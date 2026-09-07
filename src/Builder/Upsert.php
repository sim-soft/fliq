<?php

namespace Simsoft\DB\Builder;

use InvalidArgumentException;
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

        // INSERT INTO t () VALUES () is not a statement any engine accepts. On
        // MySQL it reached the server and was rejected there; on the engines
        // that need a conflict target, $columns[0] was read off an empty array
        // and raised "Undefined array key 0" followed by a TypeError naming a
        // line in the grammar. Insert refuses the same shape for the same
        // reason.
        if ($this->attributes === []) {
            throw new InvalidArgumentException(
                'UPSERT requires at least one column => value pair; none were given.'
            );
        }

        $columns = array_keys($this->attributes);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // Every name here is interpolated into the statement and none can be
        // bound, so each is checked before it is quoted: the grammars double an
        // embedded quote rather than reject it, which left a column name
        // carrying its own punctuation usable as SQL.
        $this->assertIdentifiers($columns);
        $this->assertIdentifiers($this->conflictColumns);

        $this->appendBinds(array_values($this->attributes));

        $sql = 'INSERT INTO ' . $this->quoteTable($this->table)
            . ' (' . implode(', ', array_map(
                fn(string $col): string => $grammar->quoteIdentifier($col),
                $columns
            )) . ')'
            . " VALUES ($placeholders)";

        // One path for every engine. There used to be two — a MySQL branch here
        // and a call to the grammar for everything else — and only the MySQL
        // one understood an explicit update value, so ['v' => 'x'] wrote 'x' on
        // MySQL and the inserted value on PostgreSQL and SQLite. The assignments
        // are the part that does not vary by engine; only the wrapper does.
        return $sql . $grammar->onConflictSQL(
            $this->buildAssignments($grammar, $columns),
            $this->resolveConflictColumns($grammar, $columns)
        );
    }

    /**
     * The `col = expr` clauses that follow the conflict keyword.
     *
     * A numeric entry names a column that takes the value the INSERT carried; a
     * string key names one that takes the value given beside it, which is bound
     * rather than interpolated.
     *
     * @param Grammar $grammar The connection's grammar.
     * @param array<int, string> $columns All insert columns.
     * @return array<int, string>
     */
    private function buildAssignments(Grammar $grammar, array $columns): array
    {
        if (empty($this->updateColumns)) {
            return array_map(
                fn(string $col): string => $this->quoteColumn($col) . ' = ' . $grammar->excludedColumnSQL($col),
                $columns
            );
        }

        $assignments = [];

        foreach ($this->updateColumns as $col => $value) {
            if (is_int($col)) {
                $name = (string)$value;
                $assignments[] = $this->quoteColumn($name) . ' = ' . $grammar->excludedColumnSQL($name);
                continue;
            }

            $assignments[] = $this->quoteColumn($col) . ' = ?';
            $this->appendBinds($value);
        }

        return $assignments;
    }

    /**
     * Which columns the conflict is detected on.
     *
     * @param Grammar $grammar The connection's grammar.
     * @param array<int, string> $columns All insert columns.
     * @return array<int, string>
     */
    private function resolveConflictColumns(Grammar $grammar, array $columns): array
    {
        if ($this->conflictColumns !== [] || !$grammar->requiresConflictTarget()) {
            return $this->conflictColumns;
        }

        // PostgreSQL and SQLite reject a target that is not backed by a unique
        // constraint, and the first inserted column usually is not one. Falling
        // back to it produced a statement the engine refused outright, which is
        // still better than guessing a target that happens to parse and then
        // updating on the wrong key.
        return [$columns[0]];
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

}
