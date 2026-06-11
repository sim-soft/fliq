<?php

namespace Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Connection;

/**
 * Base class for integration tests that require a real database.
 *
 * Recreates the sample_db schema from resources/sample_db.sql before each test class.
 *
 * Set environment variables to configure:
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static bool $dbAvailable = false;

    /** @var PDO|null Raw PDO for schema setup (bypasses ORM) */
    private static ?PDO $setupPdo = null;

    public static function setUpBeforeClass(): void
    {
        $host = self::env('DB_HOST', '127.0.0.1');
        $port = self::env('DB_PORT', '3306');
        $database = self::env('DB_DATABASE', 'sample_db');
        $username = self::env('DB_USERNAME', 'root');
        $password = self::env('DB_PASSWORD', '');

        // Create raw PDO to run schema SQL (without specifying database)
        try {
            self::$setupPdo = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            self::loadSchema();
        } catch (\Throwable) {
            static::$dbAvailable = false;
            return;
        }

        // Register ORM connection
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        // Also register as 'test' for backward compatibility with older tests
        Connection::add('test', [
            'driver' => 'mysqli',
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        try {
            Connection::get('mysql');
            static::$dbAvailable = true;
        } catch (\Throwable) {
            static::$dbAvailable = false;
        }
    }

    /**
     * Load the sample_db.sql schema file to recreate the database.
     *
     * @return void
     */
    private static function loadSchema(): void
    {
        $sqlFile = dirname(__DIR__, 2) . '/resources/sample_db.sql';

        if (!file_exists($sqlFile)) {
            throw new \RuntimeException("Schema file not found: {$sqlFile}");
        }

        $sql = file_get_contents($sqlFile);
        if (self::$setupPdo !== null && $sql !== false) {
            self::$setupPdo->exec($sql);
        }
    }

    /**
     * Get environment variable with fallback.
     *
     * @param string $key The variable name.
     * @param string $default Fallback value.
     * @return string
     */
    private static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    protected function setUp(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available. Check DB_HOST, DB_DATABASE, DB_USERNAME env vars.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$setupPdo = null;
        Connection::reset();
    }
}
