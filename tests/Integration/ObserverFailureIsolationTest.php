<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\QueryLogger;
use Simsoft\DB\QueryMonitor;

/**
 * What a broken observer does to the queries it was only meant to watch.
 *
 * The unit tests drive logQuery() and recordQuery() directly, which proves the
 * guard is there but not that it is in the right place. These run real
 * statements against a real engine, because the defect was in the seam: both
 * observers are called from inside Execute's try, so anything their handler
 * threw was caught there and rewrapped as a QueryException naming the SQL. The
 * caller was told their statement had failed, by the code that was watching it.
 *
 * Three distinct outcomes were measured before the fix, and each is asserted
 * here:
 *
 *   - a write reported failure after the row had already been committed;
 *   - a read discarded rows it had already fetched;
 *   - and through QueryMonitor — which runs *before* the statement — the
 *     statement never ran at all. A loop of four inserts wrote one row.
 *
 * SQLite is used because the seam is in Execute, not in any driver, and an
 * in-memory database makes the row counts exact.
 */
class ObserverFailureIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::add('obs', ['driver' => 'sqlite', 'database' => ':memory:']);
        Connection::get('obs')->execute(new Raw('CREATE TABLE t (id INTEGER PRIMARY KEY, n INT)'));

        $this->quiesce();
    }

    protected function tearDown(): void
    {
        $this->quiesce();
        Connection::remove('obs');
        Connection::reset();
    }

    private function quiesce(): void
    {
        QueryLogger::disable();
        QueryLogger::clearHandler();
        QueryLogger::reset();
        QueryLogger::setLimit(QueryLogger::DEFAULT_LIMIT);
        QueryMonitor::disable();
        QueryMonitor::clearHandler();
        QueryMonitor::reset();
    }

    /**
     * Rows currently in the scratch table.
     *
     * @return array<int, int>
     */
    private function rows(): array
    {
        $rows = Connection::get('obs')->query(new Raw('SELECT n FROM t ORDER BY id'));

        return array_map(static fn(array $row): int => (int)$row['n'], $rows);
    }

    /**
     * Run something with E_USER_WARNING swallowed.
     *
     * The guard reports the handler's failure, which is the correct behaviour
     * and would otherwise fail the test as an unexpected warning.
     *
     * @param callable $body The code to run.
     * @return mixed
     */
    private function quietly(callable $body): mixed
    {
        set_error_handler(static fn(): bool => true, E_USER_WARNING);

        try {
            return $body();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function aWriteSucceedsWhenTheLogHandlerFails(): void
    {
        QueryLogger::enable();
        QueryLogger::setHandler(static function (): void {
            throw new RuntimeException('log sink is gone');
        });

        $insert = new Insert('t', ['n' => 1]);
        $insert->withConnection('obs');

        $result = $this->quietly(fn(): bool => $insert->execute());

        // Before the fix this threw QueryException — after the row had been
        // written. The caller was told the statement failed and would
        // reasonably have retried it, writing a second row.
        $this->assertTrue($result);
        $this->assertSame([1], $this->rows());
        $this->assertSame('1', $insert->getLastInsertId());
    }

    #[Test]
    public function aReadReturnsItsRowsWhenTheLogHandlerFails(): void
    {
        Connection::get('obs')->execute(new Raw('INSERT INTO t (n) VALUES (1), (2)'));

        QueryLogger::enable();
        QueryLogger::setHandler(static function (): void {
            throw new RuntimeException('log sink is gone');
        });

        $rows = $this->quietly(
            fn(): array => (new Raw('SELECT n FROM t ORDER BY id'))->withConnection('obs')->fetchAll()
        );

        // The rows had already been fetched when the handler threw. Discarding
        // them lost data that was in hand.
        $this->assertSame([['n' => 1], ['n' => 2]], $rows);
    }

    #[Test]
    public function everyWriteStillRunsWhenTheMonitorHandlerFails(): void
    {
        // The worst of the three: recordQuery() runs before the driver is
        // reached, so a throw there stops the statement entirely. Measured
        // before the fix, this loop wrote one row and lost three.
        QueryMonitor::enable(2);
        QueryMonitor::setHandler(static function (): void {
            throw new RuntimeException('monitor sink is gone');
        });

        $this->quietly(function (): void {
            for ($n = 1; $n <= 4; ++$n) {
                $insert = new Insert('t', ['n' => $n]);
                $this->assertTrue($insert->withConnection('obs')->execute());
            }
        });

        $this->assertSame([1, 2, 3, 4], $this->rows());
    }

    #[Test]
    public function theHandlerFailureIsReportedRatherThanSwallowed(): void
    {
        // Isolating the query from the handler must not hide that the handler
        // is broken: a sink that never receives anything looks exactly like a
        // process that issued no queries.
        QueryLogger::enable();
        QueryLogger::setHandler(static function (): void {
            throw new RuntimeException('log sink is gone');
        });

        $raised = [];
        set_error_handler(function (int $_no, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            (new Insert('t', ['n' => 1]))->withConnection('obs')->execute();
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $raised);
        $this->assertStringContainsString('QueryLogger handler threw', $raised[0]);
        $this->assertStringContainsString('log sink is gone', $raised[0]);
        $this->assertStringContainsString('query itself was unaffected', $raised[0]);
    }

    #[Test]
    public function aQueryStillRunsUnderAnErrorHandlerThatThrows(): void
    {
        // The dev setup that converts warnings to exceptions. Reporting the
        // handler's failure throws there, which would put the exception back on
        // the query path the guard exists to keep clear.
        QueryLogger::enable();
        QueryLogger::setHandler(static function (): void {
            throw new RuntimeException('log sink is gone');
        });

        set_error_handler(static function (int $_no, string $message): never {
            throw new \ErrorException($message);
        });

        try {
            $result = (new Insert('t', ['n' => 1]))->withConnection('obs')->execute();
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result);
        $this->assertSame([1], $this->rows());
    }

    #[Test]
    public function aWorkingHandlerStillSeesEveryQuery(): void
    {
        // The guard must not have turned into a blanket try/catch that also
        // hides a handler doing its job.
        $seen = [];
        QueryLogger::enable();
        QueryLogger::setHandler(function (string $sql) use (&$seen): void {
            $seen[] = $sql;
        });

        (new Insert('t', ['n' => 1]))->withConnection('obs')->execute();
        (new Raw('SELECT n FROM t'))->withConnection('obs')->fetchAll();

        $this->assertCount(2, $seen);
        $this->assertStringContainsStringIgnoringCase('insert', $seen[0]);
        $this->assertStringContainsStringIgnoringCase('select', $seen[1]);
    }
}
