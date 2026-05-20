<?php

namespace Simsoft\DB\Grammar;

/**
 * PostgreSQL Grammar.
 *
 * SQL syntax specific to PostgreSQL.
 */
class PostgresGrammar implements Grammar
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
        return 'INSERT';
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
            $updates[] = "$quoted = EXCLUDED.$quoted";
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
        return 'pgsql';
    }

    /**
     * {@inheritdoc}
     */
    public function jsonExtract(string $column, string $path, bool $asText = true): string
    {
        $parts = explode('.', $path);
        $operator = $asText ? '->>' : '->';

        // For nested paths: column->'key1'->'key2'->>'leaf'
        if (count($parts) === 1) {
            return "$column $operator '$parts[0]'";
        }

        $expr = $column;
        $lastIndex = count($parts) - 1;
        foreach ($parts as $idx => $part) {
            $op = ($idx === $lastIndex) ? $operator : '->';
            $expr .= " $op '$part'";
        }

        return $expr;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonContains(string $column, string $path): string
    {
        // PostgreSQL: column->'path' @> ?::jsonb
        $parts = explode('.', $path);

        if (count($parts) === 1) {
            return "$column -> '$parts[0]' @> ?::jsonb";
        }

        $expr = $column;
        foreach ($parts as $part) {
            $expr .= " -> '$part'";
        }

        return "$expr @> ?::jsonb";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonLength(string $column, string $path): string
    {
        $parts = explode('.', $path);

        if (count($parts) === 1) {
            return "jsonb_array_length($column -> '$parts[0]')";
        }

        $expr = $column;
        foreach ($parts as $part) {
            $expr .= " -> '$part'";
        }

        return "jsonb_array_length($expr)";
    }

    /**
     * {@inheritdoc}
     */
    public function dateExtract(string $column): string
    {
        return "$column::date";
    }

    /**
     * {@inheritdoc}
     */
    public function monthExtract(string $column): string
    {
        return "EXTRACT(MONTH FROM $column)";
    }

    /**
     * {@inheritdoc}
     */
    public function yearExtract(string $column): string
    {
        return "EXTRACT(YEAR FROM $column)";
    }

    /**
     * {@inheritdoc}
     */
    public function timeExtract(string $column): string
    {
        return "$column::time";
    }
}
