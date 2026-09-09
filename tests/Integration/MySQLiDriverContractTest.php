<?php

namespace Integration;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Exceptions\ConnectionException;
use Simsoft\DB\Exceptions\QueryException;
use Throwable;

/**
 * Two things the mysqli driver has to get right that no other driver faces.
 *
 * The first is configuration: init_command is documented as a list, and the
 * driver has to deliver every entry in it.
 *
 * The second is mysqli's error mode, which is process-global. The driver sets
 * MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT inside connect(), but any other
 * library in the process can call mysqli_report(MYSQLI_REPORT_OFF) afterwards
 * — and then mysqli stops throwing and starts reporting failure by return
 * value. Everything the driver does has to hold under both modes, because it
 * does not own the setting. DriverReconnectTest already covers ping() on that
 * footing; these cover the statement paths.
 */
class MySQLiDriverContractTest extends DatabaseTestCase
{
    /** @var string The scratch table these tests write to. */
    private const TABLE = 'mysqli_contract_probe';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!static::$dbAvailable) {
            return;
        }

        $driver = Connection::get('mysql');
        $driver->execute(new Raw('DROP TABLE IF EXISTS ' . self::TABLE));
        $driver->execute(new Raw(
            'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY AUTO_INCREMENT, n INT NOT NULL) ENGINE=InnoDB'
        ));
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$dbAvailable && Connection::has('mysql')) {
            Connection::get('mysql')->execute(new Raw('DROP TABLE IF EXISTS ' . self::TABLE));
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
    }

    protected function tearDown(): void
    {
        if (Connection::has('probe')) {
            Connection::remove('probe');
        }

        parent::tearDown();
    }

    /**
     * Open a mysqli connection named 'probe' with extra configuration.
     *
     * @param array<string, mixed> $extra Configuration merged over the defaults.
     * @return Driver
     */
    private function probeConnection(array $extra = []): Driver
    {
        if (Connection::has('probe')) {
            Connection::remove('probe');
        }

        Connection::add('probe', array_merge([
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ], $extra));

        return Connection::get('probe');
    }

    /**
     * Run something with mysqli reporting turned off, as a host app might.
     *
     * @param callable $body The code to run.
     * @return mixed
     */
    private function withReportingOff(callable $body): mixed
    {
        $previous = (int)ini_get('mysqli.report_mode');
        mysqli_report(MYSQLI_REPORT_OFF);

        try {
            return $body();
        } finally {
            mysqli_report($previous);
        }
    }

    // ------------------------------------------------------------------
    // init_command is a list, and every entry has to run
    // ------------------------------------------------------------------

    #[Test]
    public function everyInitCommandIsApplied(): void
    {
        // The list used to be joined with '; ' and handed to mysqli as one
        // string, but MYSQLI_INIT_COMMAND carries a single statement: the
        // server parsed the whole thing as one and rejected the second half.
        // So configuring two init commands did not quietly skip the second, it
        // failed the connection outright with a syntax error quoting SQL the
        // caller had never written as one statement.
        $driver = $this->probeConnection([
            'init_command' => [
                "SET SESSION sql_mode='ANSI_QUOTES'",
                'SET SESSION group_concat_max_len=4096',
            ],
        ]);

        $row = $driver->query(new Raw(
            'SELECT @@session.sql_mode AS m, @@session.group_concat_max_len AS g'
        ))[0];

        $this->assertSame('ANSI_QUOTES', $row['m'], 'the first command ran');
        $this->assertSame(4096, (int)$row['g'], 'and so did the second');
    }

    #[Test]
    public function asingleInitCommandStillWorks(): void
    {
        $driver = $this->probeConnection([
            'init_command' => ["SET SESSION sql_mode='ANSI_QUOTES'"],
        ]);

        $this->assertSame(
            'ANSI_QUOTES',
            $driver->query(new Raw('SELECT @@session.sql_mode AS m'))[0]['m']
        );
    }

    #[Test]
    public function noInitCommandLeavesTheSessionAlone(): void
    {
        $driver = $this->probeConnection();

        $this->assertNotSame(
            'ANSI_QUOTES',
            $driver->query(new Raw('SELECT @@session.sql_mode AS m'))[0]['m']
        );
    }

    #[Test]
    public function initCommandsRunAgainOnAReconnectedConnection(): void
    {
        // Session settings live on the connection, so a reconnect that did not
        // re-apply them would hand back a session quietly missing the time zone
        // or sql_mode the caller configured — and the queries that followed
        // would be answered under different rules than the ones before it.
        // This is the reason to configure them here rather than issue them once
        // after connecting.
        $driver = $this->probeConnection([
            'init_command' => [
                "SET SESSION sql_mode='ANSI_QUOTES'",
                'SET SESSION group_concat_max_len=4096',
            ],
        ]);

        $before = $driver->query(new Raw('SELECT CONNECTION_ID() AS c'))[0]['c'];
        Connection::get('mysql')->execute(new Raw('KILL ' . (int)$before));
        usleep(300000);

        $after = $driver->query(new Raw(
            'SELECT @@session.sql_mode AS m, @@session.group_concat_max_len AS g, CONNECTION_ID() AS c'
        ))[0];

        $this->assertNotEquals($before, $after['c'], 'the connection really was replaced');
        $this->assertSame('ANSI_QUOTES', $after['m']);
        $this->assertSame(4096, (int)$after['g']);
    }

    // ------------------------------------------------------------------
    // A rejected statement must say so in either error mode
    // ------------------------------------------------------------------

    #[Test]
    public function anUnboundWriteThatFailsRaisesRatherThanReturningFalse(): void
    {
        // The defect this covers: with no binds the driver called
        // mysqli::query() and returned `$result !== false`. Under
        // MYSQLI_REPORT_OFF a rejected statement returns false rather than
        // throwing, so the failure came back as a false return — and nothing
        // reads the return of execute() as a failure signal. The write was
        // refused by the server and the caller was told nothing at all.
        $driver = $this->probeConnection();
        $driver->query(new Raw('SELECT 1'));

        $this->withReportingOff(function () use ($driver): void {
            try {
                $driver->execute(new Raw('INSERT INTO no_such_table_here (n) VALUES (1)'));
                $this->fail('a statement the server rejected reported success');
            } catch (AssertionFailedError $failure) {
                throw $failure;
            } catch (Throwable $exception) {
                $this->assertStringContainsString('no_such_table_here', $exception->getMessage());
            }
        });
    }

    #[Test]
    public function aBoundWriteThatCannotBePreparedRaises(): void
    {
        // The same mode, the other branch: prepare() answers false instead of
        // throwing, and the guard that reads it is what turns that back into a
        // failure the caller can see.
        $driver = $this->probeConnection();
        $driver->query(new Raw('SELECT 1'));

        $this->withReportingOff(function () use ($driver): void {
            try {
                $driver->execute(new Raw('INSERT INTO no_such_table_here (n) VALUES (?)', [1]));
                $this->fail('a statement the server rejected reported success');
            } catch (AssertionFailedError $failure) {
                throw $failure;
            } catch (Throwable $exception) {
                $this->assertStringContainsString('no_such_table_here', $exception->getMessage());
            }
        });
    }

    #[Test]
    public function aBoundWriteRejectedWhenItRunsRaises(): void
    {
        // The commonest kind of write failure there is, and the one the other
        // two branches do not cover: the statement prepares cleanly and the
        // server rejects it at execution — a duplicate key, a NOT NULL
        // violation. Under MYSQLI_REPORT_OFF execute() reports that by
        // returning false, and this method used to return that false as its own
        // result. Measured: the row was not written and nothing was raised.
        $driver = $this->probeConnection();
        $driver->execute(new Raw('INSERT INTO ' . self::TABLE . ' (id, n) VALUES (901, 1)'));

        $this->withReportingOff(function () use ($driver): void {
            foreach (
                [
                    [
                        new Raw('INSERT INTO ' . self::TABLE . ' (id, n) VALUES (?, ?)', [901, 2]),
                        'Duplicate',
                    ],
                    [
                        new Raw('INSERT INTO ' . self::TABLE . ' (id, n) VALUES (?, ?)', [902, null]),
                        'cannot be null',
                    ],
                ] as [$statement, $expected]
            ) {
                try {
                    $driver->execute($statement);
                    $this->fail('a write the server rejected reported success');
                } catch (AssertionFailedError $failure) {
                    throw $failure;
                } catch (Throwable $exception) {
                    $this->assertStringContainsString($expected, $exception->getMessage());
                }
            }
        });

        $rows = $driver->query(new Raw('SELECT id FROM ' . self::TABLE . ' WHERE id IN (901, 902)'));

        $this->assertCount(1, $rows, 'neither rejected row was written');
    }

    #[Test]
    public function aGoodBoundWriteStillReportsSuccess(): void
    {
        $driver = $this->probeConnection();

        $this->withReportingOff(function () use ($driver): void {
            $this->assertTrue($driver->execute(new Raw(
                'INSERT INTO ' . self::TABLE . ' (id, n) VALUES (?, ?)',
                [903, 33]
            )));
        });

        $this->assertSame(
            33,
            (int)$driver->query(new Raw('SELECT n FROM ' . self::TABLE . ' WHERE id = 903'))[0]['n']
        );
    }

    #[Test]
    public function aReadThatCannotBePreparedRaises(): void
    {
        $driver = $this->probeConnection();
        $driver->query(new Raw('SELECT 1'));

        $this->withReportingOff(function () use ($driver): void {
            try {
                $driver->query(new Raw('SELECT * FROM no_such_table_here'));
                $this->fail('a read against a missing table reported success');
            } catch (AssertionFailedError $failure) {
                throw $failure;
            } catch (Throwable $exception) {
                $this->assertStringContainsString('no_such_table_here', $exception->getMessage());
            }
        });
    }

    #[Test]
    public function aReadWhoseStatementFailsAtExecutionRaises(): void
    {
        // get_result() answers false for a failed statement as well as for one
        // with no result set, and the branch that tells them apart has to pick
        // "failed" here: the statement prepared, ran, and was rejected. The
        // sibling test below covers the other side of the same branch.
        $driver = $this->probeConnection();
        $driver->execute(new Raw('INSERT INTO ' . self::TABLE . ' (id, n) VALUES (911, 1)'));

        $this->withReportingOff(function () use ($driver): void {
            try {
                $driver->query(new Raw(
                    'INSERT INTO ' . self::TABLE . ' (id, n) VALUES (?, ?)',
                    [911, 2]
                ));
                $this->fail('a statement the server rejected reported success');
            } catch (AssertionFailedError $failure) {
                throw $failure;
            } catch (Throwable $exception) {
                $this->assertStringContainsString('Duplicate', $exception->getMessage());
            }
        });
    }

    // ------------------------------------------------------------------
    // Connection options
    // ------------------------------------------------------------------

    #[Test]
    public function aPersistentConnectionStillWorks(): void
    {
        // 'persistent' switches the host to mysqli's 'p:' form, which reuses a
        // pooled connection rather than opening one. Everything downstream has
        // to behave identically — including the init commands, which mysqli
        // runs on a pooled connection too.
        $driver = $this->probeConnection([
            'persistent' => true,
            'init_command' => ["SET SESSION sql_mode='ANSI_QUOTES'"],
        ]);

        $row = $driver->query(new Raw('SELECT @@session.sql_mode AS m, 1 + 1 AS s'))[0];

        $this->assertSame(2, (int)$row['s']);
        $this->assertSame('ANSI_QUOTES', $row['m']);
    }

    #[Test]
    public function driverLevelOptionsAreApplied(): void
    {
        // MYSQLI_OPT_* constants passed through 'options' reach mysqli itself
        // rather than the session, so there is nothing to read back — what is
        // being checked is that setting them does not break the connect.
        $driver = $this->probeConnection([
            'options' => [MYSQLI_OPT_CONNECT_TIMEOUT => 7],
        ]);

        $this->assertSame(1, (int)$driver->query(new Raw('SELECT 1 AS n'))[0]['n']);
    }

    #[Test]
    public function theSameFailuresRaiseUnderTheDefaultReportingMode(): void
    {
        // The guards must not have changed what happens in the mode the driver
        // itself sets, where mysqli throws before any of them is reached.
        $driver = $this->probeConnection();

        foreach (
            [
                new Raw('INSERT INTO no_such_table_here (n) VALUES (1)'),
                new Raw('INSERT INTO no_such_table_here (n) VALUES (?)', [1]),
            ] as $statement
        ) {
            try {
                $driver->execute($statement);
                $this->fail('a statement the server rejected reported success');
            } catch (AssertionFailedError $failure) {
                throw $failure;
            } catch (Throwable $exception) {
                $this->assertStringContainsString('no_such_table_here', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aGoodUnboundWriteStillReportsSuccess(): void
    {
        // Guarding the failure must not have turned a working write into one
        // that raises, in either mode.
        $driver = $this->probeConnection();

        $this->assertTrue($driver->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (11)')));

        $this->withReportingOff(function () use ($driver): void {
            $this->assertTrue($driver->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (12)')));
        });

        $rows = $driver->query(new Raw('SELECT n FROM ' . self::TABLE . ' WHERE n IN (11, 12) ORDER BY n'));

        $this->assertSame([11, 12], array_map(static fn(array $r): int => (int)$r['n'], $rows));
    }

    #[Test]
    public function aStatementWithNoResultSetStillReturnsNoRows(): void
    {
        // get_result() answers false both for a failure and for a statement
        // that never had a result set. This is the second kind, and it must not
        // be read as the first.
        $driver = $this->probeConnection();

        $this->assertSame([], $driver->query(new Raw('SET @probe_x = 1')));

        $this->withReportingOff(function () use ($driver): void {
            $this->assertSame([], $driver->query(new Raw('SET @probe_y = 1')));
        });
    }

    #[Test]
    public function aFailedWriteThroughTheQueryBuilderSurfacesAsAQueryException(): void
    {
        // End to end: the driver raising is what lets Execute report the
        // failure as a QueryException naming the statement, which is what a
        // caller actually sees.
        $driver = $this->probeConnection();
        $driver->query(new Raw('SELECT 1'));

        $this->withReportingOff(function (): void {
            $this->expectException(QueryException::class);

            (new Raw('INSERT INTO no_such_table_here (n) VALUES (1)'))
                ->withConnection('probe')
                ->execute();
        });
    }

    #[Test]
    public function anInitCommandThatFailsFailsTheConnection(): void
    {
        // Init commands run at connect time, so a bad one has nowhere later to
        // report from. Failing the connection is the honest outcome — the
        // alternative is a session that silently lacks the settings the caller
        // asked for.
        $this->expectException(ConnectionException::class);

        $this->probeConnection(['init_command' => ['THIS IS NOT SQL']]);
    }
}
