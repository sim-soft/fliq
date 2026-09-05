<?php

namespace Simsoft\DB\Grammar;

/**
 * SQLite Grammar.
 *
 * SQL syntax specific to SQLite.
 */
class SQLiteGrammar implements Grammar
{
    use EscapesStringLiteral;

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * {@inheritdoc}
     */
    public function limitSQL(int $limit, ?int $offset = null): string
    {
        $sql = "LIMIT $limit";

        if ($offset !== null && $offset > 0) {
            $sql .= " OFFSET $offset";
        }

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function insertIgnoreSQL(): string
    {
        return 'INSERT OR IGNORE';
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
            $updates[] = "$quoted = excluded.$quoted";
        }

        // Use explicit conflict columns if provided, otherwise fall back to first column
        $targets = !empty($conflictColumns) ? $conflictColumns : [$columns[0]];
        $conflictTarget = implode(', ', array_map(fn($col) => $this->quoteIdentifier($col), $targets));

        return $sql . " ON CONFLICT ($conflictTarget) DO UPDATE SET " . implode(', ', $updates);
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'sqlite';
    }

    /**
     * {@inheritdoc}
     */
    public function jsonExtract(string $column, string $path, bool $asText = true): string
    {
        $jsonPath = $this->stringLiteral('$.' . $path);

        // SQLite json_extract returns text directly, no UNQUOTE needed
        return "json_extract($column, $jsonPath)";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonContains(string $column, string $path): string
    {
        $jsonPath = $this->stringLiteral($path === '' ? '$' : '$.' . $path);
        return "EXISTS (SELECT 1 FROM json_each($column, $jsonPath) WHERE json_each.value = json_extract(?, '$'))";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonLength(string $column, string $path): string
    {
        $jsonPath = $this->stringLiteral($path === '' ? '$' : '$.' . $path);
        return "json_array_length($column, $jsonPath)";
    }

    /**
     * {@inheritdoc}
     */
    public function dateExtract(string $column): string
    {
        return "date($column)";
    }

    /**
     * {@inheritdoc}
     */
    public function monthExtract(string $column): string
    {
        return "CAST(strftime('%m', $column) AS INTEGER)";
    }

    /**
     * {@inheritdoc}
     */
    public function yearExtract(string $column): string
    {
        return "CAST(strftime('%Y', $column) AS INTEGER)";
    }

    /**
     * {@inheritdoc}
     */
    public function timeExtract(string $column): string
    {
        return "time($column)";
    }

    /**
     * {@inheritdoc}
     */
    public function insertIgnoreFullSQL(string $table, array $columns, string $placeholders): ?string
    {
        return null; // SQLite uses INSERT OR IGNORE keyword directly
    }

    /**
     * {@inheritdoc}
     */
    public function returningSQL(string $column): string
    {
        return "RETURNING " . $this->quoteIdentifier($column);
    }

    /**
     * {@inheritdoc}
     */
    public function returningColumnsSQL(array $columns): string
    {
        if (empty($columns)) {
            return 'RETURNING *';
        }

        $quoted = array_map(fn($col) => $this->quoteIdentifier($col), $columns);
        return 'RETURNING ' . implode(', ', $quoted);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsReturning(): bool
    {
        return true; // SQLite 3.35+ supports RETURNING
    }

    /**
     * {@inheritdoc}
     */
    public function jsonKeyExists(string $column, string $path): string
    {
        $jsonPath = $this->stringLiteral('$.' . $path);
        return "json_type($column, $jsonPath) IS NOT NULL";
    }

    /**
     * {@inheritdoc}
     */
    public function lockSQL(string $lockType): string
    {
        return ''; // SQLite does not support row-level locking
    }

    /**
     * {@inheritdoc}
     */
    public function fulltextSearch(array $columns, string $mode = 'plain', string $language = 'english'): string
    {
        // SQLite FTS5 uses MATCH syntax on virtual tables — limited support
        $col = $columns[0] ?? 'content';
        return "$col MATCH ?";
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFulltext(): bool
    {
        return false; // FTS5 requires virtual tables, not general-purpose
    }

    /**
     * {@inheritdoc}
     */
    public function arrayContains(string $column, string $type = 'text'): string
    {
        // SQLite does not support native array columns
        return "EXISTS (SELECT 1 FROM json_each($column) WHERE json_each.value = ?)";
    }

    /**
     * {@inheritdoc}
     */
    public function arrayOverlaps(string $column, int $count, string $type = 'text'): string
    {
        $placeholders = implode(',', array_fill(0, $count, '?'));
        return "EXISTS (SELECT 1 FROM json_each($column) WHERE json_each.value IN ($placeholders))";
    }

    /**
     * {@inheritdoc}
     */
    public function supportsArrayColumns(): bool
    {
        return false;
    }
}
