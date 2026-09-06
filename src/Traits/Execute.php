<?php

namespace Simsoft\DB\Traits;

use InvalidArgumentException;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Cache\CacheInterface;
use Simsoft\DB\Cache\QueryCache;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\Driver;
use Simsoft\DB\Exceptions\ConnectionException;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\QueryLogger;
use Simsoft\DB\QueryMonitor;
use Throwable;

/**
 * Execute trait.
 *
 * Provides query execution capabilities to builder classes.
 */
trait Execute
{
    /** @var null|string The database connection name */
    protected ?string $connection = null;

    /**
     * Set database connection.
     *
     * @param string|null $connect The database connection name.
     * @return static
     */
    public function withConnection(?string $connect = null): static
    {
        $this->connection = $connect;

        // Invalidate cached grammar when connection changes
        if (property_exists($this, 'grammar')) {
            $this->grammar = null;
        }

        return $this;
    }

    /**
     * Alias for withConnection().
     *
     * @param string $connection The database connection name.
     * @return static
     */
    public function on(string $connection): static
    {
        return $this->withConnection($connection);
    }

    /**
     * Dump the SQL and binds to output (does not stop execution).
     *
     * Outputs the full SQL with bind values interpolated for readability.
     *
     * @return static
     */
    public function dump(): static
    {
        // The grammar renders the literals, so this reads the same for every
        // builder. Raw has no Qualifier and so fell to a two-line "Binds: [...]"
        // form instead — which is not what the documented `dd()` output shows,
        // and cannot be pasted into a client, though that is the whole point of
        // dumping it.
        $sql = $this->getSQL();
        $binds = $this->getBinds();

        echo ($binds === null
            ? $sql
            : Connection::grammar($this->connection)->readableSQL($sql, $binds)) . PHP_EOL;

        return $this;
    }

    /**
     * Dump the SQL and binds, then stop execution (dump and die).
     *
     * @return never
     */
    public function dd(): never
    {
        $this->dump();
        exit(1); // @phpcs:ignore -- intentional exit for debug method
    }

    /**
     * Get the EXPLAIN output for this query.
     *
     * Returns the database's query plan as an array of rows.
     * Supports EXPLAIN ANALYZE for actual execution statistics.
     *
     * Each engine names its own formats and spells the request differently, so
     * the prefix is built by the grammar for the connection this query runs on.
     * A format the engine cannot produce is rejected rather than dropped: MySQL
     * used to ignore $format outright and hand back a traditional plan for
     * `format: 'json'`, and PostgreSQL emitted `EXPLAIN ANALYZE (FORMAT JSON)`,
     * which the server refuses to parse.
     *
     * @param bool $analyze Whether to use EXPLAIN ANALYZE (actually executes the query).
     * @param string $format Output format. MySQL: 'text', 'traditional', 'json', 'tree'
     *                       ('text' or 'tree' with $analyze). PostgreSQL: 'text',
     *                       'json', 'yaml', 'xml'. SQLite: 'text' only. Default: 'text'.
     * @return array<int, array<string, mixed>> The explain output rows.
     * @throws InvalidArgumentException If the engine cannot produce this combination.
     */
    public function explain(bool $analyze = false, string $format = 'text'): array
    {
        $explainPrefix = Connection::grammar($this->connection)->explainSQL($analyze, $format);

        // Built after the prefix so an unsupported format costs nothing, and
        // after getSQL() so the clause binds it populates are collected.
        $sql = $this->getSQL();
        $binds = $this->getBinds();

        $raw = new Raw("$explainPrefix $sql", $binds);
        $raw->withConnection($this->connection);

        return $raw->fetchAll();
    }

    /**
     * Get the connection name.
     *
     * @return string|null
     */
    public function getConnectionName(): ?string
    {
        return $this->connection;
    }

    /**
     * Get connection's driver.
     *
     * @param string $type Connection type: 'read' or 'write'. Default: 'write'.
     * @return Driver
     * @throws ConnectionException
     */
    protected function getDriver(string $type = 'write'): Driver
    {
        return Connection::get($this->resolveConnectionName(), $type);
    }

    /**
     * Resolve the connection name, falling back to the default.
     *
     * Always returns a concrete name so that an unset connection and an
     * explicitly named default resolve to the same value.
     *
     * @return string
     */
    protected function resolveConnectionName(): string
    {
        return $this->connection ?? Connection::getDefaultName();
    }

    /**
     * Execute SQL statement (INSERT, UPDATE, DELETE).
     *
     * Routes to the write connection when read/write splitting is configured.
     *
     * @param Executable|null $query The SQL query object.
     * @return bool
     * @throws QueryException
     */
    public function execute(?Executable $query = null): bool
    {
        // The query used to be cloned before running, so anything the driver
        // wrote back onto it — a RETURNING result, on PostgreSQL and SQLite —
        // landed on the copy and was discarded with it. The caller's own
        // builder reported no returned row and no last insert id, for a
        // statement that had run and returned one.
        $target = $query ?? $this;

        try {
            if (QueryMonitor::isEnabled()) {
                QueryMonitor::recordQuery($target->getSQL());
            }

            $startTime = QueryLogger::isEnabled() ? microtime(true) : 0.0;
            $result = $this->getDriver('write')->execute($target);

            if (QueryLogger::isEnabled()) {
                QueryLogger::logQuery($target->getSQL(), $target->getBinds(), $startTime);
            }

            return $result;
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw QueryException::fromQuery($exception->getMessage(), $target);
        }
    }

