<?php

namespace Simsoft\DB\Traits;

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
        $sql = $this->getSQL();
        $binds = $this->getBinds();

        if ($binds !== null && method_exists($this, 'getReadableSQL')) {
            echo $this->getReadableSQL($sql, $binds) . PHP_EOL;
            return $this;
        }

        if ($binds !== null) {
            echo $sql . PHP_EOL;
            echo "Binds: " . json_encode($binds) . PHP_EOL;
            return $this;
        }

        echo $sql . PHP_EOL;
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
        $name = $this->connection ?? Connection::getDefaultName();
        return Connection::get($name, $type);
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
        $target = $query ? clone $query : $this;

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
        $target = $query ? clone $query : $this;

        $cached = $this->getCachedResult($target);
        if ($cached !== null) {
            return $cached;
        }

        try {
            if (QueryMonitor::isEnabled()) {
                QueryMonitor::recordQuery($target->getSQL());
            }

            $startTime = QueryLogger::isEnabled() ? microtime(true) : 0.0;
            $result = $this->getDriver('read')->query($target);

            if (QueryLogger::isEnabled()) {
                QueryLogger::logQuery($target->getSQL(), $target->getBinds(), $startTime);
            }

            $this->storeCachedResult($target, $result);

            return $result;
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw QueryException::fromQuery($exception->getMessage(), $target);
        }
    }

    /**
     * Attempt to retrieve a cached query result.
     *
     * @param Executable $target The query to check cache for.
     * @return array<int, array<string, mixed>>|null Cached result or null if not cached.
     */
    private function getCachedResult(Executable $target): ?array
    {
        $cacheTtl = property_exists($target, 'cacheTtl') ? $target->cacheTtl : 0;
        if ($cacheTtl <= 0 || !QueryCache::isEnabled()) {
            return null;
        }

        $driver = QueryCache::getDriver();
        if ($driver === null) {
            return null;
        }

        $key = QueryCache::generateKey($target->getSQL(), $target->getBinds());
        $cached = $driver->get($key);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Store a query result in cache if caching is enabled.
     *
     * @param Executable $target The query that produced the result.
     * @param array<int, array<string, mixed>> $result The query result to cache.
     * @return void
     */
    private function storeCachedResult(Executable $target, array $result): void
    {
        $cacheTtl = property_exists($target, 'cacheTtl') ? $target->cacheTtl : 0;
        if ($cacheTtl <= 0 || !QueryCache::isEnabled()) {
            return;
        }

        $driver = QueryCache::getDriver();
        if ($driver === null) {
            return;
        }

        $key = QueryCache::generateKey($target->getSQL(), $target->getBinds());
        $driver->set($key, $result, $cacheTtl);
    }
}
