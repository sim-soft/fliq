<?php

declare(strict_types=1);

namespace Simsoft\DB\Interfaces;

/**
 * A driver that reuses prepared statements.
 *
 * The controls documented in docs/01-GETTING-STARTED.md. They were implemented
 * on three drivers and named on none, so `Connection::get()` handed back a
 * `Driver` that had no such methods as far as the type system was concerned,
 * and calling a documented one was checked by nothing until it ran.
 *
 * MySQLi does not implement this: it prepares nothing and caches nothing.
 */
interface CachesStatements
{
    /**
     * Discard every cached statement, leaving caching on.
     *
     * @return void
     */
    public function clearStatementCache(): void;

    /**
     * Resume caching prepared statements.
     *
     * @return void
     */
    public function enableStatementCache(): void;

    /**
     * Stop caching prepared statements and discard the ones held.
     *
     * @return void
     */
    public function disableStatementCache(): void;

    /**
     * Whether prepared statements are currently being cached.
     *
     * @return bool
     */
    public function isStatementCacheEnabled(): bool;

    /**
     * Set how many statements may be held before the oldest is evicted.
     *
     * @param int $size Maximum number of cached statements.
     * @return void
     */
    public function setStatementCacheSize(int $size): void;
}
