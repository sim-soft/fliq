<?php

namespace Integration;

use InvalidArgumentException;
use Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for the Aggregation trait against a live database.
 *
 * Existing tests exercise count/sum/min/max/avg; the six *Distinct variants had
 * no coverage at all, and neither did the explicit-null alias every one of the
 * eleven methods accepts.
 *
 * Every test here is read-only, so no fixture restoration is required.
 *
 * Ground truth, from the `user` rows in resources/sample_db.sql:
 *   id  dept  username  role    score  status_code  deleted_at
 *   1   1     alice     admin   95     1            NULL
 *   2   1     bob       editor  82     1            NULL
 *   3   2     charlie   member  70     1            NULL
 *   4   2     diana     member  55     1            NULL
 *   5   3     eve       editor  88     1            NULL
 *   6   3     frank     member  40     1            NULL
 *   7   4     grace     member  60     1            NULL
 *   8   4     henry     member  33     0            NULL
 *   9   1     ivan      member  77     1            NULL
 *   10  5     judy      admin   91     999          2024-06-01
 */
class AggregationTest extends DatabaseTestCase
{
    /** @var int Total user rows shipped in the fixture. */
    private const USER_COUNT = 10;

    /** @var int Distinct department_id values: 1, 2, 3, 4, 5. */
    private const DISTINCT_DEPTS = 5;

    /** @var int SUM(DISTINCT department_id) = 1+2+3+4+5. */
    private const SUM_DISTINCT_DEPTS = 15;

    /** @var int SUM(department_id) = 1+1+2+2+3+3+4+4+1+5. */
    private const SUM_DEPTS = 26;

    /** @var int SUM(score) across all ten users. */
    private const SUM_SCORES = 691;

    /** @var float AVG(score) = 691 / 10. */
    private const AVG_SCORE = 69.1;

    /** @var string A username matching no row, for empty-result assertions. */
    private const NO_SUCH_USER = 'no-such-user-zzz';

    /**
     * A query that matches no rows.
     *
     * @return \Simsoft\DB\Builder\ActiveQuery
     */
    private function noRows()
    {
        return User::find()->where('username', self::NO_SUCH_USER);
    }

    // ---------------------------------------------------------------
    // countDistinct
    // ---------------------------------------------------------------

    #[Test]
    public function countDistinctCollapsesRepeatedValues(): void
    {
        // Ten rows, but only five distinct departments.
        $this->assertSame(self::DISTINCT_DEPTS, User::find()->countDistinct('department_id'));
        $this->assertSame(self::USER_COUNT, User::find()->count());
    }

    #[Test]
    public function countDistinctOnRoleCountsEachRoleOnce(): void
    {
        // admin, editor, member.
        $this->assertSame(3, User::find()->countDistinct('role'));
    }

    #[Test]
    public function countDistinctOnUniqueColumnEqualsRowCount(): void
    {
        // Every score in the fixture is unique, so DISTINCT changes nothing.
        $this->assertSame(self::USER_COUNT, User::find()->countDistinct('score'));
    }

    #[Test]
    public function countDistinctWithStarSkipsTheDistinctKeyword(): void
    {
        // COUNT(DISTINCT *) is not valid SQL, so the trait deliberately omits
        // DISTINCT for '*' and the result matches a plain count.
        $this->assertSame(self::USER_COUNT, User::find()->countDistinct('*'));
        $this->assertSame(User::find()->count(), User::find()->countDistinct('*'));
    }

    #[Test]
    public function countDistinctDefaultsToStar(): void
    {
        $this->assertSame(self::USER_COUNT, User::find()->countDistinct());
    }

    #[Test]
    public function countDistinctRespectsWhereConditions(): void
    {
        // Members sit in departments 2, 2, 3, 4, 4, 1 -> four distinct.
        $this->assertSame(4, User::find()->where('role', 'member')->countDistinct('department_id'));
    }

    #[Test]
    public function countDistinctReturnsZeroWhenNothingMatches(): void
    {
        $this->assertSame(0, $this->noRows()->countDistinct('department_id'));
    }

    // ---------------------------------------------------------------
    // sumDistinct
    // ---------------------------------------------------------------

    #[Test]
    public function sumDistinctAddsEachValueOnce(): void
    {
        $this->assertSame(self::SUM_DISTINCT_DEPTS, User::find()->sumDistinct('department_id'));
        $this->assertSame(self::SUM_DEPTS, User::find()->sum('department_id'));
    }

    #[Test]
    public function sumDistinctMatchesSumWhenValuesAreUnique(): void
    {
        $this->assertSame(self::SUM_SCORES, User::find()->sumDistinct('score'));
        $this->assertSame(self::SUM_SCORES, User::find()->sum('score'));
    }

