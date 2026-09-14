<?php

namespace Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Drivers\MySQLiDriver;
use Simsoft\DB\Exceptions\ConnectionException;
use Simsoft\DB\Exceptions\QueryException;

/**
 * The failure paths, against real servers.
 *
 * tests/Query/DriverFailureContractTest.php pins the shape of these fixes
 * against SQLite, which needs no server. What it cannot show is that the four
 * drivers now agree, because the disagreements were between MySQLi, PDO's
 * mysql driver and PDO's pgsql driver, and each was visible only against the
 * server it talks to:
 *
 *   - MySQLi published its handle before real_connect() returned, so a driver
 *     whose connection had failed answered isConnected() true. Every later call
 *     then raised a PHP Error — `mysqli object is not fully initialized`, or
 *     `Property access is not allowed yet` from lastInsertId() — and an Error
 *     is not caught by a caller catching Exception.
 *   - MySQLi's query() read get_result() === false as failure, but that is also
 *     what a statement with no result set returns, so `SET @x = 1` threw
 *     `Failed to get result: ` with an empty reason. The other three answer [].
 *   - PDO's pgsql driver reports one empty row per affected row, so query() on
 *     an UPDATE touching one row answered [[]] — one row, no columns, which
 *     every caller reads as "a row was found".
 *
 * The MySQL half runs against the fixture database. The PostgreSQL half builds
 * and drops its own scratch table, since resources/sample_db_postgres.sql is
 * not reloaded between classes.
 */
class DriverFailureRecoveryTest extends DatabaseTestCase
{
    /** @var bool Whether a PostgreSQL server answered at setup. */
    private static bool $pgAvailable = false;

    /** @var non-empty-string The PostgreSQL scratch table. */
    private const PG_TABLE = 'driver_failure_probe';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add('dfr_pg', [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: '',
            'schema' => 'public',
        ]);

        try {
            $driver = Connection::get('dfr_pg');
            $driver->execute(new Raw('DROP TABLE IF EXISTS ' . self::PG_TABLE));
            $driver->execute(new Raw('CREATE TABLE ' . self::PG_TABLE . ' (id INT PRIMARY KEY, n INT)'));
            self::$pgAvailable = true;
        } catch (\Throwable) {
            self::$pgAvailable = false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pgAvailable && Connection::has('dfr_pg')) {
            try {
                Connection::get('dfr_pg')->execute(new Raw('DROP TABLE IF EXISTS ' . self::PG_TABLE));
            } catch (\Throwable) {
                // The server went away; there is nothing left to clean up.
            }
        }

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        foreach (['dfr_probe', 'dfr_dead'] as $name) {
            if (Connection::has($name)) {
                Connection::remove($name);
            }
        }

