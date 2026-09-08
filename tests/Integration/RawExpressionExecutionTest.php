<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\CaseExpression;
use Simsoft\DB\Builder\Clauses\HavingClause;
use Simsoft\DB\Builder\Clauses\SelectClause;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Select;

/**
 * Expressions carrying bind values, executed by a server.
 *
 * The unit tests pin the statement and the bind list; these ask whether the
 * server accepts them and answers with the right rows. That is the part only a
 * server can settle — a dropped bind is not visible in the SQL, which still
 * reads correctly, only in the driver's refusal to run it.
 *
 * Every assertion compares against the same question written by hand in SQL, so
 * the fixture is free to change.
 *
 * Read-only — nothing here writes, so there is nothing to restore.
 */
class RawExpressionExecutionTest extends DatabaseTestCase
{
    /**
     * Run a hand-written statement and return its rows.
     *
     * @param string $sql The statement.
     * @param array<int, mixed> $binds Its binds.
     * @return array<int, array<string, mixed>>
     */
    private function truth(string $sql, array $binds = []): array
    {
        return (new Raw($sql, $binds))->fetchAll();
    }

    /**
     * Run a builder query and return its rows.
     *
     * @param ActiveQuery $query The query.
     * @return array<int, array<string, mixed>>
     */
    private function rows(ActiveQuery $query): array
    {
        return iterator_to_array($query->getArray());
    }

    /**
     * A fresh query over the post table.
     *
     * @return ActiveQuery
     */
    private function posts(): ActiveQuery
    {
        return (new ActiveQuery())->from('post p');
    }

    #[Test]
    public function selectRawWithABindExecutesAndAnswersCorrectly(): void
    {
        $query = $this->posts()
            ->select('id')
            ->selectRaw('IF(`status_code` = ?, 1, 0) AS pub', [2])
            ->orderBy('id')
            ->limit(5);

        $this->assertSame(
            $this->truth(
                'SELECT id, IF(`status_code` = ?, 1, 0) AS pub FROM post ORDER BY id LIMIT 5',
                [2]
            ),
            $this->rows($query)
        );
    }

    #[Test]
    public function orderByRawWithABindSortsAsHandWrittenSqlDoes(): void
    {
        $query = $this->posts()
            ->select('id')
            ->orderByRaw('FIELD(`status_code`, ?) DESC, `id` ASC', [2])
            ->limit(6);

        $this->assertSame(
            $this->truth(
                'SELECT id FROM post ORDER BY FIELD(`status_code`, ?) DESC, `id` ASC LIMIT 6',
                [2]
            ),
            $this->rows($query)
        );
    }

    #[Test]
    public function anExpressionSortIsNotQuotedAsAColumnName(): void
    {
        $query = $this->posts()
            ->select('id')
            ->orderBy(new Raw('FIELD(`status_code`, ?)', [2]))
            ->orderBy('id')
            ->limit(6);

        $this->assertSame(
            $this->truth(
                'SELECT id FROM post ORDER BY FIELD(`status_code`, ?), id ASC LIMIT 6',
                [2]
            ),
            $this->rows($query)
        );
    }

    /**
     * The example the ORDER BY documentation gives, run as written.
     *
     * It sorts by a CASE built with CaseExpression, whose WHEN and THEN values
     * are bound. Cast to a string the expression left its values behind and the
     * statement could not execute at all.
     */
    #[Test]
    public function aCaseExpressionSortExecutesAndAnswersCorrectly(): void
    {
        $query = $this->posts()
            ->select('id')
            ->orderBy(CaseExpression::when('status_code', '=', 2)->then(0)->else(1))
            ->orderBy('id')
            ->limit(6);

        $this->assertSame(
            $this->truth(
                'SELECT id FROM post ORDER BY CASE WHEN `status_code` = ? THEN ? ELSE ? END, id ASC LIMIT 6',
                [2, 0, 1]
            ),
            $this->rows($query)
        );
    }

    #[Test]
    public function aCaseExpressionCastToStringStillWorksWhenGivenItsBinds(): void
    {
        $case = CaseExpression::when('status_code', '=', 2)->then(0)->else(1);
        // Ask for the SQL first: a clause collects its binds while it builds.
        $sql = $case->getSQL();

        $query = $this->posts()
            ->select('id')
            ->orderByRaw($sql, $case->getBinds())
            ->orderBy('id')
            ->limit(6);

        $this->assertSame(
            $this->truth(
                'SELECT id FROM post ORDER BY CASE WHEN `status_code` = ? THEN ? ELSE ? END, id ASC LIMIT 6',
                [2, 0, 1]
            ),
            $this->rows($query)
        );
    }

    #[Test]
    public function aRawColumnInsideASelectClauseExecutes(): void
    {
        $query = $this->posts()
            ->select(new SelectClause([
                'id',
                new Raw('IF(`status_code` = ?, 1, 0) AS pub', [2]),
            ]))
            ->orderBy('id')
            ->limit(5);

        $this->assertSame(
            $this->truth(
                'SELECT id, IF(`status_code` = ?, 1, 0) AS pub FROM post ORDER BY id LIMIT 5',
                [2]
            ),
            $this->rows($query)
        );
    }

