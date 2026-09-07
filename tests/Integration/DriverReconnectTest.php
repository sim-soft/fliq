<?php

namespace Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Exceptions\ConnectionException;

/**
 * Recovering a connection the server has closed.
 *
 * docs/01-GETTING-STARTED.md promises "All four drivers recover from this
 * automatically", by an idle ping and by a statement-level retry. Both routes
 * covered execute() and query(). Neither covered opening a transaction, so a
 * worker whose connection had dropped while idle recovered when its next
 * statement happened to be a query and failed when it happened to be a
 * transaction — the case where losing the write matters most.
 *
 * The drop is real here: MySQL's KILL, issued down a second connection, is what
 * a server-side idle timeout or a load balancer does to a pooled connection.
 */
class DriverReconnectTest extends DatabaseTestCase
{
    /** @var string The scratch table these tests write to. */
    private const TABLE = 'reconnect_probe';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!static::$dbAvailable) {
            return;
        }

        $driver = Connection::get('mysql');
        $driver->execute(new Raw('DROP TABLE IF EXISTS ' . self::TABLE));
        $driver->execute(new Raw(
            'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY AUTO_INCREMENT, n INT) ENGINE=InnoDB'
        ));
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$dbAvailable && Connection::has('mysql')) {
            Connection::get('mysql')->execute(new Raw('DROP TABLE IF EXISTS ' . self::TABLE));
        }

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        foreach (['probe', 'executioner'] as $name) {
            if (Connection::has($name)) {
                Connection::remove($name);
            }
        }

        parent::tearDown();
    }

    /**
     * The two MySQL drivers, which are the ones a KILL can reach.
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function mysqlDrivers(): array
    {
        return [
            'mysqli' => ['mysqli'],
            'pdo' => ['pdo_mysql'],
        ];
    }

    /**
     * Open a fresh connection under the name "probe".
     *
     * @param non-empty-string $driver The driver key to build.
     * @param array<string, mixed> $extra Extra config for this connection.
     * @return Driver
     */
    private function probeConnection(string $driver, array $extra = []): Driver
    {
        if (Connection::has('probe')) {
            Connection::remove('probe');
        }

        Connection::add('probe', [
            'driver' => $driver,
            'host' => (string)(getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'database' => (string)(getenv('DB_DATABASE') ?: 'sample_db'),
            'username' => (string)(getenv('DB_USERNAME') ?: 'root'),
            'password' => (string)(getenv('DB_PASSWORD') ?: ''),
            ...$extra,
        ]);

        return Connection::get('probe');
    }

    /**
     * Close a driver's connection from the server side.
     *
     * KILL must come down a different connection — the point is that the
     * connection dies without the driver being told.
     *
     * @param Driver $driver The connection to kill.
     * @return void
     */
    private function killFromTheServer(Driver $driver): void
    {
        $id = (int)$driver->query(new Raw('SELECT CONNECTION_ID() AS id'))[0]['id'];

        Connection::get('mysql')->execute(new Raw('KILL ' . $id));

        // KILL is asynchronous; give the server a moment to close the socket.
        usleep(200000);
    }

    /**
     * @param non-empty-string $rows The table to count.
     * @return int
     */
    private function rowsIn(string $rows): int
    {
        return (int)Connection::get('mysql')->query(new Raw("SELECT COUNT(*) AS c FROM $rows"))[0]['c'];
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aQueryRecoversFromAConnectionClosedWhileIdle(string $driver): void
    {
        // The route that already worked. It is here so that when the
        // transaction case below fails, this one says whether recovery is
        // broken generally or only for transactions.
        $probe = $this->probeConnection($driver);
        $this->killFromTheServer($probe);

        $this->assertSame(1, (int)$probe->query(new Raw('SELECT 1 AS n'))[0]['n']);
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aTransactionRecoversFromAConnectionClosedWhileIdle(string $driver): void
    {
        // The defect. Before the fix this threw: mysqli with a bare
        // "MySQL server has gone away", pdo with the same, and the write the
        // caller had asked for never happened.
        $probe = $this->probeConnection($driver);
        $this->killFromTheServer($probe);

        $committed = $probe->transaction(function () use ($probe): bool {
            $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [7]));

            return true;
        });

        $this->assertTrue($committed);

        // Committed on the new connection, and visible from another one.
        $seen = Connection::get('mysql')
            ->query(new Raw('SELECT n FROM ' . self::TABLE . ' WHERE n = ?', [7]));
        $this->assertCount(1, $seen);

        Connection::get('mysql')->execute(new Raw('DELETE FROM ' . self::TABLE . ' WHERE n = ?', [7]));
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function anEmptyTransactionRecoversToo(string $driver): void
    {
        $probe = $this->probeConnection($driver);
        $this->killFromTheServer($probe);

        $this->assertTrue($probe->transaction(fn(): bool => true));
        $this->assertSame(0, $probe->getTransactionLevel());
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aConnectionLostInsideATransactionIsStillRefused(string $driver): void
    {
        // The other half of the contract, and the half that must not be
        // weakened by making the opening statement recover. Once a statement
        // has been written, reconnecting would discard it and let the rest
        // commit alone — a partial write, which is the thing the transaction
        // was there to prevent.
        $probe = $this->probeConnection($driver);
        $before = $this->rowsIn(self::TABLE);

        $this->expectException(ConnectionException::class);

        try {
            $probe->transaction(function () use ($probe): bool {
                $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [81]));
                $this->killFromTheServer($probe);
                $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [82]));

                return true;
            });
        } finally {
            // Nothing may have survived: the server discards an interrupted
            // transaction, and the driver must not have reconnected under it.
            $this->assertSame($before, $this->rowsIn(self::TABLE));
            $this->assertSame(0, $probe->getTransactionLevel());
        }
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aConnectionLostInsideANestedTransactionIsRefused(string $driver): void
    {
        $probe = $this->probeConnection($driver);
        $before = $this->rowsIn(self::TABLE);

        $this->expectException(ConnectionException::class);

        try {
            $probe->transaction(function () use ($probe): bool {
                $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [83]));

                // The inner block opens a savepoint, and the reconnect check
                // must not run on that branch: there is a transaction open by
                // definition, so reconnecting could only discard it.
                $probe->transaction(function () use ($probe): bool {
                    $this->killFromTheServer($probe);
                    $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [84]));

                    return true;
                });

                return true;
            });
        } finally {
            $this->assertSame($before, $this->rowsIn(self::TABLE));
            $this->assertSame(0, $probe->getTransactionLevel());
        }
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function committingAndRollingBackStillWork(string $driver): void
    {
        // Opening a transaction now does more than it did. The ordinary
        // outcomes have to be exactly as they were.
        $probe = $this->probeConnection($driver);

        $probe->transaction(function () use ($probe): bool {
            $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [201]));

            return true;
        });
        $this->assertSame(1, $this->rowsIn(self::TABLE . ' WHERE n = 201'));

        $probe->transaction(function () use ($probe): bool {
            $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [202]));

            return false;
        });
        $this->assertSame(0, $this->rowsIn(self::TABLE . ' WHERE n = 202'));

        try {
            $probe->transaction(function () use ($probe): bool {
                $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [203]));

                throw new RuntimeException('unwind');
            });
        } catch (RuntimeException) {
            // Expected: the throw is what triggers the rollback.
        }
        $this->assertSame(0, $this->rowsIn(self::TABLE . ' WHERE n = 203'));

        Connection::get('mysql')->execute(new Raw('DELETE FROM ' . self::TABLE . ' WHERE n = 201'));
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function nestedSavepointsStillBehave(string $driver): void
    {
        $probe = $this->probeConnection($driver);

        $probe->transaction(function () use ($probe): bool {
            $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [301]));

            $probe->transaction(function () use ($probe): bool {
                $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [302]));

                return false;
            });

            $probe->transaction(function () use ($probe): bool {
                $probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [303]));

                return true;
            });

            return true;
        });

        $this->assertSame(1, $this->rowsIn(self::TABLE . ' WHERE n = 301'));
        $this->assertSame(0, $this->rowsIn(self::TABLE . ' WHERE n = 302'));
        $this->assertSame(1, $this->rowsIn(self::TABLE . ' WHERE n = 303'));

        Connection::get('mysql')
            ->execute(new Raw('DELETE FROM ' . self::TABLE . ' WHERE n IN (301, 303)'));
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function pingLeavesTheConnectionUsable(string $driver): void
    {
        // PDODriver::ping() ran SELECT 1 and never read the row. MySQL does not
        // buffer by default, so the result set stayed open and the next
        // prepared statement failed with 2014 — the liveness check breaking the
        // connection it had just vouched for.
        $probe = $this->probeConnection($driver);

        $this->assertTrue($probe->ping());
        $this->assertSame(2, (int)$probe->query(new Raw('SELECT 2 AS n'))[0]['n']);

        $this->assertTrue($probe->ping());
        $this->assertTrue($probe->execute(new Raw('SELECT 3')));
        $this->assertSame(4, (int)$probe->query(new Raw('SELECT 4 AS n'))[0]['n']);
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function pingBeforeEveryStatementIsUsable(string $driver): void
    {
        // ping_idle_seconds => 0 is documented as "check before every query",
        // so it is the setting that turns an unread ping result from an
        // occasional failure into one on every statement.
        $probe = $this->probeConnection($driver, ['ping_idle_seconds' => 0]);

        $this->assertTrue($probe->execute(new Raw('SELECT 1')));
        $this->assertSame(5, (int)$probe->query(new Raw('SELECT 5 AS n'))[0]['n']);
        $this->assertTrue($probe->transaction(fn(): bool => true));
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function executingARowReturningStatementLeavesNoOpenCursor(string $driver): void
    {
        // PDODriver::execute() sent unbound SQL through PDO::exec(), which
        // hands back no statement to close. On a row-returning statement the
        // result set stayed open and the failure surfaced later, on whichever
        // innocent statement came next.
        $probe = $this->probeConnection($driver);

        $this->assertTrue($probe->execute(new Raw('SELECT 1')));
        $this->assertSame(6, (int)$probe->query(new Raw('SELECT 6 AS n'))[0]['n']);

        // Bound and unbound take different branches; both must clean up.
        $this->assertTrue($probe->execute(new Raw('SELECT ?', [1])));
        $this->assertSame(6, (int)$probe->query(new Raw('SELECT 6 AS n'))[0]['n']);
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function writesStillWorkThroughBothExecuteBranches(string $driver): void
    {
        // execute() no longer has an exec() branch. Statements that never
        // return rows — including DDL, which cannot be prepared everywhere —
        // still have to run.
        $probe = $this->probeConnection($driver);

        $this->assertTrue($probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (401)')));
        $this->assertTrue($probe->execute(new Raw('INSERT INTO ' . self::TABLE . ' (n) VALUES (?)', [402])));
        $this->assertTrue($probe->execute(new Raw('UPDATE ' . self::TABLE . ' SET n = 403 WHERE n = 402')));
        $this->assertTrue($probe->execute(new Raw('CREATE TEMPORARY TABLE reconnect_tmp (id INT)')));
        $this->assertTrue($probe->execute(new Raw('DROP TEMPORARY TABLE reconnect_tmp')));

        $this->assertSame(1, $this->rowsIn(self::TABLE . ' WHERE n = 401'));
        $this->assertSame(1, $this->rowsIn(self::TABLE . ' WHERE n = 403'));

        Connection::get('mysql')
            ->execute(new Raw('DELETE FROM ' . self::TABLE . ' WHERE n IN (401, 403)'));
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aSuccessfulPingIsRecordedAsActivity(string $driver): void
    {
        // A ping that does not record the round trip leaves the driver
        // believing it has been idle since whenever, so it pings again before
        // the next statement, and every one after that.
        $probe = $this->probeConnection($driver);

        $clear = new ReflectionMethod($probe, 'clearActivity');
        $clear->setAccessible(true);
        $clear->invoke($probe);

        $needs = new ReflectionMethod($probe, 'needsLivenessCheck');
        $needs->setAccessible(true);
        $this->assertTrue($needs->invoke($probe));

        $this->assertTrue($probe->ping());
        $this->assertFalse($needs->invoke($probe));
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function reconnectingReplacesTheStatementCache(string $driver): void
    {
        // Prepared statements belong to the connection that prepared them, so a
        // cache carried across a reconnect hands back handles to a socket that
        // no longer exists.
        $probe = $this->probeConnection($driver);
        $probe->query(new Raw('SELECT 11 AS n'));

        $this->killFromTheServer($probe);
        $this->assertSame(11, (int)$probe->query(new Raw('SELECT 11 AS n'))[0]['n']);

        if (!property_exists($probe, 'statementCache')) {
            return;
        }

        $cache = new ReflectionProperty($probe, 'statementCache');
        $cache->setAccessible(true);
        $held = $cache->getValue($probe);
        $this->assertIsArray($held);
    }
}
