<?php

namespace Simsoft\DB;

use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Drivers\MySQLiDriver;
use Simsoft\DB\Drivers\PDODriver;
use Simsoft\DB\Drivers\PostgresDriver;
use Simsoft\DB\Drivers\SQLiteDriver;
use Simsoft\DB\Exceptions\ConnectionException;
use Simsoft\DB\Grammar\Grammar;
use Simsoft\DB\Grammar\MySQLGrammar;
use Simsoft\DB\Grammar\PostgresGrammar;
use Simsoft\DB\Grammar\SQLiteGrammar;
use Throwable;

/**
 * Connection class.
 *
 * Static registry for named database connections.
 */
final class Connection
{
    /** @var array<string, array<string, mixed>> Configurations. */
    protected static array $config = [];

    /** @var array<string, Driver> Connected databases. */
    private static array $dbs = [];

    /** @var string Default connection name */
    protected static string $defaultConnection = 'mysql';

    /**
     * Load configurations from a config file.
     *
     * The file must return an array mapping connection names to configuration
     * arrays. Nothing here throws: a bootstrap that cannot read its config is
     * reported through trigger_error() and the application is left to fail on
     * the first Connection::get() instead. Every failure is announced, though,
     * because the alternative — returning in silence — turns a typo in the path
     * into a "connection not found" thrown much later from somewhere unrelated.
     *
     * A malformed entry is skipped rather than abandoning the file, so one bad
     * connection cannot take the connections declared after it down with it.
     *
     * @param string $configFile Config file path.
     * @return void
     */
    public static function configure(string $configFile): void
    {
        if (!is_file($configFile)) {
            trigger_error("Connection config file not found: $configFile", E_USER_WARNING);
            return;
        }

        try {
            $databases = require $configFile;
        } catch (Throwable $throwable) {
            trigger_error(
                "Connection config file '$configFile' failed to load: " . $throwable->getMessage(),
                E_USER_WARNING
            );
            return;
        }

        if (!is_array($databases)) {
            trigger_error(
                "Connection config file '$configFile' must return an array, "
                . get_debug_type($databases) . ' returned.',
                E_USER_WARNING
            );
            return;
        }

        self::addAll($configFile, $databases);
    }

    /**
     * Register every well-formed entry of a loaded config file.
     *
     * @param string $configFile The file the entries came from, for messages.
     * @param array<array-key, mixed> $databases Connection name to configuration.
     * @return void
     */
    private static function addAll(string $configFile, array $databases): void
    {
        foreach ($databases as $connection => $config) {
            if (!is_array($config)) {
                // Skipped, not fatal. Passing this straight to add() raised a
                // TypeError that escaped the loop, so a single fat-fingered
                // entry silently discarded every connection declared below it
                // and the application booted half-configured.
                trigger_error(
                    "Connection '$connection' in '$configFile' must be an array, "
                    . get_debug_type($config) . ' given. Skipped.',
                    E_USER_WARNING
                );
                continue;
            }

            /** @var array<string, mixed> $config */
            self::add((string)$connection, $config);
        }
    }

    /**
     * Add connection config.
     *
     * @param string $name Connection name.
     * @param array<string, mixed> $config Connection configuration.
     * @return void
     */
    public static function add(string $name, array $config): void
    {
        self::$config[$name] = $config;
    }

    /**
     * Determine if a connection config exists.
     *
     * @param string $name Connection name.
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$config[$name]);
    }

    /**
     * Remove a connection (closes it if active).
     *
     * @param string $name Connection name.
     * @return void
     */
    public static function remove(string $name): void
    {
        unset(self::$dbs[$name], self::$dbs[$name . ':read'], self::$dbs[$name . ':write'], self::$config[$name]);
    }

