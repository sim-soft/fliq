<?php

namespace Simsoft\DB\Grammar;

/**
 * MySQL Grammar.
 *
 * SQL syntax specific to MySQL and MariaDB.
 */
class MySQLGrammar implements Grammar
{
    use EscapesStringLiteral, ExplainFormat, FulltextMode;

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
    public function supportsStatementModifiers(): bool
    {
        return true;
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
        // MySQL uses MATCH...AGAINST syntax handled by MatchAgainst condition.
        //
        // Every mode used to emit BOOLEAN MODE, which is not a wording
        // difference: BOOLEAN MODE reads punctuation in the term as operators.
        // Searching 'database -systems' in plain mode returned nothing, because
        // the '-' was parsed as an exclusion, and an ordinary term like
        // 'C++ database' raised "syntax error, unexpected '+'" outright.
        $mode = $this->normaliseFulltextMode($mode);
        $cols = implode(', ', $columns);

        // A phrase is quoted inside BOOLEAN MODE, so the quotes have to be
        // added to the value. Building them in SQL keeps the term bound; a
        // double quote in the term is replaced with a space, since otherwise it
        // would close the phrase and let the remainder be read as operators.
        if ($mode === 'phrase') {
            return "MATCH($cols) AGAINST(CONCAT('\"', REPLACE(?, '\"', ' '), '\"') IN BOOLEAN MODE)";
        }

        $against = $mode === 'websearch' ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';
        return "MATCH($cols) AGAINST(? $against)";
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
        // MySQL does not support native array columns; use JSON_CONTAINS instead.
        //
        // The candidate argument must be JSON text, not a bare value. Binding
        // the value directly made JSON_CONTAINS reject every string —
        // arrayContains('tags', 'php') raised "Invalid JSON text in argument 1"
        // — while integers passed by accident, because 2 is itself valid JSON.
        // Wrapping the placeholder in JSON_ARRAY() builds the candidate in SQL,
        // so the value stays bound.
        $candidate = $this->jsonCandidate($type);
        return "JSON_CONTAINS($column, JSON_ARRAY($candidate), '$')";
    }

    /**
     * {@inheritdoc}
     */
    public function arrayOverlaps(string $column, int $count, string $type = 'text'): string
    {
        // MySQL does not support native array columns; use JSON_OVERLAPS (8.0.17+)
        if ($count === 0) {
            // JSON_ARRAY() is legal and matches nothing, but say so plainly.
            return '0 = 1';
        }

        $candidate = $this->jsonCandidate($type);
        $placeholders = implode(',', array_fill(0, $count, $candidate));
        return "JSON_OVERLAPS($column, JSON_ARRAY($placeholders))";
    }

    /**
     * Build the bound candidate expression for a JSON array comparison.
     *
     * PDO binds every value as a string unless told otherwise, so JSON_ARRAY(?)
     * given the integer 2 builds ["2"] — which does not match a stored [1,2].
     * Casting the placeholder to the SQL type the caller named restores the
     * JSON type of the value, so an int column compares as int and a text
     * column as text. The cast target is chosen from a fixed map rather than
     * interpolated, so the caller's type never reaches the statement as SQL.
     *
     * @param string $type The array element type, in PostgreSQL's vocabulary.
     * @return string A placeholder expression producing a correctly typed value.
     */
    private function jsonCandidate(string $type): string
    {
        $cast = match (strtolower(trim($type))) {
            'int', 'int2', 'int4', 'int8', 'integer',
            'bigint', 'smallint', 'serial', 'bigserial' => 'SIGNED',
            'numeric', 'decimal', 'real', 'float', 'float4',
            'float8', 'double precision' => 'DECIMAL(65,30)',
            'date' => 'DATE',
            'timestamp', 'timestamptz', 'datetime' => 'DATETIME',
            default => 'CHAR',
        };

        return "CAST(? AS $cast)";
    }

    /**
     * {@inheritdoc}
     */
    public function supportsArrayColumns(): bool
    {
        return false;
    }
}
