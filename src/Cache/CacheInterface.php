<?php

namespace Simsoft\DB\Cache;

/**
 * CacheInterface.
 *
 * Cache interface for query result caching.
 *
 * The four methods below are named and shaped after PSR-16, but this is not
 * PSR-16 and does not claim to be: it omits clear(), getMultiple(),
 * setMultiple() and deleteMultiple(), and it does not throw on invalid keys.
 * Neither direction is interchangeable — a class written against this
 * interface is not a PSR-16 cache, and a PSR-16 cache does not satisfy this
 * interface without an adapter declaring `implements CacheInterface`. Writing
 * that adapter is a few lines, since every method here has a PSR-16
 * counterpart with compatible semantics.
 *
 * Only get() and set() are called by the query cache. delete() and has() are
 * part of the contract for drivers that want them and for callers holding a
 * driver directly; nothing in the framework invalidates cache entries, so a
 * cached result stays served until its TTL runs out.
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