    /**
     * Get a connection driver.
     *
     * @param string $name Connection name.
     * @param string $type Connection type: 'read' or 'write'. Default: 'write'.
     * @return Driver
     * @throws ConnectionException
     */
    public static function get(string $name, string $type = 'write'): Driver
    {
        $config = self::$config[$name] ?? null;
        if ($config === null) {
            throw ConnectionException::notFound($name);
        }

        // No splitting configured — use base connection for both
        if (!isset($config['read']) && !isset($config['write'])) {
            return self::$dbs[$name] ??= self::createDriver($name, $config);
        }

        return self::getTypedDriver($name, $type, $config);
    }

    /**
     * Get a typed (read/write) driver for split configurations.
     *
     * @param string $name Connection name.
     * @param string $type Connection type: 'read' or 'write'.
     * @param array<string, mixed> $config The base configuration.
     * @return Driver
     * @throws ConnectionException
     */
    private static function getTypedDriver(string $name, string $type, array $config): Driver
    {
        $key = $name . ':' . $type;

        if (isset(self::$dbs[$key])) {
            return self::$dbs[$key];
        }

        if (isset($config[$type]) && is_array($config[$type])) {
            /** @var array<string, mixed> $overrides */
            $overrides = $config[$type];
            $mergedConfig = array_merge($config, $overrides);
            unset($mergedConfig['read'], $mergedConfig['write']);
            return self::$dbs[$key] = self::createDriver($name, $mergedConfig);
        }

        // No specific override for this type, use base config
        $baseConfig = $config;
        unset($baseConfig['read'], $baseConfig['write']);
        return self::$dbs[$key] = self::$dbs[$name] ??= self::createDriver($name, $baseConfig);
    }

    /**
     * Create a driver instance from configuration.
     *
     * @param string $name Connection name (for error messages).
     * @param array<string, mixed> $config The connection configuration.
     * @return Driver
     * @throws ConnectionException
     */
    private static function createDriver(string $name, array $config): Driver
    {
        $connection = match ($config['driver'] ?? 'mysqli') {
            'pgsql', 'postgres', 'postgresql' => new PostgresDriver($config),
            'sqlite' => new SQLiteDriver($config),
            'pdo_mysql' => new PDODriver($config),
            default => new MySQLiDriver($config),
        };

        if ($connection->hasError()) {
            throw ConnectionException::failed($name, $connection->getErrors()[0]);
        }

        return $connection;
    }

    /**
     * Get the grammar for a connection.
     *
     * @param string|null $name Connection name. Null for default.
     * @return Grammar
     */
    public static function grammar(?string $name = null): Grammar
    {
        $name = $name ?? self::$defaultConnection;
        $config = self::$config[$name] ?? [];
        $driver = $config['driver'] ?? 'mysqli';

        return match ($driver) {
            'pgsql', 'postgres', 'postgresql' => new PostgresGrammar(),
            'sqlite' => new SQLiteGrammar(),
            default => new MySQLGrammar(),
        };
    }

    /**
     * Get the default connection name.
     *
     * @return string
     */
    public static function getDefaultName(): string
    {
        return self::$defaultConnection;
    }

    /**
     * Set the default connection name.
     *
     * @param string $name The default connection name.
     * @return void
     */
    public static function setDefault(string $name): void
    {
        self::$defaultConnection = $name;
    }

    /**
     * Reset all connections and configurations.
     *
     * Useful for testing or long-running processes.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$dbs = [];
        self::$config = [];
        self::$defaultConnection = 'mysql';
    }

    /**
     * Disconnect a specific connection (keep config).
     *
     * @param string $name Connection name.
     * @return void
     */
    public static function disconnect(string $name): void
    {
        unset(self::$dbs[$name], self::$dbs[$name . ':read'], self::$dbs[$name . ':write']);
    }

    /**
     * Reconnect a specific connection.
     *
     * @param string $name Connection name.
     * @return Driver
     * @throws ConnectionException
     */
    public static function reconnect(string $name): Driver
    {
        self::disconnect($name);
        return self::get($name);
    }
}
