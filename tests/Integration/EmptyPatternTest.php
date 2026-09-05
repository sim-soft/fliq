<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\DB;

/**
 * Integration tests for like() called with no patterns.
 *
 * An empty terms array is an ordinary runtime state — a search box submitted
 * blank, a filter list nobody ticked — but the builder assembled the compound
 * form regardless and emitted an empty group: `WHERE ()` on its own, or a
 * dangling `AND ()` beside another condition. The server rejects both, so the
 * page died with a syntax error rather than showing unfiltered results.
 *
 * in() already skipped the condition for an empty value list, so that is the
 * behaviour adopted here: no patterns contributes nothing, and any other
 * conditions still apply on their own.
 *
 * Results are checked against the answer the database computes for the
 * equivalent plain SQL, so an expectation cannot be wrong in the same
 * direction as the code. Every test is read-only.
 */
class EmptyPatternTest extends DatabaseTestCase
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
     * Every id in the table, sorted.
     *
     * @return array<int, int>
     */
    private function everyId(): array
    {
        $ids = [];
        foreach (DB::query('SELECT id FROM user') as $row) {
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
    public function likeWithNoPatternsReturnsEveryRow(): void
    {
        // Previously a syntax error rather than a result set.
        $this->assertSame($this->everyId(), $this->ids(User::find()->like('username', [])->get()));
    }

    #[Test]
    public function notLikeWithNoPatternsReturnsEveryRow(): void
    {
        $this->assertSame($this->everyId(), $this->ids(User::find()->notLike('username', [])->get()));
    }

    #[Test]
    public function orLikeWithNoPatternsReturnsEveryRow(): void
    {
        $this->assertSame($this->everyId(), $this->ids(User::find()->orLike('username', [])->get()));
    }

    #[Test]
    public function anEarlierConditionStillAppliesOnItsOwn(): void
    {
        // The shape that produced a dangling operator: `WHERE ... = ? AND ()`.
        $this->assertSame(
            $this->expected('status_code = 1'),
            $this->ids(User::find()->where('status_code', 1)->like('username', [])->get())
        );
    }

    #[Test]
    public function aLaterConditionStillAppliesOnItsOwn(): void
    {
        $this->assertSame(
            $this->expected('status_code = 1'),
            $this->ids(User::find()->like('username', [])->where('status_code', 1)->get())
        );
    }

    #[Test]
    public function conditionsOnBothSidesAreJoinedToEachOther(): void
    {
        // With the empty clause dropped, the two survivors must still be joined
        // to one another rather than left adjacent with no operator.
        $this->assertSame(
            $this->expected('status_code = 1 AND id > 2'),
            $this->ids(
                User::find()
                    ->where('status_code', 1)
                    ->like('username', [])
                    ->where('id', '>', 2)
                    ->get()
            )
        );
    }

    #[Test]
    public function patternsStillFilterWhenGiven(): void
    {
        // The guard must not swallow a list that actually has patterns in it.
        $this->assertSame(
            $this->expected("username LIKE '%a%'"),
            $this->ids(User::find()->like('username', ['%a%'])->get())
        );
    }

    #[Test]
    public function severalPatternsStillNarrowTheResult(): void
    {
        $this->assertSame(
            $this->expected("username LIKE '%a%' AND username LIKE '%e%'"),
            $this->ids(User::find()->like('username', ['%a%', '%e%'])->get())
        );
    }
}
