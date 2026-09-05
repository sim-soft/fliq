<?php

namespace Integration;

use InvalidArgumentException;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Collection;
use Simsoft\DB\DB;

/**
 * Integration tests for the betweenDate family against a live database.
 *
 * BetweenDateCondition had no tests at all, so none of the eight public methods
 * that reach it were covered. The negated forms were returning nothing at all
 * and no test noticed.
 *
 * Results are checked against the answer the database computes for the
 * equivalent plain SQL rather than a hand-written row list: a range predicate
 * is exactly the kind of thing where a hand-copied expectation can be wrong in
 * the same direction as the code.
 *
 * Ground truth, from the `user_profile` rows in resources/sample_db.sql:
 *   user_id  date_of_birth
 *   1        1990-03-15
 *   2        1988-07-22
 *   3        1992-11-05
 *   4        1995-01-30
 *   5        1991-09-12
 *   6        1993-04-18
 *   7        1994-08-25
 *   8        1989-12-01
 *   9        1996-02-14
 *   10       1987-06-20
 *
 * Every test here is read-only, so no fixture restoration is required.
 */
class BetweenDateTest extends DatabaseTestCase
{
    /** @var string Start of the range used by most tests. */
    private const START = '1990-01-01';

    /** @var string End of the range used by most tests. */
    private const END = '1993-12-31';

    /** @var string A birthday present in the fixture, for interval tests. */
    private const ANCHOR = '1990-03-15';

    /**
     * User ids the builder returned, sorted.
     *
     * @param Collection $rows The query result.
     * @return array<int, int>
     */
    private function ids(Collection $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            // A collection yields mixed, so the model type is checked rather
            // than assumed — a query returning something else would otherwise
            // surface as a confusing property error.
            $this->assertInstanceOf(UserProfile::class, $row);
            $ids[] = $row->user_id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * User ids matching a plain SQL predicate, sorted.
     *
     * @param string $where The WHERE clause, without the keyword.
     * @return array<int, int>
     */
    private function expected(string $where): array
    {
        $ids = [];

        foreach (DB::query("SELECT user_id FROM user_profile WHERE $where") as $row) {
            $ids[] = (int)$row['user_id'];
        }

        sort($ids);

        return $ids;
    }

    // ---------------------------------------------------------------
    // Both bounds
    // ---------------------------------------------------------------

    #[Test]
    public function betweenDateMatchesSqlBetween(): void
    {
        $this->assertSame(
            $this->expected("date_of_birth BETWEEN '" . self::START . "' AND '" . self::END . "'"),
            $this->ids(UserProfile::find()->betweenDate('date_of_birth', self::START, self::END)->get())
        );
    }

    #[Test]
    public function notBetweenDateMatchesSqlNotBetween(): void
    {
        // The negated form emitted `col < start AND col > end`, which asks for a
        // date both before the range and after it. No row can satisfy that, so
        // it silently matched nothing.
        $expected = $this->expected(
            "date_of_birth NOT BETWEEN '" . self::START . "' AND '" . self::END . "'"
        );

        $this->assertNotEmpty($expected, 'The fixture must have rows outside the range.');

        $this->assertSame(
            $expected,
            $this->ids(UserProfile::find()->notBetweenDate('date_of_birth', self::START, self::END)->get())
        );
    }

    #[Test]
    public function aRangeAndItsNegationPartitionTheTable(): void
    {
        $inside = $this->ids(
            UserProfile::find()->betweenDate('date_of_birth', self::START, self::END)->get()
        );
        $outside = $this->ids(
            UserProfile::find()->notBetweenDate('date_of_birth', self::START, self::END)->get()
        );

        $this->assertSame([], array_intersect($inside, $outside));

        $all = array_merge($inside, $outside);
        sort($all);

        $this->assertSame($this->expected('1 = 1'), $all);
    }

    // ---------------------------------------------------------------
    // One bound
    // ---------------------------------------------------------------

    #[Test]
    public function startOnlyMatchesGreaterThanOrEqual(): void
    {
        $this->assertSame(
            $this->expected("date_of_birth >= '" . self::START . "'"),
            $this->ids(UserProfile::find()->betweenDate('date_of_birth', self::START)->get())
        );
    }

    #[Test]
    public function endOnlyMatchesLessThanOrEqual(): void
    {
        $this->assertSame(
            $this->expected("date_of_birth <= '" . self::END . "'"),
            $this->ids(UserProfile::find()->betweenDate('date_of_birth', null, self::END)->get())
        );
    }

    #[Test]
    public function negatedStartOnlyMatchesLessThan(): void
    {
        $this->assertSame(
            $this->expected("date_of_birth < '" . self::START . "'"),
            $this->ids(UserProfile::find()->notBetweenDate('date_of_birth', self::START)->get())
        );
    }

    #[Test]
    public function negatedEndOnlyMatchesGreaterThan(): void
    {
        $this->assertSame(
            $this->expected("date_of_birth > '" . self::END . "'"),
            $this->ids(UserProfile::find()->notBetweenDate('date_of_birth', null, self::END)->get())
        );
    }

    #[Test]
    public function boundsAreInclusive(): void
    {
        // A row sitting exactly on each bound must be inside the range.
        $this->assertSame(
            [1],
            $this->ids(UserProfile::find()->betweenDate('date_of_birth', self::ANCHOR, self::ANCHOR)->get())
        );
    }

    // ---------------------------------------------------------------
    // Neither bound
    // ---------------------------------------------------------------

    #[Test]
    public function omittingBothDatesIsRejected(): void
    {
        // This used to build an empty condition, which the builder joined with
        // its neighbours into `WHERE` on its own — a syntax error blamed on the
        // query rather than on the call that caused it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a start date, an end date, or both');

        UserProfile::find()->betweenDate('date_of_birth')->get();
    }

    #[Test]
    public function omittingBothDatesDoesNotCorruptSurroundingConditions(): void
    {
        // Worse than the standalone case: the empty condition left a dangling
        // operator, turning a valid query into `WHERE user_id = ? AND`.
        $this->expectException(InvalidArgumentException::class);

        UserProfile::find()->where('user_id', 1)->betweenDate('date_of_birth')->get();
    }

    #[Test]
    public function anEmptyStringDateIsTreatedAsAbsent(): void
    {
        // '' is falsy, so it selects the same branch as null rather than
        // comparing against an empty string.
        $this->assertSame(
            $this->expected("date_of_birth <= '" . self::END . "'"),
            $this->ids(UserProfile::find()->betweenDate('date_of_birth', '', self::END)->get())
        );
    }

    // ---------------------------------------------------------------
    // Interval
    // ---------------------------------------------------------------

    #[Test]
    public function intervalMatchesAHalfOpenWindow(): void
    {
        $this->assertSame(
            $this->expected(
                "date_of_birth >= '" . self::ANCHOR . "'"
                . " AND date_of_birth < '" . self::ANCHOR . "' + INTERVAL 7 DAY"
            ),
            $this->ids(UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 7)->get())
        );
    }

