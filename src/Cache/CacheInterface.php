<?php

namespace Simsoft\DB\Cache;

/**
 * CacheInterface.
 *
 * PSR-16 compatible cache interface for query result caching.
 */
interface CacheInterface
{
    /**
     * Fetch a value from the cache.
     *
     * @param string $key The cache key.
     * @param mixed $default Default value if key not found.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store a value in the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param int $ttl Time-to-live in seconds. 0 means no expiration.
     * @return bool
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * Delete a value from the cache.
     *
     * @param string $key The cache key.
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key The cache key.
     * @return bool
     */
    public function has(string $key): bool;
}
