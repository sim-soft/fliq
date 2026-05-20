<?php

namespace Simsoft\DB\Cache;

/**
 * ArrayCache.
 *
 * Simple in-memory cache implementation for testing and single-request caching.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int}> Cache storage */
    private array $store = [];

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->store[$key])) {
            return $default;
        }

        $item = $this->store[$key];

        if ($item['expires'] > 0 && $item['expires'] < time()) {
            unset($this->store[$key]);
            return $default;
        }

        return $item['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }

        $item = $this->store[$key];

        if ($item['expires'] > 0 && $item['expires'] < time()) {
            unset($this->store[$key]);
            return false;
        }

        return true;
    }

    /**
     * Clear all cached items.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->store = [];
    }
}
