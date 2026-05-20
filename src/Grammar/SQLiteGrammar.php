<?php

namespace Simsoft\DB\Grammar;

/**
 * SQLite Grammar.
 *
 * SQL syntax specific to SQLite.
 */
class SQLiteGrammar implements Grammar
{
    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return "\"$identifier\"";
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
        $jsonPath = '$.' . $path;

        // SQLite json_extract returns text directly, no UNQUOTE needed
        return "json_extract($column, '$jsonPath')";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonContains(string $column, string $path): string
    {
        $jsonPath = $path === '' ? '$' : '$.' . $path;
        return "EXISTS (SELECT 1 FROM json_each($column, '$jsonPath') WHERE json_each.value = json_extract(?, '$'))";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonLength(string $column, string $path): string
    {
        $jsonPath = $path === '' ? '$' : '$.' . $path;
        return "json_array_length($column, '$jsonPath')";
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
}
