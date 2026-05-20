<?php

namespace Simsoft\DB\Grammar;

/**
 * Grammar interface.
 *
 * Defines database-specific SQL syntax (quoting, LIMIT, upsert, etc.).
 */
interface Grammar
{
    /**
     * Quote an identifier (table or column name).
     *
     * @param string $identifier The identifier to quote.
     * @return string
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Build LIMIT/OFFSET clause.
     *
     * @param int $limit The limit value.
     * @param int|null $offset The offset value.
     * @return string
     */
    public function limitSQL(int $limit, ?int $offset = null): string;

    /**
     * Build INSERT IGNORE syntax.
     *
     * @return string The INSERT keyword with ignore modifier.
     */
    public function insertIgnoreSQL(): string;

    /**
     * Build UPSERT (insert or update on conflict) SQL.
     *
     * @param string $table The quoted table name.
     * @param array<int, string> $columns The column names.
     * @param array<int, string> $updateColumns Columns to update on conflict.
     * @param string $placeholders The VALUES placeholders.
     * @param array<int, string> $conflictColumns Columns that form the unique constraint for conflict detection.
     * @return string
     */
    public function upsertSQL(string $table, array $columns, array $updateColumns, string $placeholders, array $conflictColumns = []): string;

    /**
     * Get the driver name identifier.
     *
     * @return string
     */
    public function getDriverName(): string;

    /**
     * Build a JSON path extraction expression.
     *
     * @param string $column The JSON column name (quoted).
     * @param string $path The JSON path (e.g., 'age', 'address.city').
     * @param bool $asText Whether to extract as text (unquoted) or JSON value.
     * @return string
     */
    public function jsonExtract(string $column, string $path, bool $asText = true): string;

    /**
     * Build a JSON contains expression.
     *
     * @param string $column The JSON column name (quoted).
     * @param string $path The JSON path.
     * @return string The SQL expression with a ? placeholder for the value.
     */
    public function jsonContains(string $column, string $path): string;

    /**
     * Build a JSON length expression.
     *
     * @param string $column The JSON column name (quoted).
     * @param string $path The JSON path.
     * @return string
     */
    public function jsonLength(string $column, string $path): string;

    /**
     * Build a DATE extraction expression.
     *
     * @param string $column The column expression.
     * @return string
     */
    public function dateExtract(string $column): string;

    /**
     * Build a MONTH extraction expression.
     *
     * @param string $column The column expression.
     * @return string
     */
    public function monthExtract(string $column): string;

    /**
     * Build a YEAR extraction expression.
     *
     * @param string $column The column expression.
     * @return string
     */
    public function yearExtract(string $column): string;

    /**
     * Build a TIME extraction expression.
     *
     * @param string $column The column expression.
     * @return string
     */
    public function timeExtract(string $column): string;
}