        parent::tearDown();
    }

    /**
     * The two MySQL drivers, which must agree with each other.
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
     * Config for a MySQL connection that will not answer.
     *
     * Port 9 is the discard port: reserved, and nothing listens on it.
     *
     * @param non-empty-string $driver The driver key.
     * @return array<string, mixed>
     */
    private function unreachableConfig(string $driver): array
    {
        return [
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => 9,
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
            'timeout' => 1,
        ];
    }

    /**
     * Move a connection to somewhere unreachable, and back again.
     *
     * Only the address changes. The constructor merges each driver's defaults
     * into the config once, and replacing the array wholesale would drop them —
     * losing `charset` makes the reconnect fail on the character set rather than
     * on the thing under test.
     *
     * @param Driver $driver The driver to move.
     * @param array<string, mixed> $overrides The config keys to change.
     * @return void
     */
    private function move(Driver $driver, array $overrides): void
    {
        $config = (new ReflectionProperty(Driver::class, 'config'))->getValue($driver);

        $this->repoint($driver, [...(array)$config, ...$overrides]);
    }

    /**
     * Config for a MySQL connection that will answer.
     *
     * @param non-empty-string $driver The driver key.
     * @return array<string, mixed>
     */
    private function workingConfig(string $driver): array
    {
        return [
            'driver' => $driver,
            'host' => (string)(getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'database' => (string)(getenv('DB_DATABASE') ?: 'sample_db'),
            'username' => (string)(getenv('DB_USERNAME') ?: 'root'),
            'password' => (string)(getenv('DB_PASSWORD') ?: ''),
        ];
    }

    /**
     * Open a working connection under the name "dfr_probe".
     *
     * @param non-empty-string $driver The driver key.
     * @param array<string, mixed> $extra Extra config.
     * @return Driver
     */
    private function probe(string $driver, array $extra = []): Driver
    {
        if (Connection::has('dfr_probe')) {
            Connection::remove('dfr_probe');
        }

        Connection::add('dfr_probe', [...$this->workingConfig($driver), ...$extra]);

        return Connection::get('dfr_probe');
    }

    /**
     * Overwrite a driver's config, as a server that moved would.
     *
     * @param Driver $driver The driver to repoint.
     * @param array<string, mixed> $config The replacement config.
     * @return void
     */
    private function repoint(Driver $driver, array $config): void
    {
        (new ReflectionProperty(Driver::class, 'config'))->setValue($driver, $config);
    }

    /**
     * Call a protected method on a driver.
     *
     * @param Driver $driver The driver to act on.
     * @param non-empty-string $method The method to call.
     * @return mixed
     */
    private function invoke(Driver $driver, string $method): mixed
    {
        return (new ReflectionMethod($driver, $method))->invoke($driver);
    }

    /**
     * Close a driver's connection from the server side.
     *
     * @param Driver $driver The connection to kill.
     * @return void
     */
    private function killFromTheServer(Driver $driver): void
    {
        $id = (int)$driver->query(new Raw('SELECT CONNECTION_ID() AS id'))[0]['id'];

        Connection::get('mysql')->execute(new Raw('KILL ' . $id));

        usleep(200000);
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function anUnreachableServerIsReportedAsAConnectionFailure(string $driver): void
    {
        // MySQLi assigned `new mysqli()` to the handle before real_connect()
        // ran, so on failure the handle stayed and isConnected() answered true.
        // Connection::get() reads hasError(), which was set — the two disagreed,
        // and which one a caller saw depended on how it asked.
        Connection::add('dfr_dead', $this->unreachableConfig($driver));

        $this->expectException(ConnectionException::class);

        Connection::get('dfr_dead');
    }

    #[Test]
    public function aFailedMysqliConnectLeavesNoHandleBehind(): void
    {
        // Directly, because Connection::get() throws before handing the driver
        // back and so hides the state that made every later call fail.
        $driver = new MySQLiDriver($this->unreachableConfig('mysqli'));

        $this->assertTrue($driver->hasError());
        $this->assertFalse(
            (bool)$this->invoke($driver, 'isConnected'),
            'a driver whose connect failed reported itself connected'
        );

        // The two calls that used to raise a PHP Error rather than an Exception.
        // `Error` is not an `Exception`, so a caller catching Exception around
        // its query got no chance to handle it at all.
        $this->assertFalse($driver->lastInsertId());

        $this->expectException(\RuntimeException::class);
        $driver->query(new Raw('SELECT 1'));
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aDriverThatRecoveredSaysSo(string $driver): void
    {
        // Errors accumulated and nothing cleared them, so a driver that dropped
        // its connection and got it back still answered hasError() true, naming
        // a failure that no longer applied. Connection::createDriver() reads
        // exactly that to decide whether a connection is usable.
        $probe = $this->probe($driver, ['ping_idle_seconds' => 0]);

        $this->move($probe, ['host' => '127.0.0.1', 'port' => 9, 'timeout' => 1]);

        try {
            $this->invoke($probe, 'forceReconnect');
            $this->fail('reconnecting to an unreachable server should have thrown');
        } catch (ConnectionException) {
            $this->assertTrue($probe->hasError());
        }

        $this->move($probe, ['port' => (int)(getenv('DB_PORT') ?: 3306)]);
        $this->invoke($probe, 'forceReconnect');

        $this->assertSame(1, (int)$probe->query(new Raw('SELECT 1 AS n'))[0]['n']);
        $this->assertFalse($probe->hasError(), 'a recovered driver still reported the failure it recovered from');
        $this->assertSame([], $probe->getErrors());
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function killingAConnectionStillLeavesNoStaleError(string $driver): void
    {
        // The same thing by the route it actually happens: the server closes
        // the connection, the next statement fails, runWithReconnect() recovers
        // and retries. That worked; what did not was the driver's own account
        // of itself afterwards.
        $probe = $this->probe($driver, ['ping_idle_seconds' => 0]);
        $this->killFromTheServer($probe);

        $this->assertSame(1, (int)$probe->query(new Raw('SELECT 1 AS n'))[0]['n']);
        $this->assertFalse($probe->hasError());
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function reconnectingToAnUnreachableServerRaisesConnectionException(string $driver): void
    {
        // docs/01-GETTING-STARTED.md tells callers a lost connection surfaces as
        // ConnectionException. forceReconnect() returned normally having
        // connected to nothing, so what they actually got was a RuntimeException
        // on PDO and a PHP Error on MySQLi, thrown from the next statement.
        $probe = $this->probe($driver);
        $this->move($probe, ['host' => '127.0.0.1', 'port' => 9, 'timeout' => 1]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('could not be re-established');

        $this->invoke($probe, 'forceReconnect');
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aStatementWithNoResultSetYieldsNoRows(string $driver): void
    {
        // MySQLi's get_result() answers false both for a statement that failed
        // and for one that ran fine and has no rows to give. Reading both as
        // failure made this throw `Failed to get result: ` — with an empty
        // reason, because there was no error to name.
        $probe = $this->probe($driver);

        $this->assertSame([], $probe->query(new Raw('SET @dfr_probe = 1')));
        $this->assertSame(1, (int)$probe->query(new Raw('SELECT @dfr_probe AS n'))[0]['n']);
    }

    /**
     * @param non-empty-string $driver The driver to exercise.
     * @return void
     */
    #[Test]
    #[DataProvider('mysqlDrivers')]
    public function aGenuinelyBadStatementStillFails(string $driver): void
    {
        // The other half of the same discriminator: distinguishing "no result
        // set" from "failed" must not have made every failure look benign.
        $probe = $this->probe($driver);

        try {
            $probe->query(new Raw('SELECT * FROM no_such_table_at_all'));
            $this->fail('querying a missing table should have thrown');
        } catch (\Throwable $throwable) {
            $this->assertStringContainsString('no_such_table_at_all', $throwable->getMessage());
        }

        // And the connection is still usable afterwards.
        $this->assertSame(1, (int)$probe->query(new Raw('SELECT 1 AS n'))[0]['n']);
    }

    #[Test]
    public function silencingPdoErrorsThroughOptionsCannotDisarmTheDriver(): void
    {
        // `options` is documented, so a reader can set ERRMODE_SILENT. That made
        // prepare() return false and the caller catch `TypeError:
        // prepareStatement(): Return value must be of type PDOStatement, false
        // returned` rather than a database error.
        //
        // PDO drivers only: `options` on MySQLi means mysqli's own option
        // constants, and PDO::ATTR_ERRMODE is numerically MYSQLI_INIT_COMMAND,
        // so passing one to the other configures something else entirely.
        $probe = $this->probe('pdo_mysql', ['options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]]);

        $this->assertSame(1, (int)$probe->query(new Raw('SELECT 1 AS n'))[0]['n']);

        try {
            $probe->query(new Raw('SELECT * FROM no_such_table_at_all'));
            $this->fail('a silenced driver still has to report a bad statement');
        } catch (\TypeError $error) {
            $this->fail('the driver was disarmed by options: ' . $error->getMessage());
        } catch (\Throwable $throwable) {
            $this->assertStringContainsString('no_such_table_at_all', $throwable->getMessage());
        }
    }

    #[Test]
    public function aWriteThroughTheFacadeStillReportsItsInsertId(): void
    {
        // Routing MySQLi's lastInsertId() through normalizeInsertId() must not
        // have cost it the id it is there to report.
        $probe = $this->probe('mysqli');

        $probe->execute(new Raw('DROP TABLE IF EXISTS dfr_insert_probe'));
        $probe->execute(new Raw(
            'CREATE TABLE dfr_insert_probe (id INT PRIMARY KEY AUTO_INCREMENT, n INT) ENGINE=InnoDB'
        ));

        try {
            $probe->execute(new Raw('INSERT INTO dfr_insert_probe (n) VALUES (7)'));

            $this->assertSame('1', $probe->lastInsertId());
        } finally {
            $probe->execute(new Raw('DROP TABLE IF EXISTS dfr_insert_probe'));
        }
    }

    #[Test]
    public function postgresQueryOnAnUpdateReturnsNoRows(): void
    {
        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        // PDO's pgsql driver reports one empty row per affected row, so this
        // answered [[]] — a result set of one row with no columns, which every
        // caller reads as "a row was found". The other three drivers answer [].
        $driver = Connection::get('dfr_pg');

        $driver->execute(new Raw('DELETE FROM ' . self::PG_TABLE));
        $driver->execute(new Raw('INSERT INTO ' . self::PG_TABLE . ' (id, n) VALUES (1, 1), (2, 1)'));

        $this->assertSame([], $driver->query(new Raw('UPDATE ' . self::PG_TABLE . ' SET n = 2 WHERE id = 1')));
        $this->assertSame([], $driver->query(new Raw('UPDATE ' . self::PG_TABLE . ' SET n = 3')));
        $this->assertSame([], $driver->query(new Raw('DELETE FROM ' . self::PG_TABLE . ' WHERE id = 2')));
    }

    #[Test]
    public function postgresQueryStillReturnsRealRows(): void
    {
        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        // Discarding a column-less result must not discard a real one, nor the
        // rows a RETURNING clause produces — which is a write that does have
        // columns, and so is the case the fix most had to leave alone.
        $driver = Connection::get('dfr_pg');

        $driver->execute(new Raw('DELETE FROM ' . self::PG_TABLE));
        $driver->execute(new Raw('INSERT INTO ' . self::PG_TABLE . ' (id, n) VALUES (5, 50)'));

        $this->assertSame(
            [['n' => 50]],
            $driver->query(new Raw('SELECT n FROM ' . self::PG_TABLE . ' WHERE id = 5'))
        );

        $this->assertSame(
            [['id' => 5]],
            $driver->query(new Raw('UPDATE ' . self::PG_TABLE . ' SET n = 51 WHERE id = 5 RETURNING id'))
        );

        // An empty result set is still an empty result set.
        $this->assertSame([], $driver->query(new Raw('SELECT n FROM ' . self::PG_TABLE . ' WHERE id = 999')));

        $driver->execute(new Raw('DELETE FROM ' . self::PG_TABLE));
    }

    #[Test]
    public function postgresSilencedOptionsAreStillRefused(): void
    {
        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        Connection::add('dfr_dead', [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: '',
            'schema' => 'public',
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT],
        ]);

        $driver = Connection::get('dfr_dead');

        $this->assertSame(1, (int)$driver->query(new Raw('SELECT 1 AS n'))[0]['n']);

        $this->expectException(\PDOException::class);
        $driver->query(new Raw('SELECT * FROM no_such_table_at_all'));
    }
}