    /**
     * Get last insert Id.
     *
     * @return string|null
     */
    public function getLastInsertId(): ?string
    {
        // Check if the builder has a RETURNING result (PostgreSQL/SQLite)
        if ($this instanceof Insert && $this->hasReturning()) {
            $rows = $this->getReturningResult();
            if (!empty($rows) && is_array($rows[0])) {
                $firstValue = reset($rows[0]);
                return $firstValue !== null ? (string)$firstValue : null;
            }
        }

        $result = $this->getDriver('write')->lastInsertId();

        return $result === false ? null : $result;
    }

    /**
     * Execute SQL query and return results (SELECT).
     *
     * When caching is enabled (cacheTtl > 0 and QueryCache driver is set),
     * results are stored and retrieved from cache.
     *
     * @param Executable|null $query The SQL query object.
     * @return array<int, array<string, mixed>>
     * @throws QueryException
     */
    public function query(?Executable $query = null): array
    {
        $target = $query ?? $this;

        $cached = $this->getCachedResult($target);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->runQuery($target);
        $this->storeCachedResult($target, $result);

        return $result;
    }

    /**
     * Run one query against the read connection, with monitoring and logging.
     *
     * @param Executable $target The query to run.
     * @return array<int, array<string, mixed>>
     * @throws QueryException
     */
    private function runQuery(Executable $target): array
    {
        try {
            if (QueryMonitor::isEnabled()) {
                QueryMonitor::recordQuery($target->getSQL());
            }

            $startTime = QueryLogger::isEnabled() ? microtime(true) : 0.0;
            $result = $this->getDriver('read')->query($target);

            if (QueryLogger::isEnabled()) {
                QueryLogger::logQuery($target->getSQL(), $target->getBinds(), $startTime);
            }

            return $result;
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw QueryException::fromQuery($exception->getMessage(), $target);
        }
    }

    /**
     * Read the cache TTL a query was configured with.
     *
     * property_exists() reports a protected property as present, but reading
     * one from outside the declaring class is a fatal Error, not a catchable
     * exception — so running any cached ActiveQuery through another builder's
     * query() killed the process outright. The public accessor is used instead,
     * and a query that has none is simply not cached.
     *
     * @param Executable $target The query to read the TTL from.
     * @return int Seconds, or 0 when the query carries no TTL.
     */
    private function resolveCacheTtl(Executable $target): int
    {
        return method_exists($target, 'getCacheTtl') ? $target->getCacheTtl() : 0;
    }

    /**
     * Attempt to retrieve a cached query result.
     *
     * A backend that cannot answer is treated as a miss: the rows it failed to
     * hand over can always be fetched from the database, so an unreachable
     * Redis turned every cached query into a hard failure for results that were
     * still perfectly obtainable.
     *
     * @param Executable $target The query to check cache for.
     * @return array<int, array<string, mixed>>|null Cached result or null if not cached.
     */
    private function getCachedResult(Executable $target): ?array
    {
        $driver = $this->resolveCacheDriver($target);
        if ($driver === null) {
            return null;
        }

        try {
            $key = QueryCache::generateKey($target->getSQL(), $target->getBinds(), $this->resolveConnectionName());
            $cached = $driver->get($key);
        } catch (Throwable) {
            return null;
        }

        return is_array($cached) ? $cached : null;
    }

    /**
     * Store a query result in cache if caching is enabled.
     *
     * A backend that refuses the write has not failed the query, which has
     * already run and returned its rows. The failure used to propagate out of
     * query(): a full Redis surfaced to the caller as a QueryException naming
     * the SELECT, sending them after a statement that was never at fault, and
     * discarding rows that had already been fetched.
     *
     * @param Executable $target The query that produced the result.
     * @param array<int, array<string, mixed>> $result The query result to cache.
     * @return void
     */
    private function storeCachedResult(Executable $target, array $result): void
    {
        $driver = $this->resolveCacheDriver($target);
        if ($driver === null) {
            return;
        }

        try {
            $key = QueryCache::generateKey($target->getSQL(), $target->getBinds(), $this->resolveConnectionName());
            $driver->set($key, $result, $this->resolveCacheTtl($target));
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Get the cache driver to use for a query, if it is to be cached at all.
     *
     * @param Executable $target The query being run.
     * @return CacheInterface|null Null when this query is not cached.
     */
    private function resolveCacheDriver(Executable $target): ?CacheInterface
    {
        if ($this->resolveCacheTtl($target) <= 0 || !QueryCache::isEnabled()) {
            return null;
        }

        return QueryCache::getDriver();
    }
}
