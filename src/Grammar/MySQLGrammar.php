<?php

namespace Simsoft\DB\Grammar;

/**
 * MySQL Grammar.
 *
 * SQL syntax specific to MySQL and MariaDB.
 */
class MySQLGrammar implements Grammar
{
    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return "`$identifier`";
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
     */
    public function jsonExtract(string $column, string $path, bool $asText = true): string
    {
        $jsonPath = '$.' . str_replace('.', '.', $path);

        if ($asText) {
            return "JSON_UNQUOTE(JSON_EXTRACT($column, '$jsonPath'))";
        }

        return "JSON_EXTRACT($column, '$jsonPath')";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonContains(string $column, string $path): string
    {
        $jsonPath = $path === '' ? '$' : '$.' . $path;
        return "JSON_CONTAINS($column, ?, '$jsonPath')";
    }

    /**
     * {@inheritdoc}
     */
    public function jsonLength(string $column, string $path): string
    {
        $jsonPath = $path === '' ? '$' : '$.' . $path;
        return "JSON_LENGTH($column, '$jsonPath')";
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
}