    #[Test]
    public function sumDistinctRespectsWhereConditions(): void
    {
        // Members are in departments 2, 2, 3, 4, 4, 1 -> 1+2+3+4 = 10.
        $this->assertSame(10, User::find()->where('role', 'member')->sumDistinct('department_id'));
    }

    #[Test]
    public function sumDistinctReturnsZeroWhenNothingMatches(): void
    {
        // SUM() over an empty set is SQL NULL; the trait normalises it to 0.
        $this->assertSame(0, $this->noRows()->sumDistinct('score'));
    }

    // ---------------------------------------------------------------
    // maxDistinct / minDistinct
    // ---------------------------------------------------------------

    #[Test]
    public function maxDistinctReturnsTheLargestValue(): void
    {
        // DISTINCT cannot change an extreme, but the code path is distinct.
        $this->assertSame(95, User::find()->maxDistinct('score'));
        $this->assertSame(95, User::find()->max('score'));
    }

    #[Test]
    public function minDistinctReturnsTheSmallestValue(): void
    {
        $this->assertSame(33, User::find()->minDistinct('score'));
        $this->assertSame(33, User::find()->min('score'));
    }

    #[Test]
    public function maxDistinctOnRepeatedColumnReturnsHighestDepartment(): void
    {
        $this->assertSame(5, User::find()->maxDistinct('department_id'));
    }

    #[Test]
    public function minDistinctOnRepeatedColumnReturnsLowestDepartment(): void
    {
        $this->assertSame(1, User::find()->minDistinct('department_id'));
    }

    #[Test]
    public function maxAndMinDistinctRespectWhereConditions(): void
    {
        $members = fn() => User::find()->where('role', 'member');

        $this->assertSame(77, $members()->maxDistinct('score'));
        $this->assertSame(33, $members()->minDistinct('score'));
    }

    #[Test]
    public function maxDistinctReturnsZeroWhenNothingMatches(): void
    {
        $this->assertSame(0, $this->noRows()->maxDistinct('score'));
    }

    #[Test]
    public function minDistinctReturnsZeroWhenNothingMatches(): void
    {
        $this->assertSame(0, $this->noRows()->minDistinct('score'));
    }

    // ---------------------------------------------------------------
    // avgDistinct
    // ---------------------------------------------------------------

    #[Test]
    public function avgDistinctAveragesEachValueOnce(): void
    {
        // Departments 1..5 average to 3.0, while the row-wise average is 2.6.
        $this->assertSame(3.0, User::find()->avgDistinct('department_id'));
        $this->assertSame(2.6, User::find()->avg('department_id'));
    }

    #[Test]
    public function avgDistinctMatchesAvgWhenValuesAreUnique(): void
    {
        $this->assertSame(self::AVG_SCORE, User::find()->avgDistinct('score'));
        $this->assertSame(self::AVG_SCORE, User::find()->avg('score'));
    }

    #[Test]
    public function avgDistinctRespectsWhereConditions(): void
    {
        // Members occupy departments 1, 2, 3, 4 -> 10 / 4.
        $this->assertSame(2.5, User::find()->where('role', 'member')->avgDistinct('department_id'));
    }

    #[Test]
    public function avgDistinctReturnsZeroWhenNothingMatches(): void
    {
        $this->assertSame(0.0, $this->noRows()->avgDistinct('score'));
    }

    // ---------------------------------------------------------------
    // Explicit null alias
    // ---------------------------------------------------------------

    #[Test]
    public function aggregatesReturnTheValueWhenTheAliasIsNull(): void
    {
        // Every method advertises ?string $alias. With null, no AS clause is
        // emitted and the driver names the column after the expression, so the
        // result must still be read back rather than silently reported as 0.
        $this->assertSame(self::USER_COUNT, User::find()->count('*', null));
        $this->assertSame(self::USER_COUNT, User::find()->count('id', null));
        $this->assertSame(self::SUM_SCORES, User::find()->sum('score', null));
        $this->assertSame(95, User::find()->max('score', null));
        $this->assertSame(33, User::find()->min('score', null));
        $this->assertSame(self::AVG_SCORE, User::find()->avg('score', null));
    }

    #[Test]
    public function distinctAggregatesReturnTheValueWhenTheAliasIsNull(): void
    {
        $this->assertSame(self::DISTINCT_DEPTS, User::find()->countDistinct('department_id', null));
        $this->assertSame(self::SUM_DISTINCT_DEPTS, User::find()->sumDistinct('department_id', null));
        $this->assertSame(95, User::find()->maxDistinct('score', null));
        $this->assertSame(33, User::find()->minDistinct('score', null));
        $this->assertSame(3.0, User::find()->avgDistinct('department_id', null));
    }

    #[Test]
    public function nullAliasAgreesWithTheDefaultAlias(): void
    {
        $this->assertSame(User::find()->count(), User::find()->count('*', null));
        $this->assertSame(
            User::find()->sumDistinct('department_id'),
            User::find()->sumDistinct('department_id', null)
        );
        $this->assertSame(User::find()->avg('score'), User::find()->avg('score', null));
    }

