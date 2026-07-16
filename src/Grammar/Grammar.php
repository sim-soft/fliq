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

    /**
     * Build INSERT IGNORE SQL for a given table and data.
     *
     * For databases that don't support INSERT IGNORE natively (e.g., PostgreSQL),
     * this method generates the appropriate conflict-handling syntax.
     *
     * @param string $table The quoted table name.
     * @param array<int, string> $columns The column names.
     * @param string $placeholders The VALUES placeholders.
     * @return string|null Full SQL if grammar overrides default behavior, null to use default.
     */
    public function insertIgnoreFullSQL(string $table, array $columns, string $placeholders): ?string;

    /**
     * Build a RETURNING clause for INSERT statements.
     *
     * @param string $column The column to return (typically the primary key).
     * @return string The RETURNING clause, or empty string if not supported.
     */
    public function returningSQL(string $column): string;

    /**
     * Build a RETURNING clause for UPDATE/DELETE statements.
     *
     * @param array<int, string> $columns The columns to return.
     * @return string The RETURNING clause, or empty string if not supported.
     */
    public function returningColumnsSQL(array $columns): string;

    /**
     * Whether the driver supports RETURNING clause on INSERT.
     *
     * @return bool
     */
    public function supportsReturning(): bool;

    /**
     * Build a JSON key exists expression.
     *
     * @param string $column The JSON column name (quoted).
     * @param string $path The JSON path.
     * @return string The SQL expression.
     */
    public function jsonKeyExists(string $column, string $path): string;

    /**
     * Build a row-level locking clause.
     *
     * @param string $lockType The lock type: 'update' or 'share'.
     * @return string The lock clause (e.g., FOR UPDATE, FOR SHARE).
     */
    public function lockSQL(string $lockType): string;

    /**
     * Build a full-text search expression.
     *
     * @param array<int, string> $columns The columns to search.
     * @param string $mode The search mode (e.g., 'plain', 'phrase', 'websearch').
     * @param string $language The text search language/config.
     * @return string The SQL expression with a ? placeholder for the search term.
     */
    public function fulltextSearch(array $columns, string $mode = 'plain', string $language = 'english'): string;

    /**
     * Whether the driver supports full-text search.
     *
     * @return bool
     */
    public function supportsFulltext(): bool;

    /**
     * Build an array contains expression (column @> ARRAY[value]).
     *
     * @param string $column The array column.
     * @param string $type The PostgreSQL array element type (e.g., 'text', 'int').
     * @return string SQL expression with ? placeholder.
     */
    public function arrayContains(string $column, string $type = 'text'): string;

    /**
     * Build an array overlaps expression (column && ARRAY[values]).
     *
     * @param string $column The array column.
     * @param int $count Number of values to check.
     * @param string $type The PostgreSQL array element type.
     * @return string SQL expression with ? placeholders.
     */
    public function arrayOverlaps(string $column, int $count, string $type = 'text'): string;

    /**
     * Whether the driver supports native array columns.
     *
     * @return bool
     */
    public function supportsArrayColumns(): bool;
}
