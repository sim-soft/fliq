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
    /** @var int Queries retained by default before the oldest are dropped */
    public const DEFAULT_LIMIT = 1000;

    /** @var bool Whether logging is enabled */
    private static bool $enabled = false;

    /** @var array<int, array{sql: string, binds: array<int, mixed>|null, time: float}> Logged queries */
    private static array $queries = [];

    /** @var int Maximum retained queries. 0 means unlimited. */
    private static int $limit = self::DEFAULT_LIMIT;

    /** @var int Queries dropped to stay within the limit */
    private static int $dropped = 0;

    /** @var float Total time of dropped queries, in milliseconds */
    private static float $droppedTime = 0.0;

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
     * Stop sending queries to the handler.
     *
     * disable() and reset() both leave the handler installed, so one set during
     * a profiling block kept receiving every query for the rest of the process
     * — writing to a file the caller had finished with, or into a closure whose
     * request had ended. There was no way to undo setHandler(); this is it.
     *
     * @return void
     */
    public static function clearHandler(): void
    {
        self::$handler = null;
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

        self::trim();

        if (self::$handler !== null) {
            (self::$handler)($sql, $binds, $timeMs);
        }
    }

    /**
     * Drop the oldest entries once the log exceeds its limit.
     *
     * The log is held in memory for the life of the process, so a long-running
     * worker or a queue consumer would otherwise grow it until the process ran
     * out of memory. Newest queries are kept, since those are the ones being
     * investigated. Totals still account for what was dropped.
     *
     * @return void
     */
    private static function trim(): void
    {
        if (self::$limit <= 0 || count(self::$queries) <= self::$limit) {
            return;
        }

        $excess = count(self::$queries) - self::$limit;

        for ($i = 0; $i < $excess; ++$i) {
            $dropped = array_shift(self::$queries);
            if ($dropped !== null) {
                self::$droppedTime += $dropped['time'];
                ++self::$dropped;
            }
        }
    }

    /**
     * Set how many queries to retain.
     *
     * Use a handler instead of a large limit when you need every query: it
     * receives each one as it happens and can stream them somewhere durable.
     *
     * @param int $limit Maximum retained queries, or 0 for unlimited.
     * @return void
     */
    public static function setLimit(int $limit): void
    {
        self::$limit = max(0, $limit);
        self::trim();
    }

    /**
     * Get the current retention limit.
     *
     * @return int Zero when unlimited.
     */
    public static function getLimit(): int
    {
        return self::$limit;
    }

    /**
     * Get the number of queries dropped to stay within the limit.
     *
     * A non-zero value means getQueries() is not the whole story.
     *
     * @return int
     */
    public static function getDroppedCount(): int
    {
        return self::$dropped;
    }

    /**
     * Get the retained queries.
     *
     * Only the most recent are kept — see {@see setLimit()} and
     * {@see getDroppedCount()}.
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
     * Counts every query logged, including those since dropped, so it stays
     * accurate as a profiling figure.
     *
     * @return int
     */
    public static function getQueryCount(): int
    {
        return count(self::$queries) + self::$dropped;
    }

    /**
     * Get total execution time in milliseconds.
     *
     * Includes the time of dropped queries.
     *
     * @return float
     */
    public static function getTotalTime(): float
    {
        $total = self::$droppedTime;
        foreach (self::$queries as $query) {
            $total += $query['time'];
        }
        return round($total, 3);
    }

    /**
     * Get the slowest query.
     *
     * Only the retained queries can be ranked, so a slower one that has since
     * been dropped will not be found. Use a handler if you need to catch every
     * slow query in a long-running process.
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
        self::$dropped = 0;
        self::$droppedTime = 0.0;
    }
}
