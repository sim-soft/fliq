<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * The thin forwarding layer must forward what it says it forwards.
 *
 * `ActiveQuery` exposes each condition three or four ways: a short name
 * (`in`), a where-prefixed alias (`whereIn`), and an OR variant of each
 * (`orIn`, `orWhereIn`). Every one is a one-line call through to a common
 * implementation, which is exactly why none of them were tested — and why a
 * single wrong argument in any of them would change a query's meaning without
 * changing anything a reader would look at.
 *
 * Two claims are checked, both from the generated SQL:
 *
 *  - an `or*` method joins with OR, and its non-OR counterpart joins with AND;
 *  - an alias produces byte-identical SQL and binds to the method it aliases.
 */
class ForwarderParityTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
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
        return (new ActiveQuery())->from('user')->on('mysql')->select('id');
    }

    /**
     * The condition the given callback adds, on its own, with no leading one.
     *
     * @param callable(ActiveQuery): mixed $add
     */
    private function soleCondition(callable $add): string
    {
        $query = $this->query();
        $add($query);
        $sql = $query->getSQL();
        $at = strpos($sql, 'WHERE ');

        return $at === false ? '' : substr($sql, $at + 6);
    }

    /**
     * How the given callback's condition joins to one already present.
     *
     * Returns 'AND' or 'OR' — the operator immediately after the leading
     * `id = ?`, which is what the forwarder controls.
     *
     * @param callable(ActiveQuery): mixed $add
     */
    private function joiner(callable $add): string
    {
        $query = $this->query()->where('id', '=', 1);
        $add($query);
        $sql = $query->getSQL();
        $tail = substr($sql, (int)strpos($sql, 'WHERE '));

        // Everything up to the first condition is fixed; what follows it is
        // the joining operator. Matching on the leading condition avoids
        // seeing an OR that belongs inside a multi-column group.
        $marker = 'WHERE `user`.`id` = ? ';

        if (!str_starts_with($tail, $marker)) {
            return "unrecognised: $tail";
        }

        $rest = substr($tail, strlen($marker));

        return str_starts_with($rest, 'OR ') ? 'OR' : (str_starts_with($rest, 'AND ') ? 'AND' : "unrecognised: $rest");
    }

    /**
     * Every OR-joining method, by name.
     *
     * @return array<string, array{0: callable(ActiveQuery): mixed}>
     */
    public static function orMethods(): array
    {
        return [
            'orNot' => [fn(ActiveQuery $q) => $q->orNot('score', 50)],
            'orWhereNot' => [fn(ActiveQuery $q) => $q->orWhereNot('score', 50)],
            'orWhereNull' => [fn(ActiveQuery $q) => $q->orWhereNull('deleted_at')],
            'orIsNull' => [fn(ActiveQuery $q) => $q->orIsNull('deleted_at')],
            'orWhereNotNull' => [fn(ActiveQuery $q) => $q->orWhereNotNull('deleted_at')],
            'orNotNull' => [fn(ActiveQuery $q) => $q->orNotNull('deleted_at')],
            'orWhereAny' => [fn(ActiveQuery $q) => $q->orWhereAny(['username', 'email'], '=', 'x')],
            'orWhereAll' => [fn(ActiveQuery $q) => $q->orWhereAll(['username', 'email'], '!=', 'x')],
            'orWhereNone' => [fn(ActiveQuery $q) => $q->orWhereNone(['username', 'email'], '=', 'x')],
            'orIn' => [fn(ActiveQuery $q) => $q->orIn('id', [2, 3])],
            'orWhereIn' => [fn(ActiveQuery $q) => $q->orWhereIn('id', [2, 3])],
            'orNotIn' => [fn(ActiveQuery $q) => $q->orNotIn('id', [2, 3])],
            'orWhereNotIn' => [fn(ActiveQuery $q) => $q->orWhereNotIn('id', [2, 3])],
            'orBetween' => [fn(ActiveQuery $q) => $q->orBetween('score', 10, 20)],
            'orNotBetween' => [fn(ActiveQuery $q) => $q->orNotBetween('score', 10, 20)],
            'orRegex' => [fn(ActiveQuery $q) => $q->orRegex('username', '^a')],
            'orNotRegex' => [fn(ActiveQuery $q) => $q->orNotRegex('username', '^a')],
            'orContainsWords' => [fn(ActiveQuery $q) => $q->orContainsWords('username', 'alice')],
            'orWhereRaw' => [fn(ActiveQuery $q) => $q->orWhereRaw('{score} > 90')],
            'orWhereJsonValue' => [fn(ActiveQuery $q) => $q->orWhereJsonValue('meta->status', 'x')],
            'orJsonValue' => [fn(ActiveQuery $q) => $q->orJsonValue('meta->status', 'x')],
            'orJsonContains' => [fn(ActiveQuery $q) => $q->orJsonContains('meta->tags', 'x')],
            'orJsonNotContains' => [fn(ActiveQuery $q) => $q->orJsonNotContains('meta->tags', 'x')],
            'orJsonHas' => [fn(ActiveQuery $q) => $q->orJsonHas('meta->status')],
            'orJsonMissing' => [fn(ActiveQuery $q) => $q->orJsonMissing('meta->status')],
            'orWhereJsonLength' => [fn(ActiveQuery $q) => $q->orWhereJsonLength('meta->tags', '=', 1)],
            'orJsonLength' => [fn(ActiveQuery $q) => $q->orJsonLength('meta->tags', '=', 1)],
            'orWhereJsonDoesntContain' => [fn(ActiveQuery $q) => $q->orWhereJsonDoesntContain('meta->tags', 'x')],
            'orWhereJsonContainsKey' => [fn(ActiveQuery $q) => $q->orWhereJsonContainsKey('meta->status')],
            'orWhereJsonDoesntContainKey' => [fn(ActiveQuery $q) => $q->orWhereJsonDoesntContainKey('meta->status')],
        ];
    }

    /**
     * @param callable(ActiveQuery): mixed $add
     */
    #[Test]
    #[DataProvider('orMethods')]
    public function orMethodsJoinWithOr(callable $add): void
    {
        $this->assertSame('OR', $this->joiner($add));
    }

    /**
     * Every method that must join with AND by default.
     *
     * @return array<string, array{0: callable(ActiveQuery): mixed}>
     */
    public static function andMethods(): array
    {
        return [
            'not' => [fn(ActiveQuery $q) => $q->not('score', 50)],
            'whereNot' => [fn(ActiveQuery $q) => $q->whereNot('score', 50)],
            'whereNull' => [fn(ActiveQuery $q) => $q->whereNull('deleted_at')],
            'whereNotNull' => [fn(ActiveQuery $q) => $q->whereNotNull('deleted_at')],
            'whereIn' => [fn(ActiveQuery $q) => $q->whereIn('id', [2, 3])],
            'whereNotIn' => [fn(ActiveQuery $q) => $q->whereNotIn('id', [2, 3])],
            'whereAny' => [fn(ActiveQuery $q) => $q->whereAny(['username', 'email'], '=', 'x')],
            'whereAll' => [fn(ActiveQuery $q) => $q->whereAll(['username', 'email'], '!=', 'x')],
            'whereNone' => [fn(ActiveQuery $q) => $q->whereNone(['username', 'email'], '=', 'x')],
            'notBetween' => [fn(ActiveQuery $q) => $q->notBetween('score', 10, 20)],
            'jsonValue' => [fn(ActiveQuery $q) => $q->jsonValue('meta->status', 'x')],
            'whereJsonValue' => [fn(ActiveQuery $q) => $q->whereJsonValue('meta->status', 'x')],
        ];
    }

    /**
     * @param callable(ActiveQuery): mixed $add
     */
    #[Test]
    #[DataProvider('andMethods')]
    public function nonOrMethodsJoinWithAnd(callable $add): void
    {
        // whereAny/whereNone contain an OR *inside* their bracketed group; the
        // operator joining the group to what precedes it must still be AND.
        $this->assertSame('AND', $this->joiner($add));
    }

    /**
     * Pairs that must be indistinguishable in the emitted query.
     *
     * @return array<string, array{0: callable(ActiveQuery): mixed, 1: callable(ActiveQuery): mixed}>
     */
    public static function aliasPairs(): array
    {
        return [
            'whereNot == not' => [
                fn(ActiveQuery $q) => $q->whereNot('score', 5),
                fn(ActiveQuery $q) => $q->not('score', 5),
            ],
            'whereNull == isNull' => [
                fn(ActiveQuery $q) => $q->whereNull('deleted_at'),
                fn(ActiveQuery $q) => $q->isNull('deleted_at'),
            ],
            'whereNotNull == notNull' => [
                fn(ActiveQuery $q) => $q->whereNotNull('deleted_at'),
                fn(ActiveQuery $q) => $q->notNull('deleted_at'),
            ],
            'whereIn == in' => [
                fn(ActiveQuery $q) => $q->whereIn('id', [1, 2]),
                fn(ActiveQuery $q) => $q->in('id', [1, 2]),
            ],
            'whereNotIn == notIn' => [
                fn(ActiveQuery $q) => $q->whereNotIn('id', [1, 2]),
                fn(ActiveQuery $q) => $q->notIn('id', [1, 2]),
            ],
            'orWhereNot == orNot' => [
                fn(ActiveQuery $q) => $q->orWhereNot('score', 5),
                fn(ActiveQuery $q) => $q->orNot('score', 5),
            ],
            'orWhereNull == orIsNull' => [
                fn(ActiveQuery $q) => $q->orWhereNull('deleted_at'),
                fn(ActiveQuery $q) => $q->orIsNull('deleted_at'),
            ],
            'orWhereNotNull == orNotNull' => [
                fn(ActiveQuery $q) => $q->orWhereNotNull('deleted_at'),
                fn(ActiveQuery $q) => $q->orNotNull('deleted_at'),
            ],
            'orWhereIn == orIn' => [
                fn(ActiveQuery $q) => $q->orWhereIn('id', [1, 2]),
                fn(ActiveQuery $q) => $q->orIn('id', [1, 2]),
            ],
            'orWhereNotIn == orNotIn' => [
                fn(ActiveQuery $q) => $q->orWhereNotIn('id', [1, 2]),
                fn(ActiveQuery $q) => $q->orNotIn('id', [1, 2]),
            ],
            'whereJsonValue == jsonValue' => [
                fn(ActiveQuery $q) => $q->whereJsonValue('meta->status', 'x'),
                fn(ActiveQuery $q) => $q->jsonValue('meta->status', 'x'),
            ],
            'orWhereJsonValue == orJsonValue' => [
                fn(ActiveQuery $q) => $q->orWhereJsonValue('meta->status', 'x'),
                fn(ActiveQuery $q) => $q->orJsonValue('meta->status', 'x'),
            ],
            'orWhereJsonDoesntContain == orJsonNotContains' => [
                fn(ActiveQuery $q) => $q->orWhereJsonDoesntContain('meta->tags', 'x'),
                fn(ActiveQuery $q) => $q->orJsonNotContains('meta->tags', 'x'),
            ],
            'orWhereJsonContainsKey == orJsonHas' => [
                fn(ActiveQuery $q) => $q->orWhereJsonContainsKey('meta->status'),
                fn(ActiveQuery $q) => $q->orJsonHas('meta->status'),
            ],
            'orWhereJsonDoesntContainKey == orJsonMissing' => [
                fn(ActiveQuery $q) => $q->orWhereJsonDoesntContainKey('meta->status'),
                fn(ActiveQuery $q) => $q->orJsonMissing('meta->status'),
            ],
            'orWhereJsonLength == orJsonLength' => [
                fn(ActiveQuery $q) => $q->orWhereJsonLength('meta->tags', '=', 1),
                fn(ActiveQuery $q) => $q->orJsonLength('meta->tags', '=', 1),
            ],
        ];
    }

    /**
     * @param callable(ActiveQuery): mixed $alias
     * @param callable(ActiveQuery): mixed $target
     */
    #[Test]
    #[DataProvider('aliasPairs')]
    public function aliasesMatchWhatTheyForwardTo(callable $alias, callable $target): void
    {
        $aliasQuery = $this->query();
        $alias($aliasQuery);

        $targetQuery = $this->query();
        $target($targetQuery);

        $this->assertSame($targetQuery->getSQL(), $aliasQuery->getSQL());
        $this->assertEquals($targetQuery->getBinds(), $aliasQuery->getBinds());
    }

    #[Test]
    public function whereAnyGroupsItsColumnsWithOr(): void
    {
        // The inner OR is the point of whereAny; it is not the joining operator.
        $this->assertSame(
            '(`user`.`username` = ? OR `user`.`email` = ?)',
            $this->soleCondition(fn(ActiveQuery $q) => $q->whereAny(['username', 'email'], '=', 'x'))
        );
    }

    #[Test]
    public function whereAllGroupsItsColumnsWithAnd(): void
    {
        $this->assertSame(
            '(`user`.`username` != ? AND `user`.`email` != ?)',
            $this->soleCondition(fn(ActiveQuery $q) => $q->whereAll(['username', 'email'], '!=', 'x'))
        );
    }

    #[Test]
    public function whereNoneNegatesTheGroup(): void
    {
        $this->assertSame(
            'NOT (`user`.`username` = ? OR `user`.`email` = ?)',
            $this->soleCondition(fn(ActiveQuery $q) => $q->whereNone(['username', 'email'], '=', 'x'))
        );
    }
}
