<?php

namespace Simsoft\DB;

/**
 * QueryMonitor class.
 *
 * Detects N+1 query patterns by tracking repeated queries from the same call site.
 * Enable during development to find lazy-loading performance issues.
 */
class QueryMonitor
{
    /** @var bool Whether monitoring is enabled */
    private static bool $enabled = false;

    /** @var array<string, int> Query pattern counts: [sql_pattern => count] */
    private static array $patterns = [];

    /** @var array<string, string> Query origins: [sql_pattern => backtrace_location] */
    private static array $origins = [];

    /** @var int Threshold to trigger a warning */
    private static int $threshold = 5;

    /** @var callable|null Custom handler for N+1 detection */
    private static $handler = null;

    /**
     * Enable query monitoring.
     *
     * @param int $threshold Number of similar queries before warning. Default: 5.
     * @return void
     */
    public static function enable(int $threshold = 5): void
    {
        self::$enabled = true;
        self::$threshold = $threshold;
        self::$patterns = [];
        self::$origins = [];
    }

    /**
     * Disable query monitoring.
     *
     * @return void
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Check if monitoring is enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Set a custom handler for N+1 detection.
     *
     * The handler receives: (string $pattern, int $count, string $origin)
     *
     * @param callable $handler The handler function.
     * @return void
     */
    public static function setHandler(callable $handler): void
    {
        self::$handler = $handler;
    }

    /**
     * Record a query execution.
     *
     * Normalizes the SQL to detect repeated patterns (replaces literal values
     * with placeholders for pattern matching).
     *
     * @param string $sql The SQL that was executed.
     * @return void
     */
    public static function recordQuery(string $sql): void
    {
        if (!self::$enabled) {
            return;
        }

        $pattern = self::normalizePattern($sql);

        if (!isset(self::$patterns[$pattern])) {
            self::$patterns[$pattern] = 0;
            self::$origins[$pattern] = self::getOrigin();
        }

        self::$patterns[$pattern]++;

        if (self::$patterns[$pattern] === self::$threshold) {
            self::triggerWarning($pattern);
        }
    }

    /**
     * Get all detected N+1 patterns.
     *
     * @return array<string, array{count: int, origin: string}>
     */
    public static function getDetectedPatterns(): array
    {
        $results = [];
        foreach (self::$patterns as $pattern => $count) {
            if ($count >= self::$threshold) {
                $results[$pattern] = [
                    'count' => $count,
                    'origin' => self::$origins[$pattern],
                ];
            }
        }
        return $results;
    }

    /**
     * Reset all recorded patterns.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$patterns = [];
        self::$origins = [];
    }

    /**
     * Normalize SQL into a pattern for comparison.
     *
     * Strips literal values (numbers and strings in WHERE/VALUES context)
     * while preserving identifiers like table names with numbers.
     *
     * @param string $sql The raw SQL.
     * @return string
     */
    private static function normalizePattern(string $sql): string
    {
        // Replace quoted string literals first
        $pattern = preg_replace("/'[^']*'/", '?', $sql) ?? $sql;
        // Replace standalone numeric literals (not part of identifiers)
        // Matches numbers preceded by non-word chars (operators, commas, parens, spaces)
        $pattern = preg_replace('/(?<=[\s,=(><!])(\d+)(?=[\s,);\]])/', '?', $pattern) ?? $pattern;
        // Also handle numbers at the very start (unlikely but safe)
        $pattern = preg_replace('/^\d+(?=[\s,)])/', '?', $pattern) ?? $pattern;
        // Replace IN (...) value lists
        $pattern = preg_replace('/IN\s*\([?,\s]+\)/i', 'IN (?)', $pattern) ?? $pattern;
        // Collapse whitespace
        return preg_replace('/\s+/', ' ', trim($pattern)) ?? trim($pattern);
    }

    /**
     * Get the call origin (file:line) that triggered the query.
     *
     * @return string
     */
    private static function getOrigin(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            // Skip internal library files
            if (str_contains($file, 'src/Drivers/') || str_contains($file, 'src/Traits/')) {
                continue;
            }
            if (str_contains($file, 'src/Builder/') || str_contains($file, 'src/Model.php')) {
                continue;
            }
            if (str_contains($file, 'src/Collection.php') || str_contains($file, 'src/Relation.php')) {
                continue;
            }

            return ($frame['file'] ?? 'unknown') . ':' . ($frame['line'] ?? 0);
        }

        return 'unknown';
    }

    /**
     * Trigger the N+1 warning.
     *
     * @param string $pattern The detected pattern.
     * @return void
     */
    private static function triggerWarning(string $pattern): void
    {
        $count = self::$patterns[$pattern];
        $origin = self::$origins[$pattern];

        if (self::$handler !== null) {
            (self::$handler)($pattern, $count, $origin);
            return;
        }

        trigger_error(
            "N+1 query detected ({$count}x): {$pattern} [from: {$origin}]",
            E_USER_WARNING
        );
    }
}
