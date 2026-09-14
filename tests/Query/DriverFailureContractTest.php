<?php

namespace Query;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Drivers\MySQLiDriver;
use Simsoft\DB\Drivers\PDODriver;
use Simsoft\DB\Drivers\PostgresDriver;
use Simsoft\DB\Drivers\SQLiteDriver;
use Simsoft\DB\Exceptions\ConnectionException;

/**
 * What a driver owes its caller when something goes wrong.
 *
 * The four drivers agree on the happy path and had drifted apart on every
 * failure path, each in a way that turned a plain fact — "the server is
 * unreachable", "nothing was inserted", "that statement has no rows" — into an
 * exception of the wrong type thrown from somewhere else entirely.
 *
 * SQLite needs no server, so the connection-failure behaviour is exercised for
 * real here: a database file in a directory that does not exist fails to open
 * exactly the way an unreachable host does. The parts that need MySQL or
 * PostgreSQL are in tests/Integration/DriverFailureRecoveryTest.php.
 */
class DriverFailureContractTest extends TestCase
{
    /** @var array<int, string> Files to remove once the test has finished. */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->scratch = [];

        parent::tearDown();
    }

    /**
     * A writable path for a throwaway SQLite database.
     *
     * @return string
     */
    private function scratchDatabase(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fliq_driver_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->scratch[] = $path;

        return $path;
    }

    /**
     * A path that cannot be opened, because its directory does not exist.
     *
     * @return string
     */
    private function unopenableDatabase(): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'fliq_absent_' . bin2hex(random_bytes(6))
            . DIRECTORY_SEPARATOR . 'db.sqlite';
    }

    /**
     * Overwrite a driver's config, as a moved server or a lost file would.
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
     * The body of one method, as written.
     *
     * @param class-string<Driver> $driver The driver class.
     * @param non-empty-string $method The method to extract.
     * @return string
     */
    private function bodyOf(string $driver, string $method): string
    {
        $reflected = new ReflectionMethod($driver, $method);
        $file = (string)$reflected->getFileName();
        $lines = (array)file($file);

        return implode('', array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1
        ));
    }

    /**
     * The four driver classes.
     *
     * @return array<string, array{0: class-string<Driver>}>
     */
    public static function drivers(): array
    {
        return [
            'mysqli' => [MySQLiDriver::class],
            'pdo' => [PDODriver::class],
            'postgres' => [PostgresDriver::class],
            'sqlite' => [SQLiteDriver::class],
        ];
    }

    /**
     * The three PDO-backed drivers, which share the `options` config key.
     *
     * @return array<string, array{0: class-string<Driver>}>
     */
    public static function pdoDrivers(): array
    {
        return [
            'pdo' => [PDODriver::class],
            'postgres' => [PostgresDriver::class],
            'sqlite' => [SQLiteDriver::class],
        ];
    }

    #[Test]
    public function errorsCanBeDiscarded(): void
    {
        // Errors only ever accumulated. Nothing could retract one, so a driver
        // that failed once and then worked still answered hasError() true.
        $driver = new SQLiteDriver(['database' => ':memory:']);

        $driver->addError('something went wrong');
        $this->assertTrue($driver->hasError());

        $driver->clearErrors();

        $this->assertFalse($driver->hasError());
        $this->assertSame([], $driver->getErrors());
    }

    #[Test]
    public function aDriverThatFailedAndThenConnectedReportsNoError(): void
    {
        // The reason clearing matters: Connection::createDriver() reads
        // hasError() and raises ConnectionException from it, so a stale message
        // is not cosmetic — it condemns a connection that is working.
        $driver = new SQLiteDriver(['database' => $this->unopenableDatabase()]);

        $this->assertTrue($driver->hasError(), 'opening a database in a missing directory should fail');
        $this->assertNull($driver->getPdo());

        $this->repoint($driver, ['database' => ':memory:', 'statement_cache' => true, 'statement_cache_size' => 100]);
        $this->invoke($driver, 'connect');

        $this->assertNotNull($driver->getPdo());
        $this->assertFalse($driver->hasError(), 'a connected driver still reported the failure that preceded it');
        $this->assertSame([], $driver->getErrors());
    }

    #[Test]
    public function aFailedReconnectRaisesConnectionException(): void
    {
        // connect() records its failure rather than throwing, because the
        // constructor path wants to collect it. Nothing read that on the
        // reconnect path, so forceReconnect() returned normally having
        // established nothing, and the caller carried on with a driver holding
        // no connection — the failure then surfaced from whatever statement
        // came next, as a RuntimeException naming neither the host nor the
        // reason.
        $driver = new SQLiteDriver(['database' => $this->scratchDatabase()]);
        $this->assertFalse($driver->hasError());

        $this->repoint($driver, ['database' => $this->unopenableDatabase()]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('could not be re-established');

        $this->invoke($driver, 'forceReconnect');
    }

    #[Test]
    public function aFailedReconnectNamesTheUnderlyingReason(): void
    {
        $driver = new SQLiteDriver(['database' => $this->scratchDatabase()]);
        $this->repoint($driver, ['database' => $this->unopenableDatabase()]);

        try {
            $this->invoke($driver, 'forceReconnect');
            $this->fail('forceReconnect() should have raised ConnectionException');
        } catch (ConnectionException $exception) {
            // Two frames removed from the cause is what made the old failure
            // hard to act on. The driver knows why it could not open the
            // database; it has to say so.
            $this->assertStringContainsString('unable to open database', strtolower($exception->getMessage()));
        }
    }

    #[Test]
    public function silencingPdoErrorsThroughOptionsIsRefused(): void
    {
        // `options` is documented as overriding the defaults, and every default
        // may be overridden except this one. The drivers are written for the
        // exception form throughout — prepare() is typed to return a statement
        // — so ERRMODE_SILENT does not make the library quieter, it makes
        // prepare() return false and the caller catch a TypeError instead of a
        // database error.
        $driver = new SQLiteDriver([
            'database' => ':memory:',
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT],
        ]);

        $pdo = $driver->getPdo();
        $this->assertNotNull($pdo);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    #[Test]
    public function aSilencedDriverStillReportsABadStatement(): void
    {
        $driver = new SQLiteDriver([
            'database' => ':memory:',
            'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING],
        ]);

        $this->expectException(\PDOException::class);

        $driver->query(new Raw('SELECT * FROM no_such_table'));
    }

    #[Test]
    public function everyOtherOptionIsStillHonoured(): void
    {
        // Pinning one attribute must not quietly discard the rest of the key.
        $driver = new SQLiteDriver([
            'database' => ':memory:',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_CASE => PDO::CASE_UPPER,
            ],
        ]);

        $pdo = $driver->getPdo();
        $this->assertNotNull($pdo);
        $this->assertSame(PDO::CASE_UPPER, $pdo->getAttribute(PDO::ATTR_CASE));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('pdoDrivers')]
    public function everyPdoDriverPinsTheErrorModeThroughOneMerge(string $driver): void
    {
        // Three drivers, three copies of the same array_replace() — which is
        // how one of them could have been fixed and the others left silent.
        $connect = $this->bodyOf($driver, 'connect');

        $this->assertStringContainsString('mergePdoOptions', $connect);
        $this->assertStringNotContainsString('array_replace', $connect);
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function everyDriverClearsStaleStateBeforeConnecting(string $driver): void
    {
        // A new connection inherits no transaction, no recorded activity and no
        // previous failure. Each driver used to reset some subset of the three.
        $this->assertStringContainsString('prepareConnect', $this->bodyOf($driver, 'connect'));
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function everyDriverChecksThatItsReconnectWorked(string $driver): void
    {
        $this->assertStringContainsString('assertReconnected', $this->bodyOf($driver, 'forceReconnect'));
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function everyDriverNormalisesTheInsertId(string $driver): void
    {
        // Execute::getLastInsertId() is typed ?string, maps only false to null,
        // and does not catch — so a driver that throws here throws through it.
        // Three drivers routed through normalizeInsertId() and MySQLi did not.
        $this->assertStringContainsString('normalizeInsertId', $this->bodyOf($driver, 'lastInsertId'));
    }

    #[Test]
    public function anUnconnectedDriverHasNoInsertIdRatherThanAnException(): void
    {
        $driver = new SQLiteDriver(['database' => ':memory:']);
        (new ReflectionProperty(SQLiteDriver::class, 'connection'))->setValue($driver, null);

        $this->assertFalse($driver->lastInsertId());
    }

    #[Test]
    public function theInMemoryReconnectRefusalStillComesFirst(): void
    {
        // An in-memory database exists only inside its connection, so reopening
        // one hands back an empty database rather than the caller's data.
        // assertReconnected() must not have turned that into a generic failure.
        $driver = new SQLiteDriver(['database' => ':memory:']);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('cannot be recovered');

        $this->invoke($driver, 'forceReconnect');
    }

    #[Test]
    public function aDriverRefusesToBeSerialized(): void
    {
        // There was a __wakeup() promising to reconnect on deserialization, and
        // it could only ever run for one driver in four: PDO refuses to
        // serialize, so the three PDO-backed drivers died with `Serialization
        // of 'PDO' is not allowed` — naming a class the caller never mentioned,
        // from a driver advertising the opposite. Only MySQLi round tripped,
        // and what it wrote out was the host, the username and the password in
        // plaintext.
        $driver = new SQLiteDriver(['database' => ':memory:']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be serialized');

        serialize($driver);
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function noDriverOffersToReconnectOnWakeup(string $driver): void
    {
        // The refusal has to be uniform, or it is just a fourth way for the
        // drivers to disagree about failure.
        $this->assertFalse(
            method_exists($driver, '__wakeup'),
            "$driver still promises to reconnect on deserialization"
        );

        $this->assertSame(
            Driver::class,
            (new ReflectionMethod($driver, '__serialize'))->getDeclaringClass()->getName()
        );
    }

    #[Test]
    public function aRefusedSerializationLeaksNoCredentials(): void
    {
        // The reason it is a refusal rather than a __sleep() that drops the
        // handle: the config is the other half of what a driver holds, and it
        // is the half that must not be written anywhere.
        $driver = new SQLiteDriver(['database' => ':memory:', 'password' => 'hunter2']);

        try {
            $blob = serialize($driver);
            $this->fail('serializing a driver should have been refused, got ' . strlen($blob) . ' bytes');
        } catch (\LogicException $exception) {
            $this->assertStringNotContainsString('hunter2', $exception->getMessage());
        }
    }

    #[Test]
    public function refusingSerializationLeavesTheDriverUsable(): void
    {
        $driver = new SQLiteDriver(['database' => ':memory:']);

        try {
            serialize($driver);
        } catch (\LogicException) {
            // Expected.
        }

        $this->assertSame([['x' => 1]], $driver->query(new Raw('SELECT 1 AS x')));
    }

    #[Test]
    public function theBaseClassDeclaresTheSharedFailureHelpers(): void
    {
        // The point of hoisting them: four copies is how the drivers drifted in
        // the first place, and a helper on the base class cannot be forgotten
        // by one driver only.
        foreach (['prepareConnect', 'assertReconnected', 'mergePdoOptions', 'normalizeInsertId'] as $method) {
            $this->assertSame(
                Driver::class,
                (new ReflectionMethod(Driver::class, $method))->getDeclaringClass()->getName(),
                "$method() should live on the base class"
            );
        }

        $this->assertFalse((new ReflectionClass(Driver::class))->hasMethod('connectAndPray'));
    }
}
