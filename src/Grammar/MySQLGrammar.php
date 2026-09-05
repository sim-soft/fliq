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
        $jsonPath = '$.' . $path;
        return "JSON_CONTAINS_PATH($column, 'one', '$jsonPath')";
    }

    /**
     * {@inheritdoc}
     */
    public function lockSQL(string $lockType): string
    {
        return match ($lockType) {
            'share' => 'FOR SHARE',
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