    #[Test]
    public function nullAliasStillReturnsZeroWhenNothingMatches(): void
    {
        // The empty-set contract must survive the null-alias path: an aggregate
        // over no rows is 0, not a false from an empty result set.
        $this->assertSame(0, $this->noRows()->count('*', null));
        $this->assertSame(0, $this->noRows()->sum('score', null));
        $this->assertSame(0, $this->noRows()->maxDistinct('score', null));
        $this->assertSame(0.0, $this->noRows()->avg('score', null));
    }

    #[Test]
    public function customAliasIsHonoured(): void
    {
        $this->assertSame(self::USER_COUNT, User::find()->count('*', 'c'));
        $this->assertSame(self::SUM_DISTINCT_DEPTS, User::find()->sumDistinct('department_id', 's'));
        $this->assertSame(self::AVG_SCORE, User::find()->avg('score', 'a'));
    }

    // ---------------------------------------------------------------
    // getTotalPages
    // ---------------------------------------------------------------

    #[Test]
    public function getTotalPagesRoundsUp(): void
    {
        // Ten rows, three per page -> four pages.
        $this->assertSame(4, User::find()->getTotalPages(3));
    }

    #[Test]
    public function getTotalPagesDividesEvenly(): void
    {
        $this->assertSame(2, User::find()->getTotalPages(5));
        $this->assertSame(1, User::find()->getTotalPages(self::USER_COUNT));
    }

    #[Test]
    public function getTotalPagesWithOnePerPageEqualsRowCount(): void
    {
        $this->assertSame(self::USER_COUNT, User::find()->getTotalPages(1));
    }

    #[Test]
    public function getTotalPagesReturnsOneWhenPageSizeExceedsTotal(): void
    {
        $this->assertSame(1, User::find()->getTotalPages(1000));
    }

    #[Test]
    public function getTotalPagesReturnsZeroWhenNothingMatches(): void
    {
        $this->assertSame(0, $this->noRows()->getTotalPages(10));
    }

    #[Test]
    public function getTotalPagesRespectsWhereConditions(): void
    {
        // Six members, two per page.
        $this->assertSame(3, User::find()->where('role', 'member')->getTotalPages(2));
    }

    #[Test]
    public function getTotalPagesAcceptsACountAttribute(): void
    {
        $this->assertSame(4, User::find()->getTotalPages(3, 'department_id'));
    }

    #[Test]
    public function getTotalPagesRejectsZeroPerPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Records per page must be 1 or greater, 0 given.');

        User::find()->getTotalPages(0);
    }

    #[Test]
    public function getTotalPagesRejectsNegativePerPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Records per page must be 1 or greater, -5 given.');

        User::find()->getTotalPages(-5);
    }

    // ---------------------------------------------------------------
    // Query reuse
    // ---------------------------------------------------------------

    #[Test]
    public function aggregatingDoesNotConsumeTheQuery(): void
    {
        // avg()/avgDistinct() pass $this where the other methods pass a clone.
        // The aggregate reads the condition without mutating it, so the query
        // stays usable — assert that rather than leave it to chance.
        $query = User::find()->where('status_code', 1);

        $this->assertSame(8, $query->count());
        $this->assertSame(70.875, $query->avg('score'));
        $this->assertSame(8, $query->count());
        $this->assertCount(8, $query->get());
    }

    #[Test]
    public function repeatedAggregatesOnTheSameQueryAgree(): void
    {
        // Binds are appended to the aggregate builder, not the query, so
        // repeated calls must not accumulate placeholders.
        $query = User::find()->where('status_code', 1);

        $this->assertSame(70.875, $query->avg('score'));
        $this->assertSame(70.875, $query->avg('score'));
        $this->assertSame(70.875, $query->avgDistinct('score'));
        $this->assertSame(567, $query->sum('score'));
        $this->assertSame(567, $query->sum('score'));
    }

    #[Test]
    public function conditionsAddedAfterAggregatingStillApply(): void
    {
        $query = User::find()->where('status_code', 1);
        $this->assertSame(8, $query->count());

        $query->avg('score');
        $query->where('role', 'member');

        $this->assertSame(5, $query->count());
        $this->assertSame(
            User::find()->where('status_code', 1)->where('role', 'member')->count(),
            $query->count()
        );
    }

    #[Test]
    public function distinctAggregatesNarrowIntColumnsToInt(): void
    {
        // sum/max/min are declared int|float and normalise the driver's string
        // via "$value + 0", so an integer column must come back as an int.
        $this->assertIsInt(User::find()->sumDistinct('department_id'));
        $this->assertIsInt(User::find()->maxDistinct('score'));
        $this->assertIsInt(User::find()->minDistinct('score'));
    }
}
