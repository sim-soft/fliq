<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * Unit tests for withAlias().
 *
 * The method had two defects, and neither produced an error.
 *
 * The alias never reached the columns it was meant to qualify. A simple column
 * name is stored deferred as `{name}` and only resolved in getSQL(), against
 * whatever alias is current at that point — which is the query's own, since
 * withAlias() restores it before returning. Every column named inside the
 * callback came out qualified with the FROM table, the exact opposite of the
 * point, and the SQL still ran: it just constrained the wrong table.
 *
 * Separately, only a closure was acted on. Any other callable — an invokable
 * object, a [$object, 'method'] pair — fell past the check and was discarded,
 * so the call added nothing and returned $this as though it had worked.
 */
class WithAliasTest extends TestCase
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

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * A query on `user` aliased to `u`.
     *
     * @return ActiveQuery
     */
    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user u')->on('mysql');
    }

    #[Test]
    public function aColumnNamedInTheCallbackTakesTheTemporaryAlias(): void
    {
        $sql = $this->query()->withAlias('t', function (ActiveQuery $query): void {
            $query->where('id', 1);
        })->getSQL();

        $this->assertStringContainsString('`t`.`id` = ?', $sql);
        $this->assertStringNotContainsString('`u`.`id`', $sql);
    }

    #[Test]
    public function theAliasAppliesOnlyInsideTheCallback(): void
    {
        $sql = $this->query()
            ->where('before', 1)
            ->withAlias('t', function (ActiveQuery $query): void {
                $query->where('inside', 1);
            })
            ->where('after', 1)
            ->getSQL();

        $this->assertStringContainsString('`u`.`before` = ?', $sql);
        $this->assertStringContainsString('`t`.`inside` = ?', $sql);
        $this->assertStringContainsString('`u`.`after` = ?', $sql);
    }

    #[Test]
    public function aJoinedTableCanBeConstrainedThroughItsAlias(): void
    {
        // The case the method exists for: the joined table's columns are named
        // plainly and picked up the FROM alias, so the condition silently
        // filtered `user` instead of `post`.
        $sql = $this->query()
            ->join('post p', ['p.user_id' => 'u.id'])
            ->withAlias('p', function (ActiveQuery $query): void {
                $query->where('view_count', '>', 0);
            })
            ->where('status_code', 1)
            ->getSQL();

        $this->assertStringContainsString('`p`.`view_count` > ?', $sql);
        $this->assertStringContainsString('`u`.`status_code` = ?', $sql);
    }

    #[Test]
    public function severalColumnsInOneCallbackAreAllQualified(): void
    {
        $sql = $this->query()->withAlias('t', function (ActiveQuery $query): void {
            $query->where('one', 1)->where('two', 2)->where('three', 3);
        })->getSQL();

        $this->assertStringContainsString('`t`.`one` = ?', $sql);
        $this->assertStringContainsString('`t`.`two` = ?', $sql);
        $this->assertStringContainsString('`t`.`three` = ?', $sql);
    }

    #[Test]
    public function orderingAndGroupingInsideTheCallbackAreQualifiedToo(): void
    {
        // The deferred placeholder is not specific to conditions, so every list
        // the callback can append to is resolved while the alias still holds.
        $sql = $this->query()->withAlias('t', function (ActiveQuery $query): void {
            $query->groupBy('kind')->orderBy('rank');
        })->getSQL();

        $this->assertStringContainsString('GROUP BY `t`.`kind`', $sql);
        $this->assertStringContainsString('ORDER BY `t`.`rank`', $sql);
    }

    #[Test]
    public function anExplicitPrefixInTheCallbackIsLeftAlone(): void
    {
        $sql = $this->query()->withAlias('t', function (ActiveQuery $query): void {
            $query->where('other.id', 1);
        })->getSQL();

        $this->assertStringContainsString('`other`.`id` = ?', $sql);
    }

    #[Test]
    public function nestedCallsRestoreTheOuterAlias(): void
    {
        $sql = $this->query()
            ->withAlias('a', function (ActiveQuery $query): void {
                $query->where('outer', 1)->withAlias('b', function (ActiveQuery $inner): void {
                    $inner->where('inner', 1);
                });
            })
            ->where('after', 1)
            ->getSQL();

        $this->assertStringContainsString('`a`.`outer` = ?', $sql);
        $this->assertStringContainsString('`b`.`inner` = ?', $sql);
        $this->assertStringContainsString('`u`.`after` = ?', $sql);
    }

    #[Test]
    public function anInvokableObjectIsApplied(): void
    {
        // Previously dropped without a word.
        $condition = new class {
            public function __invoke(ActiveQuery $query): void
            {
                $query->where('id', 1);
            }
        };

        $this->assertStringContainsString(
            '`t`.`id` = ?',
            $this->query()->withAlias('t', $condition)->getSQL()
        );
    }

    #[Test]
    public function anObjectMethodPairIsApplied(): void
    {
        $helper = new class {
            public function apply(ActiveQuery $query): void
            {
                $query->where('id', 1);
            }
        };

        $this->assertStringContainsString(
            '`t`.`id` = ?',
            $this->query()->withAlias('t', [$helper, 'apply'])->getSQL()
        );
    }

    #[Test]
    public function aFirstClassCallableIsApplied(): void
    {
        // This syntax produces a Closure wrapping a named method, which cannot
        // be rebound: attempting it warns and yields null, so the callable is
        // invoked unbound instead.
        $helper = new class {
            public function apply(ActiveQuery $query): void
            {
                $query->where('id', 1);
            }
        };

        $this->assertStringContainsString(
            '`t`.`id` = ?',
            $this->query()->withAlias('t', $helper->apply(...))->getSQL()
        );
    }

    #[Test]
    public function theAliasIsRestoredWhenTheCallbackThrows(): void
    {
        $query = $this->query();

        try {
            $query->withAlias('t', function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('The exception should have propagated.');
        } catch (RuntimeException) {
            // Expected — what matters is the state left behind.
        }

        // Without the restore, every later condition would be qualified with
        // `t`, a table the query never joined.
        $this->assertStringContainsString('`u`.`after` = ?', $query->where('after', 1)->getSQL());
    }

    #[Test]
    public function theQueryIsReturnedForChaining(): void
    {
        $query = $this->query();

        $this->assertSame($query, $query->withAlias('t', fn() => null));
    }

    #[Test]
    public function aCallbackAddingNothingLeavesTheQueryUnchanged(): void
    {
        $before = $this->query()->getSQL();

        $this->assertSame($before, $this->query()->withAlias('t', fn() => null)->getSQL());
    }
}
