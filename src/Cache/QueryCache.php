<?php

namespace Simsoft\DB\Cache;

/**
 * QueryCache.
 *
 * Static registry for the query cache driver.
 * Set a driver to enable automatic query result caching.
 */
class QueryCache
{
    /** @var CacheInterface|null The active cache driver */
    private static ?CacheInterface $driver = null;

    /**
     * Set the cache driver.
     *
     * @param CacheInterface $driver The cache driver instance.
     * @return void
     */
    public static function setDriver(CacheInterface $driver): void
    {
        self::$driver = $driver;
    }

    /**
     * Get the cache driver.
     *
     * @return CacheInterface|null
     */
    public static function getDriver(): ?CacheInterface
    {
        return self::$driver;
    }

    /**
     * Determine if caching is enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$driver !== null;
    }

    /**
     * Reset the cache driver (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$driver = null;
    }

    /**
     * Generate a cache key from the connection, SQL, and bind values.
     *
     * The connection name is part of the key because the same statement means
     * different things on different databases. Without it, a query against one
     * connection would serve rows cached from another — a data leak between
     * tenants when connections are how they are kept apart.
     *
     * @param string $sql The SQL statement.
     * @param array<int, mixed>|null $binds The bind values.
     * @param string $connection The resolved connection name.
     * @return string
     */
    public static function generateKey(string $sql, ?array $binds, string $connection = ''): string
    {
        return 'query:' . md5($connection . "\0" . $sql . serialize($binds));
    }
}
