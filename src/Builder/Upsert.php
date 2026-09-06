<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Connection;
use Simsoft\DB\Grammar\Grammar;

/**
 * Upsert Query Builder Class.
 *
 * Generates INSERT ... ON DUPLICATE KEY UPDATE / ON CONFLICT SQL statements.
 * Uses the Grammar interface for database-specific syntax.
 */
class Upsert extends Builder
{
    /**
     * Constructor.
     *
     * @param string $table The table name.
     * @param array<string, mixed> $attributes Attributes => values to insert.
     * @param array<int|string, mixed> $updateColumns Columns to update on duplicate key.
     * @param array<int, string> $conflictColumns Columns that form the unique constraint for conflict detection.
     */
    public function __construct(
        protected string $table,
        protected array $attributes,
        protected array $updateColumns = [],
        protected array $conflictColumns = []
    )
    {
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $grammar = Connection::grammar($this->connection);
        $columns = array_keys($this->attributes);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // Every name here is interpolated into the statement and none can be
        // bound, so each is checked before it is quoted: the grammars double an
        // embedded quote rather than reject it, which left a column name
        // carrying its own punctuation usable as SQL.
        $this->assertIdentifiers($columns);
        $this->assertIdentifiers($this->conflictColumns);

        $this->appendBinds(array_values($this->attributes));

        if ($grammar->getDriverName() === 'mysql') {
            return $this->buildMySQLUpsert($grammar, $columns, $placeholders);
        }

        // PostgreSQL / SQLite: use Grammar's upsertSQL with conflict columns
        $updateCols = $this->resolveUpdateColumns($columns);
        $this->assertIdentifiers($updateCols);
        $quotedTable = $this->quoteTable($this->table);

        return $grammar->upsertSQL($quotedTable, $columns, $updateCols, $placeholders, $this->conflictColumns);
    }

    /**
     * Validate a set of column names.
     *
     * @param array<int, string> $columns The column names to check.
     * @return void
     */
    private function assertIdentifiers(array $columns): void
    {
        foreach ($columns as $column) {
            $this->quoteColumn($column);
        }
    }

    /**
     * Resolve which columns should be updated on conflict.
     *
     * @param array<int, string> $columns All insert columns.
     * @return array<int, string>
     */
    private function resolveUpdateColumns(array $columns): array
    {
        if (empty($this->updateColumns)) {
            return $columns;
        }

        $updateCols = [];
        foreach ($this->updateColumns as $col => $value) {
            $updateCols[] = is_int($col) ? (string)$value : $col;
        }

        return $updateCols;
    }

    /**
     * Build MySQL-specific upsert with support for explicit update values.
     *
     * @param Grammar $grammar The grammar instance.
     * @param array<int, string> $columns The insert columns.
     * @param string $placeholders The VALUES placeholders.
     * @return string
     */
    private function buildMySQLUpsert(Grammar $grammar, array $columns, string $placeholders): string
    {
        $quotedColumns = array_map(fn($col) => $grammar->quoteIdentifier($col), $columns);

        $sql = "INSERT INTO " . $this->quoteTable($this->table) . " ("
            . implode(', ', $quotedColumns)
            . ") VALUES ($placeholders)";

        $updates = $this->buildMySQLUpdateClauses($grammar, $columns);

        return $sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    /**
     * Build the SET clauses for MySQL ON DUPLICATE KEY UPDATE.
     *
     * @param Grammar $grammar The grammar instance.
     * @param array<int, string> $columns All insert columns.
     * @return array<int, string>
     */
    private function buildMySQLUpdateClauses(Grammar $grammar, array $columns): array
    {
        if (empty($this->updateColumns)) {
            return array_map(function (string $col) use ($grammar): string {
                $quoted = $grammar->quoteIdentifier($col);
                return "$quoted = VALUES($quoted)";
            }, $columns);
        }

        $updates = [];
        foreach ($this->updateColumns as $col => $value) {
            if (is_int($col)) {
                $quoted = $this->quoteColumn((string)$value);
                $updates[] = "$quoted = VALUES($quoted)";
                continue;
            }
            $quoted = $this->quoteColumn($col);
            $updates[] = "$quoted = ?";
            $this->appendBinds($value);
        }

        return $updates;
    }
}
