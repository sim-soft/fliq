<?php

namespace Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\DB;

/**
 * The logical operator as an injection sink, against the live database.
 *
 * The operator joining two conditions is interpolated, not bound. Passing
 * "OR 1=1 -- " to any of some twenty condition methods put that text into the
 * WHERE clause verbatim: the OR made the preceding condition irrelevant and
 * the comment marker removed everything after it, so a query restricted to one
 * row returned the whole table. These tests count rows rather than compare SQL,
 * so they measure what the server did with the statement.
 */
class LogicalOperatorValidationTest extends DatabaseTestCase
{
    /** Count the rows a built query returns. */
    private function rowCount(ActiveQuery $query): int
    {
        $n = 0;

        foreach ($query->all() as $row) {
            $n++;
        }

        return $n;
    }

    private function users(): ActiveQuery
    {
        return (new ActiveQuery())->withConnection('mysql')->from('user')->select('id');
    }

    /**
     * The size of the table the injection leaked, so the numbers below mean
     * something.
     */
    #[Test]
    public function theUserTableHasTenRows(): void
    {
        $rows = DB::query('SELECT COUNT(*) c FROM user', [], 'mysql');

        $this->assertSame(10, (int)$rows[0]['c']);
    }

    /**
     * The reported case: a query narrowed to a non-existent id returned every
     * row in the table.
     */
    #[Test]
    public function anOperatorCarryingSqlCannotLeakTheTable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->users()->where('id', '=', 999)->isNull('deleted_at', 'OR 1=1 -- ');
    }

    /**
     * The same query with a legitimate operator still restricts properly —
     * id 999 does not exist, so nothing comes back.
     */
    #[Test]
    public function theEquivalentLegitimateQueryReturnsNothing(): void
    {
        $this->assertSame(
            0,
            $this->rowCount($this->users()->where('id', '=', 999)->isNull('deleted_at', 'AND'))
        );
    }

    /**
     * AND and OR both still reach the server and mean what they say. Nine of
     * the ten fixture users have deleted_at NULL and one has id 1, so AND
     * gives 1 and OR gives 9.
     */
    #[Test]
    public function andStillNarrowsAndOrStillWidens(): void
    {
        $and = $this->rowCount($this->users()->where('id', '=', 1)->isNull('deleted_at', 'AND'));
        $or = $this->rowCount($this->users()->where('id', '=', 1)->isNull('deleted_at', 'OR'));

        $nulls = DB::query('SELECT COUNT(*) c FROM user WHERE deleted_at IS NULL', [], 'mysql');

        $this->assertSame(1, $and);
        $this->assertSame((int)$nulls[0]['c'], $or);
        $this->assertGreaterThan($and, $or);
    }

    /**
     * A lowercase operator is normalised rather than rejected, and produces
     * the same rows as its uppercase form.
     */
    #[Test]
    public function lowercaseOperatorsRunAndAgree(): void
    {
        $this->assertSame(
            $this->rowCount($this->users()->where('id', '=', 1)->isNull('deleted_at', 'OR')),
            $this->rowCount($this->users()->where('id', '=', 1)->isNull('deleted_at', 'or'))
        );
    }

    /**
     * A sweep across the condition methods that take the operator, each with
     * a payload that would have leaked the table. None may build.
     */
    #[Test]
    public function noConditionMethodAcceptsAnInjectedOperator(): void
    {
        $methods = [
            'where' => ['role', '=', 'admin'],
            'not' => ['role', 'admin'],
            'isNull' => ['deleted_at'],
            'notNull' => ['deleted_at'],
            'in' => ['id', [1, 2]],
            'notIn' => ['id', [1, 2]],
            'regex' => ['username', '^a'],
            'notRegex' => ['username', '^a'],
            'containsWords' => ['username', ['alice']],
            'whereAny' => [['id', 'score'], '=', 1],
            'whereAll' => [['id', 'score'], '=', 1],
            'whereNone' => [['id', 'score'], '=', 1],
            'whereColumn' => ['id', '=', 'score'],
            'whereDate' => ['created', '=', '2024-01-01'],
        ];

        $accepted = [];

        foreach ($methods as $method => $args) {
            try {
                $query = $this->users()->where('id', '=', 999)->{$method}(...[...$args, 'OR 1=1 -- ']);

                // If it built, find out whether the server honoured it.
                $accepted[] = $method . ' (' . $this->rowCount($query) . ' rows)';
            } catch (InvalidArgumentException) {
                // Refused, as it should be.
            }
        }

        $this->assertSame([], $accepted, 'these methods still accept an injected operator');
    }

    /**
     * The JSON condition methods reach the same join point by a different
     * route, so they are swept separately against a JSON column.
     */
    #[Test]
    public function noJsonConditionMethodAcceptsAnInjectedOperator(): void
    {
        $evil = 'OR 1=1 -- ';

        // Each closure takes the payload and makes the one call under test, so
        // the arguments keep the types the signatures declare.
        $methods = [
            'jsonContains' => fn(ActiveQuery $q, string $op) => $q->jsonContains('metadata->tags', 'core', $op),
            'jsonNotContains' => fn(ActiveQuery $q, string $op) => $q->jsonNotContains('metadata->tags', 'core', $op),
            'jsonHas' => fn(ActiveQuery $q, string $op) => $q->jsonHas('metadata->priority', $op),
            'jsonMissing' => fn(ActiveQuery $q, string $op) => $q->jsonMissing('metadata->priority', $op),
            'jsonLength' => fn(ActiveQuery $q, string $op) => $q->jsonLength('metadata->tags', '=', 2, $op),
            'whereJsonValue' => fn(ActiveQuery $q, string $op) => $q->whereJsonValue('metadata->priority', 1, $op),
            'whereJson' => fn(ActiveQuery $q, string $op) => $q->whereJson('metadata->priority', '=', 1, $op),
            'whereJsonLength' => fn(ActiveQuery $q, string $op) => $q->whereJsonLength('metadata->tags', '=', 2, $op),
        ];

        $accepted = [];

        foreach ($methods as $method => $call) {
            $settings = (new ActiveQuery())->withConnection('mysql')->from('setting')->select('id');

            try {
                $query = $call($settings->where('id', '=', 999), $evil);
                $accepted[] = $method . ' (' . $this->rowCount($query) . ' rows)';
            } catch (InvalidArgumentException) {
                // Refused.
            }
        }

        $this->assertSame([], $accepted, 'these JSON methods still accept an injected operator');
    }
}
