<?php

namespace Simsoft\DB;

use Throwable;

/**
 * QueryMonitor class.
 *
 * Detects N+1 query patterns by tracking repeated queries from the same call site.
 * Enable during development to find lazy-loading performance issues.
 */
class QueryMonitor
{
    /**
     * @var int Backtrace frames to inspect when locating the caller.
     *
     * A lazy relation load reaches recordQuery() eight frames deep — through
     * Execute, Fetchable, and Collection's pager — so the caller sits close
     * enough to the limit that a shallower walk would miss it and report
     * 'unknown'. Deep enough to clear the library, capped so that a query
     * issued from far down a call stack does not build a large trace on every
     * new pattern.
     */
    private const TRACE_DEPTH = 24;

    /** @var bool Whether monitoring is enabled */
    private static bool $enabled = false;

    /** @var string|null Cached library directory, with trailing separator */
    private static ?string $root = null;

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
     * Restore the default warning behaviour.
     *
     * Neither disable() nor reset() removes the handler, and enable() does not
     * replace it, so a handler set once stayed installed for the rest of the
     * process — a handler registered in one request-scoped block kept firing in
     * the next, and one registered by a test kept firing in the tests that
     * followed it, reporting into a closure whose surrounding state was gone.
     * There was no way to undo setHandler(); this is it.
     *
     * @return void
     */
    public static function clearHandler(): void
    {
        self::$handler = null;
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
        // Replace quoted string literals first. A literal may contain an
        // escaped quote, written as two in a row: 'o''brien' is one value, not
        // two. Matching `'[^']*'` stopped at the inner pair and produced `??`,
        // so the same name spelt with an apostrophe normalised differently from
        // one without, and the two never grouped.
        $pattern = preg_replace("/'(?:[^']|'')*'/", '?', $sql) ?? $sql;

        // Replace standalone numeric literals (not part of identifiers).
        // Matches numbers preceded by non-word chars (operators, commas,
        // parens, spaces) and followed by a delimiter or the end of the
        // statement. Without the `$` alternative a trailing literal was left
        // alone, which is exactly where an id lands: `WHERE id = 1` and
        // `WHERE id = 2` stayed distinct, so the N+1 this class exists to find
        // counted as one occurrence each and never reached the threshold.
        $pattern = preg_replace('/(?<=[\s,=(><!])(\d+)(?=[\s,);\]]|$)/', '?', $pattern) ?? $pattern;

        // Also handle numbers at the very start (unlikely but safe)
        $pattern = preg_replace('/^\d+(?=[\s,)]|$)/', '?', $pattern) ?? $pattern;

        // Replace IN (...) value lists
        $pattern = preg_replace('/IN\s*\([?,\s]+\)/i', 'IN (?)', $pattern) ?? $pattern;

        // Collapse whitespace
        return preg_replace('/\s+/', ' ', trim($pattern)) ?? trim($pattern);
    }

    /**
     * Get the call origin (file:line) that triggered the query.
     *
     * Walks out of the library and reports the first frame belonging to the
     * caller, which is the line an N+1 warning needs to name if it is to be
     * actionable.
     *
     * @return string
     */
    private static function getOrigin(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH);
        $root = self::libraryRoot();

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';

            // Frames with no file are internal calls — iterator_to_array
            // driving a generator, say. They belong to whatever called them, so
            // keep walking rather than reporting a location that has none.
            if ($file === '' || str_starts_with($file, $root)) {
                continue;
            }

            return $file . ':' . ($frame['line'] ?? 0);
        }

        // Only reachable if all TRACE_DEPTH frames are library files, which
        // needs 24 consecutive ones: the deepest real path measured — a lazy
        // relation load through a collection — uses 9, and no arrangement of
        // internal frames extends it, since debug_backtrace() gives the caller
        // of every fileless frame a file of its own. Kept because the walk must
        // return a string and a wrong file:line would be worse than admitting
        // there isn't one; not covered, because nothing can honestly get here.
        return 'unknown';
    }

    /**
     * Get the library's own directory, with a trailing separator.
     *
     * The skip list used to be six str_contains() tests against forward-slash
     * fragments — 'src/Drivers/', 'src/Model.php' and so on. Two things were
     * wrong with that. On Windows __FILE__ uses backslashes, so none of the
     * fragments ever matched and every origin was reported as the first frame,
     * which is the self::getOrigin() call inside recordQuery(): every warning
     * blamed QueryMonitor.php:94 rather than the loop that caused it. And the
     * list only named six paths, so a query arriving through any other file in
     * the library — Collection's lazy pager among them — would have been
     * credited to the library even where the fragments did match.
     *
     * Deriving the directory from __DIR__ and comparing prefixes covers every
     * file in the package, on either separator, with nothing to keep in sync as
     * files are added.
     *
     * @return string
     */
    private static function libraryRoot(): string
    {
        return self::$root ??= __DIR__ . DIRECTORY_SEPARATOR;
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
            self::callHandler(self::$handler, $pattern, $count, $origin);
            return;
        }

        trigger_error(
            "N+1 query detected ({$count}x): {$pattern} [from: {$origin}]",
            E_USER_WARNING
        );
    }

    /**
     * Hand one warning to the custom handler, surviving its failure.
     *
     * The same guard QueryLogger's handler needs, and for a worse reason. This
     * one runs from recordQuery(), which Execute calls *before* handing the
     * statement to the driver: a handler that threw was caught there and
     * rewrapped as a QueryException, so the statement never ran. Measured on
     * SQLite, a loop of four inserts past the N+1 threshold wrote one row and
     * lost three — to a failure in the code that was only watching.
     *
     * A monitor must not be able to stop the queries it is monitoring.
     *
     * The handler is passed in rather than read from the property, because the
     * null check at the call site does not narrow across the method boundary.
     *
     * @param callable $handler The installed handler.
     * @param string $pattern The detected pattern.
     * @param int $count How many times it was seen.
     * @param string $origin Where it was issued from.
     * @return void
     */
    private static function callHandler(callable $handler, string $pattern, int $count, string $origin): void
    {
        try {
            $handler($pattern, $count, $origin);
        } catch (Throwable $throwable) {
            // Guarded in turn: trigger_error() throws under a
            // warnings-to-exceptions handler, which would put the failure back
            // on the path this exists to keep clear.
            try {
                trigger_error(
                    'QueryMonitor handler threw ' . $throwable::class . ': ' . $throwable->getMessage()
                    . '. The query itself was unaffected.',
                    E_USER_WARNING
                );
            } catch (Throwable) {
                // Nothing left to report it to.
            }
        }
    }
}
