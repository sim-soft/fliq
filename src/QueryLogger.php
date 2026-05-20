<?php

namespace Simsoft\DB;

/**
 * QueryLogger class.
 *
 * Records all executed queries with timing information.
 * Enable during development or production for APM/profiling.
 */
class QueryLogger
{
    /** @var bool Whether logging is enabled */
    private static bool $enabled = false;

    /** @var array<int, array{sql: string, binds: array<int, mixed>|null, time: float}> Logged queries */
    private static array $queries = [];

    /** @var callable|null Custom log handler */
    private static $handler = null;

    /**
     * Enable query logging.
     *
     * @return void
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable query logging.
     *
     * @return void
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Check if logging is enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Set a custom handler for each logged query.
     *
     * Handler receives: (string $sql, ?array $binds, float $timeMs)
     *
     * @param callable $handler The handler function.
     * @return void
     */
    public static function setHandler(callable $handler): void
    {
        self::$handler = $handler;
    }

    /**
     * Log a query execution.
     *
     * @param string $sql The SQL that was executed.
     * @param array<int, mixed>|null $binds The bind values.
     * @param float $startTime The microtime before execution.
     * @return void
     */
    public static function logQuery(string $sql, ?array $binds, float $startTime): void
    {
        if (!self::$enabled) {
            return;
        }

        $timeMs = (microtime(true) - $startTime) * 1000;

        self::$queries[] = [
            'sql' => $sql,
            'binds' => $binds,
            'time' => round($timeMs, 3),
        ];

        if (self::$handler !== null) {
            (self::$handler)($sql, $binds, $timeMs);
        }
    }

    /**
     * Get all logged queries.
     *
     * @return array<int, array{sql: string, binds: array<int, mixed>|null, time: float}>
     */
    public static function getQueries(): array
    {
        return self::$queries;
    }

    /**
     * Get total query count.
     *
     * @return int
     */
    public static function getQueryCount(): int
    {
        return count(self::$queries);
    }

    /**
     * Get total execution time in milliseconds.
     *
     * @return float
     */
    public static function getTotalTime(): float
    {
        $total = 0.0;
        foreach (self::$queries as $query) {
            $total += $query['time'];
        }
        return round($total, 3);
    }

    /**
     * Get the slowest query.
     *
     * @return array{sql: string, binds: array<int, mixed>|null, time: float}|null
     */
    public static function getSlowestQuery(): ?array
    {
        if (empty(self::$queries)) {
            return null;
        }

        $slowest = self::$queries[0];
        foreach (self::$queries as $query) {
            if ($query['time'] > $slowest['time']) {
                $slowest = $query;
            }
        }
        return $slowest;
    }

    /**
     * Reset the query log.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$queries = [];
    }
}
