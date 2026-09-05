<?php

namespace Simsoft\DB\Grammar;

/**
 * PostgreSQL Grammar.
 *
 * SQL syntax specific to PostgreSQL.
 */
class PostgresGrammar implements Grammar
{
    use EscapesJsonPath;

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
            return "$column $operator " . $this->jsonPathLiteral($parts[0]);
        }

        $expr = $column;
        $lastIndex = count($parts) - 1;
        foreach ($parts as $idx => $part) {
            $op = ($idx === $lastIndex) ? $operator : '->';
            $expr .= " $op " . $this->jsonPathLiteral($part);
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
            return "$column -> " . $this->jsonPathLiteral($parts[0]) . ' @> ?::jsonb';
        }

        $expr = $column;
        foreach ($parts as $part) {
            $expr .= ' -> ' . $this->jsonPathLiteral($part);
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
            return "jsonb_array_length($column -> " . $this->jsonPathLiteral($parts[0]) . ')';
        }

        $expr = $column;
        foreach ($parts as $part) {
            $expr .= ' -> ' . $this->jsonPathLiteral($part);
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

    /**
     * {@inheritdoc}
     */
    public function insertIgnoreFullSQL(string $table, array $columns, string $placeholders): ?string
    {
        $quotedColumns = array_map(fn($col) => $this->quoteIdentifier($col), $columns);

        return "INSERT INTO $table ("
            . implode(', ', $quotedColumns)
            . ") VALUES ($placeholders) ON CONFLICT DO NOTHING";
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
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonKeyExists(string $column, string $path): string
    {
        $parts = explode('.', $path);

        if (count($parts) === 1) {
            return "jsonb_exists($column, " . $this->jsonPathLiteral($parts[0]) . ')';
        }

        // For nested paths, navigate to the parent then check key
        $lastKey = array_pop($parts);
        $expr = $column;
        foreach ($parts as $part) {
            $expr .= ' -> ' . $this->jsonPathLiteral($part);
        }

        return "jsonb_exists($expr, " . $this->jsonPathLiteral($lastKey) . ')';
    }

    /**
     * {@inheritdoc}
     */
    public function lockSQL(string $lockType): string
    {
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
        $tsvectors = array_map(
            fn($col) => "to_tsvector('$language', $col)",
            $columns
        );
        $tsvector = count($tsvectors) === 1 ? $tsvectors[0] : implode(' || ', $tsvectors);

        $queryFunc = match ($mode) {
            'phrase' => "phraseto_tsquery('$language', ?)",
            'websearch' => "websearch_to_tsquery('$language', ?)",
            default => "plainto_tsquery('$language', ?)",
        };

        return "$tsvector @@ $queryFunc";
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
        return "$column @> ARRAY[?]::$type" . '[]';
    }

    /**
     * {@inheritdoc}
     */
    public function arrayOverlaps(string $column, int $count, string $type = 'text'): string
    {
        $placeholders = implode(',', array_fill(0, $count, '?'));
        return "$column && ARRAY[$placeholders]::$type" . '[]';
    }

    /**
     * {@inheritdoc}
     */
    public function supportsArrayColumns(): bool
    {
        return true;
    }
}