    #[Test]
    public function aRawHavingClauseExecutesWithItsBind(): void
    {
        $query = $this->posts()
            ->select('user_id')
            ->where(new HavingClause(new Raw('`user_id` > ?', [8])))
            ->orderBy('id');

        $this->assertSame(
            $this->truth('SELECT user_id FROM post WHERE user_id > ? ORDER BY id', [8]),
            $this->rows($query)
        );
    }

    #[Test]
    public function havingAgainstARawAggregateFiltersTheSameGroups(): void
    {
        $query = $this->posts()
            ->select('user_id', new Raw('COUNT(*) AS c'))
            ->groupBy('user_id')
            ->having(new Raw('COUNT(*)'), '>', 1)
            ->orderBy('user_id');

        $this->assertSame(
            $this->truth(
                'SELECT user_id, COUNT(*) AS c FROM post GROUP BY user_id HAVING COUNT(*) > ? ORDER BY user_id',
                [1]
            ),
            $this->rows($query)
        );
    }

    /**
     * Every section carrying a bind at once.
     *
     * Placeholders are positional, so the values have to reach the driver in
     * the order the sections are emitted. Adding ORDER BY to the sections that
     * can hold one is where that ordering could have gone wrong.
     */
    #[Test]
    public function bindsFromEverySectionReachTheServerInOrder(): void
    {
        $query = $this->posts()
            ->selectRaw('SUM(IF(`status_code` = ?, 1, 0)) AS published', [2])
            ->selectRaw('COUNT(*) AS c')
            ->where('user_id', '>', 1)
            ->groupByRaw('`user_id`')
            ->havingRaw('COUNT(*) > ?', [1])
            ->orderByRaw('SUM(IF(`status_code` = ?, 1, 0)) DESC, `user_id` ASC', [2]);

        $this->assertSame(
            $this->truth(
                'SELECT SUM(IF(`status_code` = ?, 1, 0)) AS published, COUNT(*) AS c FROM post'
                . ' WHERE user_id > ? GROUP BY `user_id`'
                . ' HAVING COUNT(*) > ? ORDER BY SUM(IF(`status_code` = ?, 1, 0)) DESC, `user_id` ASC',
                [2, 1, 1, 2]
            ),
            $this->rows($query)
        );
    }

    #[Test]
    public function orderBindsSurviveAMergeAndStillExecute(): void
    {
        $first = $this->posts()->select('id')->orderByRaw('FIELD(`status_code`, ?)', [2]);
        $second = $this->posts()->orderByRaw('FIELD(`user_id`, ?)', [5]);

        $merged = $first->merge($second)->limit(5);

        $this->assertSame(
            $this->truth(
                'SELECT id FROM post ORDER BY FIELD(`status_code`, ?), FIELD(`user_id`, ?) LIMIT 5',
                [2, 5]
            ),
            $this->rows($merged)
        );
    }

    #[Test]
    public function orderBindsPrecedeUnionBindsWhenExecuted(): void
    {
        $first = $this->posts()->select('id')
            ->where('user_id', '=', 1)
            ->orderByRaw('FIELD(`status_code`, ?)', [2]);
        $second = $this->posts()->select('id')->where('user_id', '=', 2);

        $this->assertSame(
            $this->truth(
                '(SELECT id FROM post WHERE user_id = ? ORDER BY FIELD(`status_code`, ?))'
                . ' UNION (SELECT id FROM post WHERE user_id = ?)',
                [1, 2, 2]
            ),
            $this->rows($first->union($second))
        );
    }

    /**
     * Select borrows a query's conditions, and must take only those binds.
     *
     * Handed the source query's select-list value as well, the driver was given
     * more values than the statement had placeholders and refused to run it.
     */
    #[Test]
    public function selectBorrowingConditionsExecutesWithoutTheSelectListBinds(): void
    {
        $source = $this->posts()
            ->selectRaw('IF(`status_code` = ?, 1, 0) AS pub', [2])
            ->where('user_id', '>', 8);
        $source->getSQL();

        $select = new Select('post', ['id'], $source);

        $this->assertSame(
            $this->truth('SELECT id FROM post WHERE user_id > ?', [8]),
            $select->query($select)
        );
    }

    #[Test]
    public function selectBorrowingConditionsKeepsTheOrderItReEmits(): void
    {
        $source = $this->posts()
            ->where('user_id', '>', 8)
            ->orderByRaw('FIELD(`status_code`, ?) DESC', [2]);
        $source->getSQL();

        $select = new Select('post', ['id'], $source);

        $this->assertSame(
            $this->truth(
                'SELECT id FROM post WHERE user_id > ? ORDER BY FIELD(`status_code`, ?) DESC',
                [8, 2]
            ),
            $select->query($select)
        );
    }
}
