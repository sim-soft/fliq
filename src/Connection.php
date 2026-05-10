<?php

namespace Simsoft\DB\MySQL;

use Exception;
use Simsoft\DB\MySQL\Drivers\PDODriver;
use Simsoft\DB\MySQL\Drivers\Driver;
use Simsoft\DB\MySQL\Drivers\MySQLiDriver;
use Throwable;

/**
 * Connection class
 *
 */
final class Connection
{
    /** @var array Configurations. */
    protected static array $config = [];

    /** @var array Connected databases. */
    private static array $dbs = [];

    /** @var string Default connection name */
    protected static string $defaultConnection = 'mysql';

    /**
     * Load configurations from config file.
     *
     * @param string $configFile Config file path.
     * @return void
     */
    public static function configure(string $configFile): void
    {
        try {
            if (file_exists($configFile)) {
                $databases = require $configFile;
                if (is_array($databases)) {
                    foreach ($databases as $connection => $config) {
                        self::add($connection, $config);
                    }
                }
            }
        } catch (Throwable $throwable) {
            debug_print_backtrace();
            trigger_error($throwable->getMessage());
        }
    }

    /**
     * Add connection config
     *
     * @param string $name Connection name.
     * @param array $config
     * @return void
     */
    public static function add(string $name, array $config): void
    {
        self::$config[$name] = $config;
    }

    /**
     * Get connection.
     *
     * @param string $name Connection name.
     * @return Driver|null
     * @throws Exception
     */
    public static function get(string $name): ?Driver
    {
        if (isset(self::$dbs[$name])) {
            return self::$dbs[$name];
        }

        if ($config = self::$config[$name] ?? false) {
            $connection = match ($config['driver'] ?? self::$defaultConnection) {
                'mysqli' => new MySQLiDriver($config),
                default => new PDODriver($config),
            };

            if ($connection->hasError()) {
                throw new Exception("Connection '$name' failed: " . $connection->getErrors()[0]);
            }

            return self::$dbs[$name] = $connection;
        }

        throw new Exception('Database not found');
    }
}
