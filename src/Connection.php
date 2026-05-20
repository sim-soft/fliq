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
     * @param string $configFile Config file path.
     * @return void
     */
    public static function configure(string $configFile): void
    {
        try {
            if (!file_exists($configFile)) {
                return;
            }

            $databases = require $configFile;
            if (!is_array($databases)) {
                return;
            }

            foreach ($databases as $connection => $config) {
                self::add($connection, $config);
            }
        } catch (Throwable $throwable) {
            trigger_error($throwable->getMessage());
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
        $connection = match ($config['driver'] ?? 'mysql') {
            'pgsql', 'postgres', 'postgresql' => new PostgresDriver($config),
            'sqlite' => new SQLiteDriver($config),
            'mysqli' => new MySQLiDriver($config),
            default => new PDODriver($config),
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
        $driver = $config['driver'] ?? 'mysql';

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
