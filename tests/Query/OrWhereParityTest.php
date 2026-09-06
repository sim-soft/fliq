<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Conditions\BetweenDateCondition;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;

/**
 * orWhere() is where() with 'OR' for the logical operator, and nothing else.
 *
 * Its signature said otherwise. It omitted the array and Clause forms and
 * narrowed the operator from mixed to ?string, so orWhere([...]) raised a
 * TypeError for a shape where() accepts, and orWhere($clause) — allowed
 * through by the callable|Raw union only because Clause is stringable —
 * stringified the clause into the attribute slot, where it was then read as a
 * null comparison. The result was SQL with two placeholders too few, which
 * failed at execute rather than at the call that was wrong.
 */
class OrWhereParityTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user')->select('id');
    }

    /** @return array<string, array{0: mixed}> */
    public static function argumentProvider(): array
    {
        return [
            'triplet list' => [[['id', '>=', 1], ['id', '<=', 3]]],
            'triplet list, one entry' => [[['role', '=', 'admin']]],
            'map' => [['id' => 1, 'score' => 0]],
            'map with IN' => [['id' => [1, 2, 3]]],
            'map with one key' => [['role' => 'admin']],
        ];
    }

    #[Test]
    #[DataProvider('argumentProvider')]
    public function orWhereBuildsEveryArrayFormWhereBuilds(mixed $argument): void
    {
        $viaOrWhere = $this->query()->where('id', '=', 9)->orWhere($argument);
        $viaWhere = $this->query()->where('id', '=', 9)->where($argument, logicalOperator: 'OR');

        $this->assertSame((string)$viaWhere, (string)$viaOrWhere);
        $this->assertSame($viaWhere->getBinds(), $viaOrWhere->getBinds());
    }

    #[Test]
    public function orWhereJoinsTheArrayFormWithOr(): void
    {
        $query = $this->query()->where('id', '=', 9)->orWhere(['role' => 'admin']);

        $this->assertStringContainsString('OR', (string)$query);
        $this->assertSame([9, 'admin'], $query->getBinds());
    }

    #[Test]
    public function orWhereAcceptsAClauseAndKeepsItsBinds(): void
    {
        $clause = fn(): Clause => new BetweenDateCondition(
            'created',
            ['2024-01-01', '2024-12-31'],
            true
        );

        $viaOrWhere = $this->query()->where('id', '=', 9)->orWhere($clause());
        $viaWhere = $this->query()->where('id', '=', 9)->where($clause(), logicalOperator: 'OR');

        $this->assertSame((string)$viaWhere, (string)$viaOrWhere);
        $this->assertSame($viaWhere->getBinds(), $viaOrWhere->getBinds());

        // The clause used to be flattened into the attribute slot and then read
        // as a null comparison, leaving one bind for three placeholders.
        $this->assertStringNotContainsString('IS NULL', (string)$viaOrWhere);

        $binds = $viaOrWhere->getBinds();
        $this->assertNotNull($binds);
        $this->assertSame(
            substr_count((string)$viaOrWhere, '?'),
            count($binds),
            'every placeholder must have a bind'
        );
    }

    #[Test]
    public function orWhereStillAcceptsTheScalarForm(): void
    {
        $query = $this->query()->where('id', '=', 9)->orWhere('role', '=', 'admin');

        $this->assertSame(
            'SELECT `user`.`id` FROM `user` WHERE `user`.`id` = ? OR `user`.`role` = ?',
            (string)$query
        );
        $this->assertSame([9, 'admin'], $query->getBinds());
    }

    #[Test]
    public function orWhereStillAcceptsTheShorthandForm(): void
    {
        // orWhere('role', 'admin') — the operator slot holding the value.
        $query = $this->query()->where('id', '=', 9)->orWhere('role', 'admin');

        $this->assertSame(
            'SELECT `user`.`id` FROM `user` WHERE `user`.`id` = ? OR `user`.`role` = ?',
            (string)$query
        );
        $this->assertSame([9, 'admin'], $query->getBinds());
    }

    #[Test]
    public function orWhereStillAcceptsAClosure(): void
    {
        $query = $this->query()
            ->where('id', '=', 9)
            ->orWhere(function (ActiveQuery $q): void {
                $q->where('role', '=', 'admin')->orWhere('role', '=', 'editor');
            });

        $this->assertStringContainsString('OR (', (string)$query);
        $this->assertSame([9, 'admin', 'editor'], $query->getBinds());
    }

    #[Test]
    public function orWhereStillAcceptsRaw(): void
    {
        $query = $this->query()->where('id', '=', 9)->orWhere(new Raw('1 = 1'));

        $this->assertStringContainsString('OR', (string)$query);
    }

    #[Test]
    public function orWhereNowTakesANonStringOperatorLikeWhereDoes(): void
    {
        // The operator was typed ?string, so a shorthand value that is not a
        // string — orWhere('score', 0) — was a TypeError on the or variant and
        // fine on the base one.
        $query = $this->query()->where('id', '=', 9)->orWhere('score', 0);

        $this->assertSame([9, 0], $query->getBinds());
    }

    #[Test]
    public function everyOrVariantAcceptsWhatItsBaseMethodAccepts(): void
    {
        // where/orWhere was the only mismatched pair of the 32. This holds the
        // line for the rest of them.
        $pairs = [
            'where' => 'orWhere',
            'isNull' => 'orIsNull',
            'notNull' => 'orNotNull',
            'in' => 'orIn',
            'notIn' => 'orNotIn',
            'between' => 'orBetween',
            'notBetween' => 'orNotBetween',
            'betweenDate' => 'orBetweenDate',
            'betweenDateInterval' => 'orBetweenDateInterval',
            'regex' => 'orRegex',
            'notRegex' => 'orNotRegex',
            'containsWords' => 'orContainsWords',
            'whereRaw' => 'orWhereRaw',
            'exists' => 'orExists',
            'notExists' => 'orNotExists',
            'whereAny' => 'orWhereAny',
            'whereAll' => 'orWhereAll',
            'whereNone' => 'orWhereNone',
            'whereColumn' => 'orWhereColumn',
            'whereLike' => 'orWhereLike',
            'whereNotLike' => 'orWhereNotLike',
            'whereDate' => 'orWhereDate',
            'whereMonth' => 'orWhereMonth',
            'whereYear' => 'orWhereYear',
            'whereTime' => 'orWhereTime',
            'whereFulltext' => 'orWhereFulltext',
            'jsonContains' => 'orJsonContains',
            'whereJsonLength' => 'orWhereJsonLength',
            'arrayContains' => 'orArrayContains',
            'arrayOverlaps' => 'orArrayOverlaps',
            'not' => 'orNot',
            'whereNot' => 'orWhereNot',
        ];

        foreach ($pairs as $base => $or) {
            $this->assertSame(
                $this->parameterTypes($base),
                $this->parameterTypes($or),
                "$or must accept everything $base accepts"
            );
        }
    }

    /**
     * Parameter types of a query method, less the trailing logical operator.
     *
     * @param string $method The method name.
     * @return array<string, string> Parameter name => declared type.
     */
    private function parameterTypes(string $method): array
    {
        $reflection = new \ReflectionMethod(ActiveQuery::class, $method);
        $types = [];

        foreach ($reflection->getParameters() as $parameter) {
            if (in_array($parameter->getName(), ['logicalOperator', 'is'], true)) {
                continue;
            }
            $types[$parameter->getName()] = (string)$parameter->getType();
        }

        return $types;
    }
}
