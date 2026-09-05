<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Aggregations\Count;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\DB;

/**
 * Integration tests for grouping, HAVING and bind ordering.
 *
 * Two of the defects covered here produced SQL the server accepts and runs.
 * A Raw attribute lost the operator and value given beside it, so
 * where(Raw('score'), '>', 90) ran as a bare `WHERE score` — a truthiness test
 * returning every non-zero row. And bind values were collected in the order
 * the builder methods were called rather than the order their placeholders are
 * emitted, so a query built with groupByRaw() before its where() handed the
 * two values over swapped. Neither raised an error or wrote a log line; both
 * simply returned the wrong rows.
 *
 * A test comparing generated SQL against an expected string would have called
 * both correct, since what they produced was valid. Every result below is
 * checked against the answer the database computes for the equivalent plain
 * SQL instead. Every test is read-only.
 */
class GroupingShapeTest extends DatabaseTestCase
{
    /**
     * The single column the given raw query selects, in order.
     *
     * @param string $sql The complete SQL statement.
     * @return array<int, string> The values, as strings.
     */
    private function truth(string $sql): array
    {
        $values = [];
        foreach (DB::query($sql) as $row) {
            $values[] = (string)reset($row);
        }

        return $values;
    }

    /**
     * The single column the given query returns, in order.
     *
     * @param ActiveQuery $query The query to run.
     * @return array<int, string> The values, as strings.
     */
    private function values(ActiveQuery $query): array
    {
        $values = [];
        foreach ($query->all() as $row) {
            $values[] = (string)reset($row);
        }

        return $values;
    }

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user u');
    }

    #[Test]
    public function twoHavingConditionsBothApply(): void
    {
        // `HAVING a, b` is a syntax error, so a second having() call took the
        // whole query down rather than narrowing it.
        $query = $this->query()->select('role')->groupBy('role')
            ->having(new Raw('COUNT(*)'), '>', 1)
            ->having(new Raw('MAX(score)'), '>', 80);

        $this->assertSame(
            $this->truth('SELECT role FROM user GROUP BY role HAVING COUNT(*) > 1 AND MAX(score) > 80'),
            $this->values($query)
        );
    }

    #[Test]
    public function orHavingWidensTheGroupsKept(): void
    {
        $query = $this->query()->select('role')->groupBy('role')
            ->having(new Raw('COUNT(*)'), '>', 5)
            ->orHaving(new Raw('MAX(score)'), '>', 90);

        $this->assertSame(
            $this->truth('SELECT role FROM user GROUP BY role HAVING COUNT(*) > 5 OR MAX(score) > 90'),
            $this->values($query)
        );
    }

    #[Test]
    public function aRawAttributeIsComparedRatherThanTestedForTruth(): void
    {
        // The defect that produced no error: `WHERE score` is valid SQL that
        // keeps every non-zero row, so it returned all ten users where the
        // comparison asked for returns two.
        $query = $this->query()->select('id')->where(new Raw('score'), '>', 90);

        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE score > 90'),
            $this->values($query)
        );
    }

    #[Test]
    public function aRawHavingAttributeIsComparedRatherThanTestedForTruth(): void
    {
        $query = $this->query()->select('role')->groupBy('role')
            ->having(new Raw('COUNT(*)'), '>', 2);

        $this->assertSame(
            $this->truth('SELECT role FROM user GROUP BY role HAVING COUNT(*) > 2'),
            $this->values($query)
        );
    }

    #[Test]
    public function aGroupByValueBoundBeforeItsWhereStillLandsInGroupBy(): void
    {
        // The other defect that produced no error: the two values were handed
        // over swapped, so the query filtered on status_code = 50 and grouped
        // on score > 1. Both are valid, and the row count merely came out wrong.
        $query = $this->query()->select(new Raw('COUNT(*) AS n'))
            ->groupByRaw('CASE WHEN score > ? THEN 1 ELSE 0 END', [50])
            ->where('status_code', '=', 1);

        $this->assertSame(
            $this->truth(
                'SELECT COUNT(*) AS n FROM user WHERE status_code = 1 '
                . 'GROUP BY CASE WHEN score > 50 THEN 1 ELSE 0 END'
            ),
            $this->values($query)
        );
    }

    #[Test]
    public function aHavingValueBoundBeforeItsWhereStillLandsInHaving(): void
    {
        $query = $this->query()->select('role')
            ->havingRaw('COUNT(*) > ?', [1])
            ->groupBy('role')
            ->where('status_code', '=', 1);

        $this->assertSame(
            $this->truth('SELECT role FROM user WHERE status_code = 1 GROUP BY role HAVING COUNT(*) > 1'),
            $this->values($query)
        );
    }

    #[Test]
    public function aRawSelectExpressionRunsWithItsOwnBinds(): void
    {
        // Its binds were dropped, leaving the statement one value short of its
        // placeholders, so the driver refused to execute it at all.
        $query = $this->query()
            ->select(new Raw('IF(score > ?, 1, 0) AS high', [50]))
            ->where('status_code', '=', 1);

        $this->assertSame(
            $this->truth('SELECT IF(score > 50, 1, 0) AS high FROM user WHERE status_code = 1'),
            $this->values($query)
        );
    }

    #[Test]
    public function aUnionBranchesValuesFollowTheOuterQuerysOwn(): void
    {
        $query = $this->query()->select('id')
            ->union((new ActiveQuery())->from('user')->select('id')->where('score', '>', 90))
            ->where('score', '<', 40);

        $this->assertSame(
            $this->truth('(SELECT id FROM user WHERE score < 40) UNION (SELECT id FROM user WHERE score > 90)'),
            $this->values($query)
        );
    }

    #[Test]
    public function aJoinedSubQueryRunsWithItsBindsInPlace(): void
    {
        // The sub-query was quoted as a single identifier and its bind dropped,
        // so no shape of this call could run: the server rejected the statement
        // as an over-long identifier name.
        $sub = (new ActiveQuery())->from('post')->select('user_id')->where('view_count', '>', 100);
        $query = $this->query()->select('id')
            ->join(['p' => $sub], ['user_id' => 'id'])
            ->where('score', '>', 50);

        $this->assertSame(
            $this->truth(
                'SELECT u.id FROM user u '
                . 'INNER JOIN (SELECT user_id FROM post WHERE view_count > 100) AS p ON p.user_id = u.id '
                . 'WHERE u.score > 50'
            ),
            $this->values($query)
        );
    }

    #[Test]
    public function aMergedQuerysHavingValueStaysInHaving(): void
    {
        $other = $this->query()->havingRaw('COUNT(*) > ?', [1])->where('role', '=', 'member');
        $query = $this->query()->select('role')
            ->where('status_code', '=', 1)
            ->groupBy('role')
            ->merge($other);

        $this->assertSame(
            $this->truth(
                "SELECT role FROM user WHERE status_code = 1 AND role = 'member' "
                . 'GROUP BY role HAVING COUNT(*) > 1'
            ),
            $this->values($query)
        );
    }

    #[Test]
    public function anAggregateTakesOnlyTheBindsForTheSectionsItReEmits(): void
    {
        // The aggregate re-emits the source query's JOIN, WHERE, GROUP BY and
        // HAVING but writes its own SELECT. Handing it every bind gave it the
        // SELECT value too, whose placeholder is not in the statement, and the
        // driver refused it as over-supplied.
        $source = $this->query()
            ->select(new Raw('IF(score > ?, 1, 0)', [50]))
            ->where('status_code', '=', 1)
            ->groupBy('role')
            ->havingRaw('COUNT(*) > ?', [1]);

        $count = new Count('user', '*');
        $count->condition($source);

        $this->assertSame('2', (string)$count->queryScalar());
    }

    #[Test]
    public function havingWithTheIsOperatorChecksForNull(): void
    {
        // having() had no IS routing, so its value shorthand took the operator
        // as the value and ran `HAVING col = 'IS'` — matching nothing, quietly.
        $query = $this->query()->select('id')->groupBy('id', 'deleted_at')
            ->having('deleted_at', 'IS', null);

        $this->assertSame(
            $this->truth('SELECT id FROM user GROUP BY id, deleted_at HAVING deleted_at IS NULL'),
            $this->values($query)
        );
    }
}
