<?php

namespace Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Interfaces\CachesStatements;

/**
 * Whether the statement cache obeys the config it is given.
 *
 * `statement_cache` and `statement_cache_size` are documented for the PDO
 * drivers, and SQLite is one — it prepares statements and keeps them in exactly
 * the same array with exactly the same eviction. It read neither key. A config
 * setting them was accepted in full and obeyed in none of it: caching stayed on
 * after being switched off, and the cache grew past the ceiling that was asked
 * for, which is the opposite of what someone disabling it for a bulk import
 * wants.
 *
 * Nothing here needs a server: SQLite runs in memory, and the MySQL connection
 * is only built, never used, so these run wherever the suite does.
 */
class StatementCacheConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * The PDO-backed drivers, which are the ones that cache.
     *
     * MySQLi is absent because it prepares nothing.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cachingDrivers(): array
    {
        return [
            'sqlite' => [['driver' => 'sqlite', 'database' => ':memory:']],
            'pdo' => [[
                'driver' => 'pdo_mysql',
                'host' => (string)(getenv('DB_HOST') ?: '127.0.0.1'),
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'database' => (string)(getenv('DB_DATABASE') ?: 'sample_db'),
                'username' => (string)(getenv('DB_USERNAME') ?: 'root'),
                'password' => (string)(getenv('DB_PASSWORD') ?: ''),
            ]],
        ];
    }

    /**
     * Build a connection and hand back its driver.
     *
     * The return type is the pair, because these tests need both halves: the
     * cache controls, which only CachesStatements names, and query(), which
     * only Driver does. Narrowing to the interface here is also the assertion
     * that a caching driver really does declare itself one.
     *
     * @param array<string, mixed> $config The connection config.
     * @return Driver&CachesStatements
     */
    private function driverFor(array $config): Driver&CachesStatements
    {
        Connection::reset();
        Connection::add('cache', $config);

        $driver = Connection::get('cache');
        $this->assertInstanceOf(CachesStatements::class, $driver);

        return $driver;
    }

    /**
     * How many statements a driver is holding.
     *
     * @param Driver $driver The driver to inspect.
     * @return int
     */
    private function cachedCount(Driver $driver): int
    {
        $cache = new ReflectionProperty($driver, 'statementCache');
        $cache->setAccessible(true);

        $held = $cache->getValue($driver);
        $this->assertIsArray($held);

        return count($held);
    }

    /**
     * Run a handful of distinct statements, so the cache has something to hold.
     *
     * @param Driver $driver The driver to exercise.
     * @param int<1, max> $count How many distinct statements to run.
     * @return void
     */
    private function runDistinctStatements(Driver $driver, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $driver->query(new Raw("SELECT $i AS n"));
        }
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function cachingIsOnByDefault(array $config): void
    {
        $driver = $this->driverFor($config);

        $this->runDistinctStatements($driver, 3);

        $this->assertSame(3, $this->cachedCount($driver));
        $this->assertTrue($driver->isStatementCacheEnabled());
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function statementCacheFalseActuallyDisablesIt(array $config): void
    {
        // SQLite cached three statements here while being told not to cache.
        $driver = $this->driverFor([...$config, 'statement_cache' => false]);

        $this->runDistinctStatements($driver, 3);

        $this->assertSame(0, $this->cachedCount($driver));
        $this->assertFalse($driver->isStatementCacheEnabled());
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function statementCacheSizeBoundsTheCache(array $config): void
    {
        // SQLite kept all eight against a ceiling of three, because it never
        // read the key and its own default of 100 stood.
        $driver = $this->driverFor([...$config, 'statement_cache_size' => 3]);

        $this->runDistinctStatements($driver, 8);

        $this->assertLessThanOrEqual(3, $this->cachedCount($driver));
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function disablingAtRuntimeClearsAndStopsCaching(array $config): void
    {
        $driver = $this->driverFor($config);

        $this->runDistinctStatements($driver, 3);
        $this->assertSame(3, $this->cachedCount($driver));

        $driver->disableStatementCache();

        $this->assertFalse($driver->isStatementCacheEnabled());
        $this->assertSame(0, $this->cachedCount($driver));

        $this->runDistinctStatements($driver, 3);
        $this->assertSame(0, $this->cachedCount($driver));
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function reEnablingResumesCaching(array $config): void
    {
        $driver = $this->driverFor($config);

        $driver->disableStatementCache();
        $driver->enableStatementCache();

        $this->assertTrue($driver->isStatementCacheEnabled());

        $this->runDistinctStatements($driver, 2);
        $this->assertSame(2, $this->cachedCount($driver));
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function clearingFlushesWithoutDisabling(array $config): void
    {
        $driver = $this->driverFor($config);

        $this->runDistinctStatements($driver, 3);
        $driver->clearStatementCache();

        $this->assertSame(0, $this->cachedCount($driver));
        $this->assertTrue($driver->isStatementCacheEnabled());

        $this->runDistinctStatements($driver, 2);
        $this->assertSame(2, $this->cachedCount($driver));
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function settingTheSizeAtRuntimeBoundsTheCache(array $config): void
    {
        $driver = $this->driverFor($config);

        $driver->setStatementCacheSize(2);
        $this->runDistinctStatements($driver, 6);

        $this->assertLessThanOrEqual(2, $this->cachedCount($driver));
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function repeatingAStatementReusesOneCacheEntry(array $config): void
    {
        $driver = $this->driverFor($config);

        for ($i = 0; $i < 5; ++$i) {
            $driver->query(new Raw('SELECT 1 AS n'));
        }

        $this->assertSame(1, $this->cachedCount($driver));
    }

    /**
     * @param array<string, mixed> $config The connection config.
     * @return void
     */
    #[Test]
    #[DataProvider('cachingDrivers')]
    public function resultsAreCorrectWhicheverWayTheCacheIsSet(array $config): void
    {
        // Whatever the cache does, it is an optimisation: the rows must not
        // depend on it. A cached statement re-executed without being reset
        // would be the way this goes wrong.
        foreach ([true, false] as $enabled) {
            $driver = $this->driverFor([...$config, 'statement_cache' => $enabled]);

            for ($i = 0; $i < 3; ++$i) {
                $this->assertSame(41, (int)$driver->query(new Raw('SELECT 41 AS n'))[0]['n']);
                $this->assertSame(42, (int)$driver->query(new Raw('SELECT 42 AS n'))[0]['n']);
            }
        }
    }
}
