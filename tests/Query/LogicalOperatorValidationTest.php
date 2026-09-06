<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;

/**
 * The logical operator joining two conditions is interpolated into the SQL,
 * not bound. Every condition method takes it as a trailing argument and none
 * of them validated it, so an arbitrary string became a bare token in the
 * WHERE clause.
 */
class LogicalOperatorValidationTest extends TestCase
{
    /** Build a query with one existing condition, so the next one joins. */
    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user')->select('id')->where('id', '=', 1);
    }

    /**
     * Condition methods taking a trailing logical operator, with valid
     * leading arguments for each.
     *
     * @return array<string, array{0: string, 1: array<int, mixed>}>
     */
    public static function conditionMethods(): array
    {
        return [
            'where' => ['where', ['role', '=', 'admin']],
            'not' => ['not', ['role', 'admin']],
            'isNull' => ['isNull', ['deleted_at']],
            'notNull' => ['notNull', ['deleted_at']],
            'in' => ['in', ['id', [1, 2]]],
            'notIn' => ['notIn', ['id', [1, 2]]],
            'regex' => ['regex', ['username', '^a']],
            'notRegex' => ['notRegex', ['username', '^a']],
            'containsWords' => ['containsWords', ['username', ['alice']]],
            'whereAny' => ['whereAny', [['id', 'score'], '=', 1]],
            'whereAll' => ['whereAll', [['id', 'score'], '=', 1]],
            'whereNone' => ['whereNone', [['id', 'score'], '=', 1]],
            'whereColumn' => ['whereColumn', ['id', '=', 'score']],
            'whereDate' => ['whereDate', ['created', '=', '2024-01-01']],
            'jsonContains' => ['jsonContains', ['meta->tags', 'core']],
            'jsonNotContains' => ['jsonNotContains', ['meta->tags', 'core']],
            'jsonHas' => ['jsonHas', ['meta->priority']],
            'jsonMissing' => ['jsonMissing', ['meta->priority']],
            'jsonLength' => ['jsonLength', ['meta->tags', '=', 2]],
            'whereJsonValue' => ['whereJsonValue', ['meta->priority', 1]],
            'whereJson' => ['whereJson', ['meta->priority', '=', 1]],
            'whereJsonLength' => ['whereJsonLength', ['meta->tags', '=', 2]],
        ];
    }

    /**
     * @param array<int, mixed> $args
     */
    #[Test]
    #[DataProvider('conditionMethods')]
    public function rejectsAnUnrecognisedLogicalOperator(string $method, array $args): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid logical operator');

        $this->query()->{$method}(...[...$args, 'BANANA']);
    }

    /**
     * @param array<int, mixed> $args
     */
    #[Test]
    #[DataProvider('conditionMethods')]
    public function acceptsAndAndOr(string $method, array $args): void
    {
        $and = (string)$this->query()->{$method}(...[...$args, 'AND']);
        $or = (string)$this->query()->{$method}(...[...$args, 'OR']);

        $this->assertStringContainsString(' AND ', $and);
        $this->assertStringContainsString(' OR ', $or);
    }

    /**
     * The comparison operator is normalised for case, so the logical one is
     * too — 'or' names the operator as plainly as 'OR'.
     *
     * @param array<int, mixed> $args
     */
    #[Test]
    #[DataProvider('conditionMethods')]
    public function normalisesCaseAndSurroundingSpace(string $method, array $args): void
    {
        $canonical = (string)$this->query()->{$method}(...[...$args, 'OR']);

        foreach (['or', ' or ', 'Or'] as $variant) {
            $this->assertSame(
                $canonical,
                (string)$this->query()->{$method}(...[...$args, $variant]),
                "'$variant' should be read as OR"
            );
        }
    }

    /**
     * The reported defect: a logical operator carrying SQL punctuation was
     * spliced in whole, commenting out the conditions that followed it.
     */
    #[Test]
    public function anOperatorCarryingSqlIsNotSplicedIntoTheWhereClause(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ActiveQuery())->from('user')->select('id')
            ->where('id', '=', 999)
            ->isNull('deleted_at', 'OR 1=1 -- ');
    }

    #[Test]
    public function namesTheOffendingOperatorAndTheAllowedOnes(): void
    {
        try {
            $this->query()->isNull('deleted_at', 'XOR');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("'XOR'", $e->getMessage());
            $this->assertStringContainsString('AND, OR', $e->getMessage());
        }
    }

    /**
     * An empty operator is not a request for the default; it is a missing
     * value, and joining on it would produce two adjacent condition fragments.
     */
    #[Test]
    public function rejectsAnEmptyOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->isNull('deleted_at', '');
    }

    /**
     * whereHas/whereDoesntHave take the operator too, and reach the same
     * join point via a different path.
     */
    #[Test]
    public function rejectsAnUnrecognisedOperatorOnRelationConditions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ActiveQuery())->from('user')->select('id')
            ->where('id', '=', 1)
            ->whereHas('posts', null, 'BANANA');
    }

    /**
     * Validation must not fire on the first condition, where no operator is
     * emitted at all — the guard runs before the "is this the first?" check.
     */
    #[Test]
    public function stillRejectsEvenWhenNoOperatorWouldBeEmitted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // No preceding condition, so nothing would be joined; the bad
        // operator is caught regardless rather than silently accepted.
        (new ActiveQuery())->from('user')->select('id')->isNull('deleted_at', 'BANANA');
    }
}
