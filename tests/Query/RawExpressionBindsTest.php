<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\CaseExpression;
use Simsoft\DB\Builder\Clauses\HavingClause;
use Simsoft\DB\Builder\Clauses\SelectClause;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Select;
use Simsoft\DB\Connection;

/**
 * Bind values carried by expressions outside the WHERE clause.
 *
 * A Raw expression may hold placeholders wherever it is accepted, not only in
 * a condition: `IF(score > ?, 1, 0) AS grade` in the select list, `FIELD(status,
 * ?)` in the sort, `COUNT(*) > ?` in the filter after aggregation. Only the
 * WHERE path collected those values. Everywhere else they were dropped, so the
 * statement emitted a placeholder with nothing to fill it and the driver
 * refused it outright — "must consist of exactly N elements, 0 present".
 *
 * The failure is at least loud. Its mirror is not: Select borrowed a query's
 * conditions but took all of its binds, including the select-list values whose
 * placeholders it never emitted, leaving the driver over-supplied instead.
 */
class RawExpressionBindsTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
        ]);
    }

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user u');
    }

    // selectRaw()

    #[Test]
    public function selectRawCarriesItsBinds(): void
    {
        $query = $this->query()->selectRaw('IF(`score` > ?, 1, 0) AS grade', [90]);

        $this->assertSame(
            'SELECT IF(`score` > ?, 1, 0) AS grade FROM `user` `u`',
            $query->getSQL()
        );
        $this->assertSame([90], $query->getBinds());
    }

    #[Test]
    public function selectRawWithoutBindsCollectsNone(): void
    {
        $query = $this->query()->selectRaw('COUNT(*) AS total');

        $this->assertSame('SELECT COUNT(*) AS total FROM `user` `u`', $query->getSQL());
        $this->assertNull($query->getBinds());
    }

    // orderByRaw()

    #[Test]
    public function orderByRawCarriesItsBinds(): void
    {
        $query = $this->query()->orderByRaw('FIELD(`status`, ?, ?)', ['draft', 'live']);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` ORDER BY FIELD(`status`, ?, ?)',
            $query->getSQL()
        );
        $this->assertSame(['draft', 'live'], $query->getBinds());
    }

    #[Test]
    public function orderByRawWithoutBindsCollectsNone(): void
    {
        $query = $this->query()->orderByRaw('FIELD(`status`, 3, 1, 2)');

        $this->assertNull($query->getBinds());
    }

    // orderBy() with an expression

    #[Test]
    public function orderByRawExpressionIsEmittedAsWrittenNotQuoted(): void
    {
        $query = $this->query()->orderBy(new Raw('FIELD(`status`, ?)', ['live']));

        // Quoted as a column name it asked for `u`.`FIELD(``status``, ?)`.
        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` ORDER BY FIELD(`status`, ?)',
            $query->getSQL()
        );
        $this->assertSame(['live'], $query->getBinds());
    }

    #[Test]
    public function orderByClauseIsBuiltAndItsBindsCollected(): void
    {
        $query = $this->query()->orderBy(
            CaseExpression::when('role', '=', 'admin')->then(1)->else(2)
        );

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` ORDER BY CASE WHEN `u`.`role` = ? THEN ? ELSE ? END',
            $query->getSQL()
        );
        $this->assertSame(['admin', 1, 2], $query->getBinds());
    }

    #[Test]
    public function anExpressionInsideAnOrderByListIsAlsoEmittedAsWritten(): void
    {
        $query = $this->query()->orderBy([new Raw('FIELD(`status`, ?)', ['live']), 'id']);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` ORDER BY FIELD(`status`, ?), `u`.`id` ASC',
            $query->getSQL()
        );
        $this->assertSame(['live'], $query->getBinds());
    }

    #[Test]
    public function aNamedColumnStillTakesItsDirection(): void
    {
        $query = $this->query()->orderBy(['name' => 'DESC', 'id' => 'ASC']);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` ORDER BY `u`.`name` DESC, `u`.`id` ASC',
            $query->getSQL()
        );
    }

    #[Test]
    public function aListOfNamesStillTakesTheDirectionArgument(): void
    {
        $query = $this->query()->orderBy(['name', 'id'], 'DESC');

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` ORDER BY `u`.`name` DESC, `u`.`id` DESC',
            $query->getSQL()
        );
    }

    // SelectClause

    #[Test]
    public function aRawColumnInsideASelectClauseCarriesItsBinds(): void
    {
        $clause = new SelectClause(['id', new Raw('IF(`score` > ?, 1, 0) AS grade', [90])]);

        $this->assertSame('{id}, IF(`score` > ?, 1, 0) AS grade', $clause->getSQL());
        $this->assertSame([90], $clause->getBinds());
    }

    #[Test]
    public function aSelectClauseReachesTheQueryWithItsBinds(): void
    {
        $query = $this->query()->select(
            new SelectClause([new Raw('IF(`score` > ?, 1, 0) AS grade', [90])])
        );

        $this->assertSame(
            'SELECT IF(`score` > ?, 1, 0) AS grade FROM `user` `u`',
            $query->getSQL()
        );
        $this->assertSame([90], $query->getBinds());
    }

    // HavingClause

    #[Test]
    public function aRawHavingClauseCarriesItsBinds(): void
    {
        $clause = new HavingClause(new Raw('COUNT(*) > ?', [5]));

        $this->assertSame('COUNT(*) > ?', $clause->getSQL());
        $this->assertSame([5], $clause->getBinds());
    }

    #[Test]
    public function aRawHavingClauseWithoutBindsCollectsNone(): void
    {
        $clause = new HavingClause(new Raw('COUNT(*) > 5'));

        $this->assertNull($clause->getBinds());
    }

    // Bind ordering across sections

    #[Test]
    public function bindsAreOrderedAsTheirPlaceholdersAreEmitted(): void
    {
        $query = $this->query()
            ->select(new Raw('IF(`score` > ?, 1, 0) AS grade', [90]))
            ->where('status', '=', 'live')
            ->groupBy(new Raw('IF(`score` > ?, 1, 0)', [90]))
            ->havingRaw('COUNT(*) > ?', [1])
            ->orderByRaw('FIELD(`role`, ?)', ['admin']);

        $this->assertSame(
            'SELECT IF(`score` > ?, 1, 0) AS grade FROM `user` `u` WHERE `u`.`status` = ?'
            . ' GROUP BY IF(`score` > ?, 1, 0) HAVING COUNT(*) > ? ORDER BY FIELD(`role`, ?)',
            $query->getSQL()
        );
        $this->assertSame([90, 'live', 90, 1, 'admin'], $query->getBinds());
    }

    #[Test]
    public function orderBindsAreCarriedThroughAMerge(): void
    {
        $first = $this->query()->orderByRaw('FIELD(`status`, ?)', ['live']);
        $second = $this->query()->orderByRaw('FIELD(`role`, ?)', ['admin']);

        $merged = $first->merge($second);

        $this->assertSame(
            'SELECT `u`.* FROM `user` `u` ORDER BY FIELD(`status`, ?), FIELD(`role`, ?)',
            $merged->getSQL()
        );
        $this->assertSame(['live', 'admin'], $merged->getBinds());
    }

    #[Test]
    public function orderBindsPrecedeUnionBinds(): void
    {
        $first = $this->query()->select('id')->orderByRaw('FIELD(`status`, ?)', ['live']);
        $second = $this->query()->select('id')->where('role', '=', 'admin');

        $union = $first->union($second);

        $this->assertSame(['live', 'admin'], $union->getBinds());
    }

    #[Test]
    public function clearBindsDiscardsOrderBinds(): void
    {
        $query = $this->query()->orderByRaw('FIELD(`status`, ?)', ['live']);
        $this->assertSame(['live'], $query->getBinds());

        $query->clearBinds();

        $this->assertNull($query->getBinds());
    }

    // Select borrowing another query's conditions

    #[Test]
    public function selectTakesOnlyTheBindsWhosePlaceholdersItEmits(): void
    {
        $source = $this->query()
            ->select(new Raw('IF(`score` > ?, 1, 0) AS grade', [90]))
            ->where('role', '=', 'admin');
        $source->getSQL();

        $select = new Select('user', ['id'], $source);

        // The select-list placeholder is not re-emitted, so its value must not
        // be handed over: the driver counts values against placeholders.
        $this->assertSame('SELECT id FROM user WHERE `role` = ?', $select->getSQL());
        $this->assertSame(['admin'], $select->getBinds());
    }

    #[Test]
    public function selectTakesTheOrderBindsItDoesEmit(): void
    {
        $source = $this->query()
            ->where('role', '=', 'admin')
            ->orderByRaw('FIELD(`status`, ?)', ['live']);
        $source->getSQL();

        $select = new Select('user', ['id'], $source);

        $this->assertSame(
            'SELECT id FROM user WHERE `role` = ? ORDER BY FIELD(`status`, ?)',
            $select->getSQL()
        );
        $this->assertSame(['admin', 'live'], $select->getBinds());
    }
}
