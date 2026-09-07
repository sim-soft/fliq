<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Drivers\MySQLiDriver;
use Simsoft\DB\Drivers\PDODriver;
use Simsoft\DB\Drivers\PostgresDriver;
use Simsoft\DB\Drivers\SQLiteDriver;
use Simsoft\DB\Interfaces\CachesStatements;

/**
 * What every driver has to offer, checked against the class rather than a server.
 *
 * The drivers are four independent implementations of one contract, and the
 * contract was only ever written down as whatever three of them happened to
 * agree on. `reconnectIfNeeded()` existed as a byte-identical copy in three and
 * was absent from the fourth; `ping()` existed in all four and had drifted
 * apart in three separate ways; the statement-cache controls existed in two.
 *
 * A missing method is not a subtle failure — it is a fatal error in whichever
 * application first calls the documented name on the wrong driver. These
 * assertions are cheap and would have caught every one of those gaps.
 */
class DriverContractTest extends TestCase
{
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
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function everyDriverCanBeAskedToReconnect(string $driver): void
    {
        // SQLite had no reconnectIfNeeded() at all. Calling the documented name
        // on it was a fatal error, and because it is the driver the test suite
        // reaches for, that gap was the easiest of the four to hit. Both live
        // on the base class now, so this is a statement about the hierarchy.
        $this->assertTrue(is_subclass_of($driver, Driver::class));

        $reconnect = new ReflectionMethod($driver, 'reconnectIfNeeded');
        $this->assertTrue($reconnect->isPublic());
    }

    /**
     * The statement-cache controls docs/01-GETTING-STARTED.md documents.
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function cacheControls(): array
    {
        return [
            'enable' => ['enableStatementCache'],
            'disable' => ['disableStatementCache'],
            'clear' => ['clearStatementCache'],
            'setSize' => ['setStatementCacheSize'],
            'isEnabled' => ['isStatementCacheEnabled'],
        ];
    }

    /**
     * Every PDO-backed driver offers the documented cache controls.
     *
     * @param non-empty-string $method The documented method name.
     * @return void
     */
    #[Test]
    #[DataProvider('cacheControls')]
    public function everyCachingDriverOffersTheDocumentedControls(string $method): void
    {
        // SQLite caches statements — it has the array and the eviction — and
        // offered only clearStatementCache() of the five. Naming the set in an
        // interface is what makes the other four a compile-time obligation
        // rather than something each driver is trusted to remember; that the
        // three caching drivers implement it is now checked statically, so
        // what is left to assert is that the interface really promises this.
        $declared = new ReflectionMethod(CachesStatements::class, $method);

        $this->assertTrue($declared->isPublic());

        // And that each driver supplies a body, rather than inheriting a
        // promise nobody keeps.
        foreach ([PDODriver::class, PostgresDriver::class, SQLiteDriver::class] as $driver) {
            $this->assertSame(
                $driver,
                (new ReflectionMethod($driver, $method))->getDeclaringClass()->getName(),
                "$driver does not implement the documented $method()"
            );
        }

        // MySQLi prepares nothing, so promising the controls would be a lie.
        $this->assertFalse(method_exists(MySQLiDriver::class, $method));
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function reconnectingIsInheritedRatherThanCopied(string $driver): void
    {
        // Three copies of one method is how the fourth came to have none, and
        // how ping() drifted three ways. Whatever a driver must not vary, it
        // must not redeclare.
        $method = new ReflectionMethod($driver, 'reconnectIfNeeded');

        $this->assertSame(
            Driver::class,
            $method->getDeclaringClass()->getName(),
            "$driver redeclares reconnectIfNeeded() instead of inheriting it"
        );
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function livenessCheckingIsInheritedRatherThanCopied(string $driver): void
    {
        // ping() is the method that had drifted: one copy forgot markActivity()
        // and so re-pinged forever, and one left its result set unread, which
        // on MySQL made the next statement fail. Only the probe varies.
        $ping = new ReflectionMethod($driver, 'ping');
        $this->assertSame(Driver::class, $ping->getDeclaringClass()->getName());

        $probe = new ReflectionMethod($driver, 'probeLiveness');
        $this->assertSame($driver, $probe->getDeclaringClass()->getName());
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function everyDriverReportsWhetherItHoldsAConnection(string $driver): void
    {
        // The base class needs this to decide about reconnecting, and cannot
        // read the handle itself: it is mysqli on one driver and PDO on three.
        $method = new ReflectionMethod($driver, 'isConnected');

        $this->assertSame($driver, $method->getDeclaringClass()->getName());
        $this->assertTrue($method->isProtected());
    }

    /**
     * @param class-string<Driver> $driver The driver class to inspect.
     * @return void
     */
    #[Test]
    #[DataProvider('drivers')]
    public function everyDriverIsConcrete(string $driver): void
    {
        // Hoisting ping() and reconnectIfNeeded() added two abstract methods to
        // the base class. A driver that missed one would be abstract, and would
        // fail at construction rather than here.
        $this->assertFalse((new ReflectionClass($driver))->isAbstract());
    }

    #[Test]
    public function openingATransactionChecksTheConnectionFirst(): void
    {
        // The whole defect in one assertion. execute() and query() both checked
        // liveness before running and recovered from a connection that had
        // dropped while idle; opening a transaction checked nothing, so the
        // same worker recovered when its next statement was a query and failed
        // when it was a transaction — the case where losing the write matters.
        $source = (string)file_get_contents((string)(new ReflectionClass(Driver::class))->getFileName());

        $start = strpos($source, 'private function enterTransaction');
        $this->assertNotFalse($start);

        $body = substr($source, $start, (int)strpos($source, 'private function leaveTransactionWithCommit') - $start);

        $this->assertStringContainsString('reconnectIfDead', $body);
        $this->assertStringContainsString('beginTransaction', $body);

        // And it must happen before the transaction is opened, not after: once
        // the level is up, guardReconnectDuringTransaction() has to refuse.
        $this->assertLessThan(
            strpos($body, '$this->beginTransaction()'),
            strpos($body, '$this->reconnectIfDead()')
        );
    }
}
