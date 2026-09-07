<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;

/**
 * IN and NOT IN, answered by a server.
 *
 * The unit tests hold the statement still; these compare the rows against the
 * same question written by hand in SQL. Both defects here were the kind that
 * only a server can settle: an empty list silently returned the whole table
 * instead of nothing, and a subquery with nothing to bind could not execute at
 * all. Every assertion below is checked against a hand-written statement rather
 * than against an expected list, so the fixture is free to change.
 *
 * Read-only — nothing here writes, so there is nothing to restore.
 */
class SetMembershipTest extends DatabaseTestCase
{
    /**
     * Run a hand-written statement and return its id column, sorted.
     *
     * @param string $sql The statement.
     * @param array<int, mixed> $binds Its binds.
     * @return array<int, int>
     */
    private function truth(string $sql, array $binds = []): array
    {
        $ids = array_map(
            static fn(array $row): int => (int)$row['id'],
            (new Raw($sql, $binds))->fetchAll()
        );
        sort($ids);
        return $ids;
    }

    /**
     * Run a builder query and return its id column, sorted.
     *
     * @param ActiveQuery $query The query.
     * @return array<int, int>
     */
    private function idsOf(ActiveQuery $query): array
    {
        $ids = array_map(
            static fn(array $row): int => (int)$row['id'],
            iterator_to_array($query->getArray())
        );
        sort($ids);
        return $ids;
    }

    /**
     * A fresh query over the user table.
     *
     * @return ActiveQuery
     */
    private function users(): ActiveQuery
    {
        return (new ActiveQuery())->from('user')->select('id');
    }

    #[Test]
    public function inListMatchesTheSameRowsAsHandWrittenSql(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id IN (1, 2, 3)'),
            $this->idsOf($this->users()->in('id', [1, 2, 3]))
        );
    }

    #[Test]
    public function notInListMatchesTheSameRowsAsHandWrittenSql(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id NOT IN (1, 2, 3)'),
            $this->idsOf($this->users()->notIn('id', [1, 2, 3]))
        );
    }

    /**
     * The empty list, which is where the wrong answer was.
     *
     * Nothing is a member of a set with nothing in it, so this must match no
     * rows. It used to drop the condition and return all of them — an empty
     * allow-list handing back the entire table.
     */
    #[Test]
    public function emptyInMatchesNoRows(): void
    {
        $this->assertSame([], $this->idsOf($this->users()->in('id', [])));
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE 1 = 0'),
            $this->idsOf($this->users()->in('id', []))
        );
    }

    #[Test]
    public function emptyNotInMatchesEveryRow(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user'),
            $this->idsOf($this->users()->notIn('id', []))
        );
    }

    /**
     * Beside an AND the empty set still has to narrow.
     */
    #[Test]
    public function emptyInNarrowsBesideAnotherCondition(): void
    {
        $this->assertSame([], $this->idsOf($this->users()->where('id', 1)->in('id', [])));

        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id = 1'),
            $this->idsOf($this->users()->where('id', 1)->notIn('id', []))
        );
    }

    /**
     * Beside an OR it has to widen, which skipping could not do.
     *
     * `orNotIn('id', [])` is constant-true, so the whole clause is true for
     * every row. Dropped, it left the preceding condition to answer alone and
     * returned one row where every row was correct.
     */
    #[Test]
    public function emptySetDecidesTheAnswerBesideOr(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id = 1 OR 1 = 1'),
            $this->idsOf($this->users()->where('id', 1)->orNotIn('id', []))
        );

        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id = 1 OR 1 = 0'),
            $this->idsOf($this->users()->where('id', 1)->orIn('id', []))
        );
    }

    #[Test]
    public function inSubqueryMatchesTheSameRowsAsHandWrittenSql(): void
    {
        $sub = (new ActiveQuery())->from('post')->select('user_id')->where('view_count', '>', 100);

        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id IN (SELECT user_id FROM post WHERE view_count > ?)', [100]),
            $this->idsOf($this->users()->in('id', $sub))
        );
    }

    /**
     * A subquery with nothing to bind, which used to be unable to run.
     *
     * "No binds" was reported as null and then bound as one SQL NULL, leaving
     * a statement with one bind and no placeholder for it. The driver refused
     * it with "Invalid parameter number" and never reached the server.
     */
    #[Test]
    public function bindlessSubqueryExecutes(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id IN (SELECT user_id FROM post)'),
            $this->idsOf($this->users()->in('id', (new ActiveQuery())->from('post')->select('user_id')))
        );
    }

    #[Test]
    public function bindlessRawExecutes(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id IN (SELECT user_id FROM post)'),
            $this->idsOf($this->users()->in('id', new Raw('SELECT user_id FROM post')))
        );
    }

    #[Test]
    public function bindlessNotInSubqueryExecutes(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id NOT IN (SELECT user_id FROM post WHERE user_id IS NOT NULL)'),
            $this->idsOf($this->users()->notIn(
                'id',
                (new ActiveQuery())->from('post')->select('user_id')->notNull('user_id')
            ))
        );
    }

    #[Test]
    public function inRawWithBindsExecutes(): void
    {
        $this->assertSame(
            $this->truth('SELECT id FROM user WHERE id IN (SELECT user_id FROM post WHERE view_count > ?)', [100]),
            $this->idsOf($this->users()->in(
                'id',
                new Raw('SELECT user_id FROM post WHERE view_count > ?', [100])
            ))
        );
    }

    /**
     * The model-facing entry points reach the same answer.
     */
    #[Test]
    public function modelWhereInMatchesHandWrittenSql(): void
    {
        $expected = $this->truth('SELECT id FROM user WHERE id IN (2, 4, 6)');

        $ids = array_map(
            static fn(User $user): int => (int)$user->id,
            User::find()->whereIn('id', [2, 4, 6])->get()->all()
        );
        sort($ids);

        $this->assertSame($expected, $ids);
    }

    #[Test]
    public function modelEmptyWhereInFindsNothing(): void
    {
        $this->assertSame(0, User::find()->whereIn('id', [])->count());
        $this->assertEmpty(User::find()->whereIn('id', [])->get()->all());
    }

    /**
     * Counting an empty set goes through the aggregate path, which builds the
     * condition separately from the select.
     */
    #[Test]
    public function emptySetCountsCorrectly(): void
    {
        $total = User::find()->count();

        $this->assertSame(0, User::find()->in('id', [])->count());
        $this->assertSame($total, User::find()->notIn('id', [])->count());
    }

    /**
     * A value that cannot form a set is refused before anything is sent.
     */
    #[Test]
    public function scalarValueNeverReachesTheServer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs an array, subquery or Raw expression');

        $this->users()->where('id', 'IN', 5)->getSQL();
    }
}
