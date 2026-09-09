<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\ConnectionException;
use Simsoft\DB\Exceptions\QueryException;
use Throwable;

/**
 * Which exception a failed statement leaves the caller holding.
 *
 * Execute wraps whatever a statement throws in a QueryException naming the SQL,
 * which is the right thing for a statement the server rejected — but not for a
 * server that was never reached. A connection failure has nothing to do with
 * the statement, and reporting it as one sends the caller looking for a fault
 * in SQL that is perfectly good. So ConnectionException is let through
 * untouched, on both the read path and the write path.
 *
 * SQLite makes this testable without a server: a database file in a directory
 * that does not exist fails to open exactly as an unreachable host does.
 */
class ExecuteErrorRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::add('exec_ok', ['driver' => 'sqlite', 'database' => ':memory:']);
        Connection::add('exec_dead', ['driver' => 'sqlite', 'database' => $this->unopenableDatabase()]);

        Connection::get('exec_ok')->execute(new Raw('CREATE TABLE t (id INTEGER PRIMARY KEY, n INT)'));
    }

    protected function tearDown(): void
    {
        Connection::remove('exec_ok');
        Connection::remove('exec_dead');
    }

    /**
     * A database path that cannot be opened, its directory being absent.
     *
     * @return string
     */
    private function unopenableDatabase(): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'fliq_exec_absent_' . bin2hex(random_bytes(6))
            . DIRECTORY_SEPARATOR . 'db.sqlite';
    }

    #[Test]
    public function aWriteToAnUnreachableServerRaisesConnectionExceptionNotQueryException(): void
    {
        $insert = new Insert('t', ['n' => 1]);
        $insert->withConnection('exec_dead');

        // Caught as Throwable rather than as the class expected, so that the
        // assertion is what decides the test. Catching ConnectionException
        // directly would let a QueryException escape and error the test rather
        // than fail it, which reports the same defect less clearly — and the
        // two are siblings under RuntimeException, so no catch of one can
        // assert anything about the other.
        try {
            $insert->execute();
            $this->fail('a write to an unopenable database should have thrown');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception);
        }
    }

    #[Test]
    public function aReadFromAnUnreachableServerRaisesConnectionExceptionNotQueryException(): void
    {
        $select = new Raw('SELECT 1');
        $select->withConnection('exec_dead');

        try {
            $select->fetchAll();
            $this->fail('a read from an unopenable database should have thrown');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception);
        }
    }

    #[Test]
    public function aWriteTheServerRejectsIsReportedAsAQueryException(): void
    {
        // The other side of the same branch: the server answered, and what it
        // answered was that the statement is wrong. That one does belong to the
        // query, and comes back naming it.
        $insert = new Insert('no_such_table', ['n' => 1]);
        $insert->withConnection('exec_ok');

        $this->expectException(QueryException::class);

        $insert->execute();
    }

    #[Test]
    public function aReadTheServerRejectsIsReportedAsAQueryException(): void
    {
        $select = new Raw('SELECT * FROM no_such_table');
        $select->withConnection('exec_ok');

        $this->expectException(QueryException::class);

        $select->fetchAll();
    }

    #[Test]
    public function theQueryExceptionCarriesTheStatementThatFailed(): void
    {
        $select = new Raw('SELECT * FROM no_such_table WHERE n = ?', [7]);
        $select->withConnection('exec_ok');

        try {
            $select->fetchAll();
            $this->fail('a read of a missing table should have thrown');
        } catch (QueryException $exception) {
            // Wrapping exists to attach the statement; an exception that named
            // only the driver's message would be no better than the original.
            $this->assertStringContainsString('no_such_table', $exception->getSQL());
            $this->assertSame([7], $exception->getBinds());
        }
    }
}
