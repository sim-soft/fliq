<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\DB;

/**
 * Integration tests for operators whose right-hand side is not one placeholder.
 *
 * IN, NOT IN, BETWEEN, NOT BETWEEN, IS and IS NOT are all on the documented
 * operator whitelist, so where('id', 'IN', [1, 2]) looks supported and passes
 * validation. None of them built the shape the operator needs: the set and
 * range operators emitted a single placeholder and the server rejected the
 * statement, while IS and IS NOT fell into the value shorthand and became
 * `col = 'IS'` — which runs, matches nothing, and reports no error.
 *
 * That last case is why these run against a live database rather than only
 * asserting on SQL text: a string comparison would have called the broken
 * form correct, since the SQL it produced was valid. Every result below is
 * checked against the answer the database computes for the equivalent plain
 * SQL. Every test is read-only.
 */
class OperatorShapeTest extends DatabaseTestCase
{
    /**
     * The ids the given raw WHERE clause selects, sorted.
     *
     * @param string $where The WHERE clause, without the keyword.
     * @return array<int, int>
     */
    private function expected(string $where): array
    {
        $ids = [];
        foreach (DB::query("SELECT id FROM user WHERE $where") as $row) {
            $ids[] = (int)$row['id'];
        }
        sort($ids);

        return $ids;
    }

    /**
     * The ids the given query returns, sorted.
     *
     * @param iterable<mixed> $rows The query result.
     * @return array<int, int>
     */
    private function ids(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            // A collection yields mixed, so the model type is checked rather
            // than assumed — a query returning something else would otherwise
            // surface as a confusing property error.
            $this->assertInstanceOf(User::class, $row);
            $ids[] = (int)$row->id;
        }
        sort($ids);

        return $ids;
    }

    #[Test]
    public function theIsOperatorSelectsTheRowsWithANullColumn(): void
    {
        // The defect that produced no error: `deleted_at = 'IS'` is valid SQL
        // that matches nothing, so only comparing against the real answer
        // catches it.
        $expected = $this->expected('deleted_at IS NULL');
        $this->assertNotSame([], $expected, 'fixture must have rows with a null deleted_at');

        $this->assertSame($expected, $this->ids(User::find()->where('deleted_at', 'IS', null)->get()));
    }

    #[Test]
    public function theIsNotOperatorSelectsTheRowsWithAValue(): void
    {
        $expected = $this->expected('deleted_at IS NOT NULL');
        $this->assertNotSame([], $expected, 'fixture must have rows with a non-null deleted_at');

        $this->assertSame($expected, $this->ids(User::find()->where('deleted_at', 'IS NOT', null)->get()));
    }

    #[Test]
    public function theTwoNullOperatorsSelectDisjointRowSets(): void
    {
        // Both previously returned nothing, which would have looked consistent
        // had each been checked only against the other.
        $isNull = $this->ids(User::find()->where('deleted_at', 'IS', null)->get());
        $isNotNull = $this->ids(User::find()->where('deleted_at', 'IS NOT', null)->get());

        $this->assertSame([], array_intersect($isNull, $isNotNull));
        $this->assertSame($this->expected('1 = 1'), array_values(array_unique([...$isNull, ...$isNotNull])));
    }

    #[Test]
    public function theInOperatorSelectsTheListedRows(): void
    {
        $this->assertSame(
            $this->expected('id IN (1, 2, 3)'),
            $this->ids(User::find()->where('id', 'IN', [1, 2, 3])->get())
        );
    }

    #[Test]
    public function theNotInOperatorExcludesTheListedRows(): void
    {
        $this->assertSame(
            $this->expected('id NOT IN (1, 2)'),
            $this->ids(User::find()->where('id', 'NOT IN', [1, 2])->get())
        );
    }

    #[Test]
    public function theBetweenOperatorSelectsTheRange(): void
    {
        $this->assertSame(
            $this->expected('id BETWEEN 2 AND 4'),
            $this->ids(User::find()->where('id', 'BETWEEN', [2, 4])->get())
        );
    }

    #[Test]
    public function theNotBetweenOperatorExcludesTheRange(): void
    {
        $this->assertSame(
            $this->expected('id NOT BETWEEN 2 AND 4'),
            $this->ids(User::find()->where('id', 'NOT BETWEEN', [2, 4])->get())
        );
    }

    #[Test]
    public function anEmptySetInTheMapFormLeavesTheOtherConditionApplied(): void
    {
        // `IN ()` is a syntax error, so this took the whole query down.
        $this->assertSame(
            $this->expected('status_code = 1'),
            $this->ids(User::find()->where('status_code', 1)->where(['id' => []])->get())
        );
    }

    #[Test]
    public function anEmptySetInTheListFormLeavesTheOtherEntryApplied(): void
    {
        $this->assertSame(
            $this->expected("role = 'admin'"),
            $this->ids(User::find()->where([['role', '=', 'admin'], ['id', 'IN', []]])->get())
        );
    }

    #[Test]
    public function havingWithNoValueBindsNullRatherThanTheOperator(): void
    {
        // The clause bound the literal "=" in the value's place, so this ran
        // as `deleted_at = '='`. It matches nothing either way — `col = NULL`
        // is never true — but for the wrong reason, and a column whose value
        // happened to be "=" would have matched.
        $this->assertSame(
            $this->expected('deleted_at = NULL'),
            $this->ids(User::find()->groupBy('id')->having('deleted_at')->get())
        );
    }
}
