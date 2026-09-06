<?php

namespace Simsoft\DB\Grammar;

/**
 * MySQL Grammar.
 *
 * SQL syntax specific to MySQL and MariaDB.
 */
class MySQLGrammar implements Grammar
{
    use EscapesStringLiteral, ExplainFormat;

    /** @var array<int, string> Plan formats MySQL accepts after FORMAT=. */
    private const EXPLAIN_FORMATS = ['text', 'traditional', 'json', 'tree'];

    /** @var array<int, string> Plan formats MySQL accepts alongside ANALYZE. */
    private const ANALYZE_FORMATS = ['text', 'tree'];

    /**
     * {@inheritdoc}
     *
     * MySQL also treats backslash as an escape character inside string
     * literals (unless NO_BACKSLASH_ESCAPES is enabled), so it is doubled
     * as well to keep the literal intact either way.
     */
    protected function escapeStringLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "''"], $value);
    }

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * {@inheritdoc}
     */
    public function limitSQL(int $limit, ?int $offset = null): string
    {
        if ($offset !== null && $offset > 0) {
            return "LIMIT $offset, $limit";
        }

        return "LIMIT $limit";
    }

    /**
     * {@inheritdoc}
     */
    public function insertIgnoreSQL(): string
    {
        return 'INSERT IGNORE';
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int, string> $columns
     * @param array<int, string> $updateColumns
     * @param array<int, string> $conflictColumns
     */
    public function upsertSQL(string $table, array $columns, array $updateColumns, string $placeholders, array $conflictColumns = []): string
    {
        $quotedColumns = array_map(fn($col) => $this->quoteIdentifier($col), $columns);

        $sql = "INSERT INTO $table ("
            . implode(', ', $quotedColumns)
            . ") VALUES ($placeholders)";

        $updates = [];
        foreach ($updateColumns as $col) {
            $quoted = $this->quoteIdentifier($col);
            $updates[] = "$quoted = VALUES($quoted)";
        }

        return $sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'mysql';
    }

    /**
     * {@inheritdoc}
     *
     * MySQL spells the format as `FORMAT=<name>` immediately after the
     * keywords. It has no YAML or XML plan output, and EXPLAIN ANALYZE only
     * produces the tree format — asking for any other alongside ANALYZE is
     * refused by the server with "This version of MySQL doesn't yet support
     * 'FORMAT=JSON with EXPLAIN ANALYZE'", so it is rejected here instead,
     * naming the argument the caller actually passed.
     *
     * 'text' maps to TRADITIONAL, the column-per-field default, and is left
     * implicit so the emitted statement matches what a user would write.
     */
    public function explainSQL(bool $analyze, string $format): string
    {
        if (!$analyze) {
            $normalised = $this->normaliseExplainFormat($format, self::EXPLAIN_FORMATS);

            return in_array($normalised, ['text', 'traditional'], true)
                ? 'EXPLAIN'
                : 'EXPLAIN FORMAT=' . strtoupper($normalised);
        }

        // ANALYZE reports the tree format whether or not it is named, so the
        // bare form is emitted for both spellings of the same request.
        $this->normaliseExplainFormat($format, self::ANALYZE_FORMATS);

        return 'EXPLAIN ANALYZE';
    }

    /**
     * {@inheritdoc}
     */
    public function jsonExtract(string $column, string $path, bool $asText = true): string
    {
        $jsonPath = $this->stringLiteral('$.' . $path);

        if ($asText) {
            return "JSON_UNQUOTE(JSON_EXTRACT($column, $jsonPath))";
        }

        return "JSON_EXTRACT($column, $jsonPath)";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonContains(string $column, string $path): string
    {
        $jsonPath = $this->stringLiteral($path === '' ? '$' : '$.' . $path);
        return "JSON_CONTAINS($column, ?, $jsonPath)";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonLength(string $column, string $path): string
    {
        $jsonPath = $this->stringLiteral($path === '' ? '$' : '$.' . $path);
        return "JSON_LENGTH($column, $jsonPath)";
    }

    /**
     * {@inheritdoc}
     */
    public function dateExtract(string $column): string
    {
        return "DATE($column)";
    }

    /**
     * {@inheritdoc}
     */
    public function monthExtract(string $column): string
    {
        return "MONTH($column)";
    }

    /**
     * {@inheritdoc}
     */
    public function yearExtract(string $column): string
    {
        return "YEAR($column)";
    }

    /**
     * {@inheritdoc}
     */
    public function timeExtract(string $column): string
    {
        return "TIME($column)";
    }

    /**
     * {@inheritdoc}
     */
    public function insertIgnoreFullSQL(string $table, array $columns, string $placeholders): ?string
    {
        return null; // MySQL uses INSERT IGNORE keyword directly
    }

    /**
     * {@inheritdoc}
     */
    public function returningSQL(string $column): string
    {
        return ''; // MySQL does not support RETURNING
    }

    /**
     * {@inheritdoc}
     */
    public function returningColumnsSQL(array $columns): string
    {
        return ''; // MySQL does not support RETURNING
    }

    /**
     * {@inheritdoc}
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonKeyExists(string $column, string $path): string
    {
        $jsonPath = $this->stringLiteral('$.' . $path);
        return "JSON_CONTAINS_PATH($column, 'one', $jsonPath)";
    }

    /**
     * {@inheritdoc}
     */
    public function lockSQL(string $lockType): string
    {
        // NOWAIT and SKIP LOCKED have been available since MySQL 8.0. Without
        // them here both fell through to a plain FOR UPDATE, so a caller asking
        // to skip locked rows silently got the blocking behaviour instead — the
        // opposite of what the job queue pattern needs.
        return match ($lockType) {
            'share' => 'FOR SHARE',
            'noWait' => 'FOR UPDATE NOWAIT',
            'skipLocked' => 'FOR UPDATE SKIP LOCKED',
            default => 'FOR UPDATE',
        };
    }

    /**
     * {@inheritdoc}
     */
    public function fulltextSearch(array $columns, string $mode = 'plain', string $language = 'english'): string
    {
        // MySQL uses MATCH...AGAINST syntax handled by MatchAgainst condition
        $cols = implode(', ', $columns);
        return "MATCH($cols) AGAINST(? IN BOOLEAN MODE)";
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFulltext(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function arrayContains(string $column, string $type = 'text'): string
    {
        // MySQL does not support native array columns; use JSON_CONTAINS instead
        return "JSON_CONTAINS($column, ?, '$')";
    }

    /**
     * {@inheritdoc}
     */
    public function arrayOverlaps(string $column, int $count, string $type = 'text'): string
    {
        // MySQL does not support native array columns; use JSON_OVERLAPS (8.0.17+)
        $placeholders = implode(',', array_fill(0, $count, '?'));
        return "JSON_OVERLAPS($column, JSON_ARRAY($placeholders))";
    }

    /**
     * {@inheritdoc}
     */
    public function supportsArrayColumns(): bool
    {
        return false;
    }
}
