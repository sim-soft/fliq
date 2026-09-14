<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * The methods that shape a query without adding a condition.
 *
 * `scope`, `when`, `unless` and `tap` all hand the query to a callback; what
 * distinguishes them is only which callback runs and what is returned. `union`
 * and its variants stringify a whole sub-query and collect its binds
 * separately, which is the shape that has produced bind-ordering defects
 * before, so the binds are asserted alongside the SQL.
 */
class QueryShapingTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user')->on('mysql')->select('id');
    }

    /**
     * The query's bind values, in order, asserting there are some.
     *
     * getBinds() answers null rather than [] when nothing is bound, so a query
     * that lost its binds entirely would otherwise reach array_values() as null.
     *
     * @return array<int, mixed>
     */
    private function binds(ActiveQuery $query): array
    {
        $binds = $query->getBinds();
        $this->assertNotNull($binds);

        return array_values($binds);
    }

    // ------------------------------------------------------------------
    // Unions
    // ------------------------------------------------------------------

    #[Test]
    public function unionAllKeepsBothHalvesAndTheirBindsInOrder(): void
    {
        $query = $this->query()->where('score', '>', 80)
            ->unionAll($this->query()->where('score', '<', 50));

        $this->assertSame(
            '(SELECT `user`.`id` FROM `user` WHERE `user`.`score` > ?)'
            . ' UNION ALL '
            . '(SELECT `user`.`id` FROM `user` WHERE `user`.`score` < ?)',
            $query->getSQL()
        );

        // The halves must not swap: 80 belongs to the > and 50 to the <.
        $this->assertSame([80, 50], $this->binds($query));
    }

    #[Test]
    public function unionDistinctEmitsItsOwnKeyword(): void
    {
        $query = $this->query()->where('score', '>', 80)
            ->unionDistinct($this->query()->where('score', '<', 50));

        $this->assertStringContainsString(' UNION DISTINCT ', $query->getSQL());
        $this->assertSame([80, 50], $this->binds($query));
    }

    #[Test]
    public function theThreeUnionKeywordsDiffer(): void
    {
        $plain = $this->query()->union($this->query())->getSQL();
        $all = $this->query()->unionAll($this->query())->getSQL();
        $distinct = $this->query()->unionDistinct($this->query())->getSQL();

        $this->assertStringContainsString(' UNION ', $plain);
        $this->assertStringNotContainsString(' UNION ALL ', $plain);
        $this->assertStringContainsString(' UNION ALL ', $all);
        $this->assertStringContainsString(' UNION DISTINCT ', $distinct);
    }

    // ------------------------------------------------------------------
    // select / order shorthands
    // ------------------------------------------------------------------

    #[Test]
    public function selectDistinctSelectsAndDistinctsInOneCall(): void
    {
        $this->assertSame(
            (new ActiveQuery())->from('user')->on('mysql')->select('role')->distinct()->getSQL(),
            (new ActiveQuery())->from('user')->on('mysql')->selectDistinct('role')->getSQL()
        );
    }

    #[Test]
    public function selectDistinctCarriesEveryColumn(): void
    {
        $this->assertSame(
            'SELECT DISTINCT `user`.`role`, `user`.`status_code` FROM `user`',
            (new ActiveQuery())->from('user')->on('mysql')->selectDistinct('role', 'status_code')->getSQL()
        );
    }

    // ------------------------------------------------------------------
    // Conditional builders
    // ------------------------------------------------------------------

    #[Test]
    public function scopeAppliesItsCallback(): void
    {
        $this->assertSame(
            $this->query()->where('score', '>', 80)->getSQL(),
            $this->query()->scope(fn(ActiveQuery $q) => $q->where('score', '>', 80))->getSQL()
        );
    }

    #[Test]
    public function whenAppliesOnlyWhenTrue(): void
    {
        $add = fn(ActiveQuery $q) => $q->where('score', '>', 80);

        $this->assertSame($this->query()->where('score', '>', 80)->getSQL(), $this->query()->when(true, $add)->getSQL());
        $this->assertSame($this->query()->getSQL(), $this->query()->when(false, $add)->getSQL());
    }

    #[Test]
    public function whenFallsBackToOtherwise(): void
    {
        $query = $this->query()->when(
            false,
            fn(ActiveQuery $q) => $q->where('score', '>', 80),
            fn(ActiveQuery $q) => $q->where('score', '<', 50)
        );

        $this->assertSame($this->query()->where('score', '<', 50)->getSQL(), $query->getSQL());
        $this->assertSame([50], $this->binds($query));
    }

    #[Test]
    public function unlessInvertsWhen(): void
    {
        $add = fn(ActiveQuery $q) => $q->where('score', '>', 80);

        $this->assertSame($this->query()->when(true, $add)->getSQL(), $this->query()->unless(false, $add)->getSQL());
        $this->assertSame($this->query()->when(false, $add)->getSQL(), $this->query()->unless(true, $add)->getSQL());
    }

    #[Test]
    public function unlessPassesOtherwiseThrough(): void
    {
        $query = $this->query()->unless(
            true,
            fn(ActiveQuery $q) => $q->where('score', '>', 80),
            fn(ActiveQuery $q) => $q->where('score', '<', 50)
        );

        $this->assertSame($this->query()->where('score', '<', 50)->getSQL(), $query->getSQL());
    }

    #[Test]
    public function tapObservesWithoutChanging(): void
    {
        $seen = null;
        $before = $this->query()->where('score', '>', 80);
        $sqlBefore = $before->getSQL();

        $after = $before->tap(function (ActiveQuery $q) use (&$seen): string {
            $seen = $q->getSQL();

            // A returned value must be ignored, not adopted as the query.
            return 'discarded';
        });

        $this->assertSame($sqlBefore, $seen);
        $this->assertSame($sqlBefore, $after->getSQL());
        $this->assertSame([80], $this->binds($after));
    }

    #[Test]
    public function tapReturnsTheSameQueryForChaining(): void
    {
        $query = $this->query();

        $this->assertSame($query, $query->tap(static fn(ActiveQuery $q): ActiveQuery => $q));
    }

    // ------------------------------------------------------------------
    // Inspection
    // ------------------------------------------------------------------

    #[Test]
    public function hasConditionsSeesEveryClauseThatNarrowsOrOrders(): void
    {
        $this->assertFalse($this->query()->hasConditions());
        $this->assertTrue($this->query()->where('id', '=', 1)->hasConditions());
        $this->assertTrue($this->query()->groupBy('role')->hasConditions());
        $this->assertTrue($this->query()->orderBy('id')->hasConditions());
        $this->assertTrue($this->query()->groupBy('role')->having('COUNT(*)', '>', 1)->hasConditions());
    }

    #[Test]
    public function hasConditionsIgnoresLimit(): void
    {
        // A limit bounds the result but does not select or order it.
        $this->assertFalse($this->query()->limit(5)->hasConditions());
    }

    #[Test]
    public function gettersReportWhatWasBuilt(): void
    {
        $this->assertSame([], (new ActiveQuery())->from('user')->on('mysql')->getSelects());
        $this->assertCount(2, $this->query()->select('username')->getSelects());
        $this->assertCount(1, $this->query()->where('id', '=', 1)->getConditions());
        $this->assertSame([], $this->query()->getConditions());
    }

    #[Test]
    public function eagerLoadGettersReportRequestedRelations(): void
    {
        $this->assertSame([], $this->query()->getEagerLoad());
        $this->assertContains('posts', $this->query()->with('posts')->getEagerLoad());

        $constrained = $this->query()->with(['posts' => static fn(ActiveQuery $q): ActiveQuery => $q]);
        $this->assertArrayHasKey('posts', $constrained->getEagerLoadConstraints());
        $this->assertSame([], $this->query()->getEagerLoadConstraints());
    }
}