    #[Test]
    public function negatedIntervalMatchesEverythingOutsideTheWindow(): void
    {
        $expected = $this->expected(
            "NOT (date_of_birth >= '" . self::ANCHOR . "'"
            . " AND date_of_birth < '" . self::ANCHOR . "' + INTERVAL 7 DAY)"
        );

        $this->assertNotEmpty($expected, 'The fixture must have rows outside the window.');

        $this->assertSame(
            $expected,
            $this->ids(
                UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 7, false)->get()
            )
        );
    }

    #[Test]
    public function notBetweenDateIntervalMatchesEverythingOutsideTheWindow(): void
    {
        $this->assertSame(
            $this->expected(
                "NOT (date_of_birth >= '" . self::ANCHOR . "'"
                . " AND date_of_birth < '" . self::ANCHOR . "' + INTERVAL 7 DAY)"
            ),
            $this->ids(
                UserProfile::find()->notBetweenDateInterval('date_of_birth', self::ANCHOR, 7)->get()
            )
        );
    }

    #[Test]
    public function anIntervalWindowAndItsNegationPartitionTheTable(): void
    {
        $inside = $this->ids(
            UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 7)->get()
        );
        $outside = $this->ids(
            UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 7, false)->get()
        );

        $this->assertSame([], array_intersect($inside, $outside));

        $all = array_merge($inside, $outside);
        sort($all);

        $this->assertSame($this->expected('1 = 1'), $all);
    }

    #[Test]
    public function theIntervalEndIsExclusive(): void
    {
        // A 1-day window starting on a birthday holds only that day, so the
        // row on the anchor is in and the day after is not.
        $this->assertSame(
            [1],
            $this->ids(UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 1)->get())
        );
    }

    #[Test]
    public function aZeroDayIntervalMatchesNothing(): void
    {
        // `>= d AND < d` is empty by construction.
        $this->assertSame(
            [],
            $this->ids(UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 0)->get())
        );
    }

    #[Test]
    public function theIntervalDefaultsToSevenDays(): void
    {
        $this->assertSame(
            $this->ids(UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 7)->get()),
            $this->ids(UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR)->get())
        );
    }

    // ---------------------------------------------------------------
    // Combining with other conditions
    // ---------------------------------------------------------------

    #[Test]
    public function aNegatedRangeGroupsAgainstAPrecedingAnd(): void
    {
        // The negated form is a disjunction, so without parentheses a
        // surrounding AND would bind to its first half only — AND binds tighter
        // than OR — and rows failing the other condition would leak in.
        $this->assertSame(
            $this->expected(
                "user_id > 5 AND date_of_birth NOT BETWEEN '" . self::START . "' AND '" . self::END . "'"
            ),
            $this->ids(
                UserProfile::find()
                    ->where('user_id', '>', 5)
                    ->notBetweenDate('date_of_birth', self::START, self::END)
                    ->get()
            )
        );
    }

    #[Test]
    public function aNegatedRangeGroupsAgainstAFollowingAnd(): void
    {
        $this->assertSame(
            $this->expected(
                "date_of_birth NOT BETWEEN '" . self::START . "' AND '" . self::END . "' AND user_id > 5"
            ),
            $this->ids(
                UserProfile::find()
                    ->notBetweenDate('date_of_birth', self::START, self::END)
                    ->where('user_id', '>', 5)
                    ->get()
            )
        );
    }

    #[Test]
    public function aNegatedIntervalGroupsAgainstASurroundingAnd(): void
    {
        $this->assertSame(
            $this->expected(
                "user_id > 5 AND NOT (date_of_birth >= '" . self::ANCHOR . "'"
                . " AND date_of_birth < '" . self::ANCHOR . "' + INTERVAL 7 DAY)"
            ),
            $this->ids(
                UserProfile::find()
                    ->where('user_id', '>', 5)
                    ->betweenDateInterval('date_of_birth', self::ANCHOR, 7, false)
                    ->get()
            )
        );
    }

    #[Test]
    public function orBetweenDateCombinesWithOr(): void
    {
        $this->assertSame(
            $this->expected(
                "user_id = 1 OR date_of_birth BETWEEN '" . self::START . "' AND '" . self::END . "'"
            ),
            $this->ids(
                UserProfile::find()
                    ->where('user_id', 1)
                    ->orBetweenDate('date_of_birth', self::START, self::END)
                    ->get()
            )
        );
    }

    #[Test]
    public function orNotBetweenDateCombinesWithOr(): void
    {
        $this->assertSame(
            $this->expected(
                "user_id = 1 OR date_of_birth NOT BETWEEN '" . self::START . "' AND '" . self::END . "'"
            ),
            $this->ids(
                UserProfile::find()
                    ->where('user_id', 1)
                    ->orNotBetweenDate('date_of_birth', self::START, self::END)
                    ->get()
            )
        );
    }

    #[Test]
    public function orBetweenDateIntervalCombinesWithOr(): void
    {
        $this->assertSame(
            $this->expected(
                "user_id = 4 OR (date_of_birth >= '" . self::ANCHOR . "'"
                . " AND date_of_birth < '" . self::ANCHOR . "' + INTERVAL 7 DAY)"
            ),
            $this->ids(
                UserProfile::find()
                    ->where('user_id', 4)
                    ->orBetweenDateInterval('date_of_birth', self::ANCHOR, 7)
                    ->get()
            )
        );
    }

    // ---------------------------------------------------------------
    // Binding
    // ---------------------------------------------------------------

    #[Test]
    public function datesAreBoundRatherThanInterpolated(): void
    {
        $query = UserProfile::find()->betweenDate('date_of_birth', self::START, self::END);

        $this->assertStringNotContainsString(self::START, $query->getSQL());
        $this->assertSame([self::START, self::END], $query->getBinds());
    }

    #[Test]
    public function theIntervalAnchorIsBoundOnceForEachPlaceholder(): void
    {
        // The anchor appears on both sides of the window, so it is bound twice
        // — one value per placeholder, or the two would fall out of step.
        $query = UserProfile::find()->betweenDateInterval('date_of_birth', self::ANCHOR, 7);

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame(substr_count($query->getSQL(), '?'), count($binds));
        $this->assertSame([self::ANCHOR, self::ANCHOR], $binds);
    }

    #[Test]
    public function aQuoteInADateCannotEscapeItsPlaceholder(): void
    {
        // The payload arrives as one bound string, so the server reads it as a
        // (malformed) date rather than as SQL. MySQL keeps the leading
        // 1990-01-01 and warns about the rest, so the result is the ordinary
        // range — the appended OR never widens it to the whole table.
        $injected = $this->ids(
            UserProfile::find()
                ->betweenDate('date_of_birth', "1990-01-01' OR '1'='1", self::END)
                ->get()
        );

        $this->assertSame(
            $this->ids(UserProfile::find()->betweenDate('date_of_birth', '1990-01-01', self::END)->get()),
            $injected
        );
        $this->assertNotSame($this->expected('1 = 1'), $injected);
    }
}
