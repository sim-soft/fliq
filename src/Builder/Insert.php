<?php

namespace Simsoft\DB\Builder;

use InvalidArgumentException;
use Simsoft\DB\Connection;
use Simsoft\DB\Grammar\Grammar;
use Simsoft\DB\Traits\Ignore;

/**
 * Insert Query Builder Class.
 *
 */
class Insert extends Builder
{
    use Ignore;

    /** @var string|null Column to return via RETURNING clause */
    protected ?string $returningColumn = null;

    /** @var array<int, array<string, mixed>>|null Rows returned by RETURNING clause */
    protected ?array $returningResult = null;

    /**
     * Constructor
     *
     * @param string $table The table name.
     * @param array<string|int, mixed> $attributes Attributes => values to be inserted.
     */
    public function __construct(protected string $table, protected array $attributes = [])
    {
    }

    /**
     * Add a RETURNING clause to the INSERT statement.
     *
     * Supported by PostgreSQL and SQLite 3.35+.
     *
     * @param string $column The column to return.
     * @return static
     */
    public function returning(string $column): static
    {
        $this->returningColumn = $column;
        $this->invalidateSQL();
        return $this;
    }

    /**
     * Get the result from a RETURNING clause execution.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getReturningResult(): ?array
    {
        return $this->returningResult;
    }

    /**
     * Set the RETURNING result after execution.
     *
     * @param array<int, array<string, mixed>> $result The returned rows.
     * @return void
     */
    public function setReturningResult(array $result): void
    {
        $this->returningResult = $result;
    }

    /**
     * Check if this INSERT has a RETURNING clause.
     *
     * @return bool
     */
    public function hasReturning(): bool
    {
        return $this->returningColumn !== null;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $this->assertHasData();

        $grammar = Connection::grammar($this->connection);

        // PostgreSQL/SQLite: grammar provides full INSERT...ON CONFLICT DO NOTHING SQL
        if ($this->ignore) {
            $fullSQL = $this->buildIgnoreSQL($grammar);
            if ($fullSQL !== null) {
                // The grammar's override ends at DO NOTHING and dropped the
                // RETURNING clause with it, so a caller asking for one got no
                // rows back and no indication why. The clause belongs after the
                // conflict action, where it returns a row when the insert
                // happened and none when it was skipped.
                if ($this->returningColumn !== null && $grammar->supportsReturning()) {
                    $fullSQL .= ' ' . $grammar->returningSQL($this->returningColumn);
                }

                return $this->getQualifiedSQL($fullSQL);
            }
        }

        // MySQL uses INSERT IGNORE keyword; otherwise plain INSERT
        $insertKeyword = $this->ignore ? $grammar->insertIgnoreSQL() : 'INSERT';

        $sql = implode(' ', array_filter([
            $insertKeyword,
            'INTO ' . $this->quoteTable($this->table),
            $this->isBulkData() ? $this->bulkData() : $this->normalData(),
        ]));

        // Append RETURNING clause if set
        if ($this->returningColumn !== null && $grammar->supportsReturning()) {
            $sql .= ' ' . $grammar->returningSQL($this->returningColumn);
        }

        return $this->getQualifiedSQL($sql);
    }

    /**
     * Refuse a statement that names no columns to insert.
     *
     * INSERT INTO `user` () VALUES () is not a statement any engine accepts,
     * and the shapes that produced it — no attributes at all, a list of bare
     * values with no column names, a bulk set whose first row is empty — all
     * come from the caller passing something other than column => value pairs.
     * Left alone the empty array raised "Undefined array key 0" followed by a
     * TypeError out of array_keys(), naming a line in this class rather than
     * the argument at fault.
     *
     * @return void
     * @throws InvalidArgumentException If there is nothing to insert.
     */
    private function assertHasData(): void
    {
        if ($this->attributes === []) {
            throw new InvalidArgumentException(
                'INSERT requires at least one column => value pair; none were given.'
            );
        }

        if (!$this->isBulkData()) {
            return;
        }

        foreach ($this->attributes as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException(sprintf(
                    'A bulk INSERT takes an array of rows, each a column => value map; row %d is %s. '
                    . 'To insert a single row, pass the map itself rather than a list.',
                    $index,
                    get_debug_type($row)
                ));
            }

            if ($row === []) {
                throw new InvalidArgumentException(
                    "A bulk INSERT row must name at least one column; row $index is empty."
                );
            }
        }
    }

    /**
     * Build INSERT IGNORE SQL using grammar-specific full override.
     *
     * @param Grammar $grammar The grammar instance.
     * @return string|null Full SQL if grammar provides override, null otherwise.
     */
    private function buildIgnoreSQL(Grammar $grammar): ?string
    {
        $columns = $this->columnNames();
        $quotedTable = $this->quoteTable($this->table);

        if ($this->isBulkData()) {
            // Check if grammar supports full override before appending binds.
            //
            // The grammars wrap what they are handed in a single pair of
            // parentheses, which is right for one row's placeholders and wrong
            // for a bulk set that brings its own. Written as "VALUES ((?,?),
            // (?,?))" PostgreSQL read the whole thing as one row holding two
            // row-constructors, and refused it as having fewer expressions than
            // target columns — so bulk insertOrIgnore never ran there at all.
            $fullSQL = $grammar->insertIgnoreFullSQL(
                $quotedTable,
                $columns,
                $this->rowPlaceholders($columns)
            );
            if ($fullSQL === null) {
                return null;
            }
            $this->appendBulkBinds($columns);
            return $fullSQL;
        }

        $placeholders = '(' . implode(',', array_fill(0, count($this->attributes), $this->getPlaceHolder())) . ')';
        $fullSQL = $grammar->insertIgnoreFullSQL($quotedTable, $columns, $placeholders);
        if ($fullSQL === null) {
            return null;
        }
        $this->appendBinds(array_values($this->attributes));

        return $fullSQL;
    }

    /**
     * The column names this statement writes, taken from the first row.
     *
     * @return array<int, string>
     * @throws InvalidArgumentException If a later row names a column the first does not.
     */
    private function columnNames(): array
    {
        if (!$this->isBulkData()) {
            return array_map('strval', array_keys($this->attributes));
        }

        /** @var array<string|int, mixed> $first */
        $first = $this->attributes[0];
        $columns = array_map('strval', array_keys($first));

        foreach ($this->attributes as $index => $row) {
            /** @var array<string|int, mixed> $row */
            $extra = array_diff(array_map('strval', array_keys($row)), $columns);
            if ($extra !== []) {
                // Every row is written against the first row's columns, so a
                // key only a later row has was dropped without a word: the
                // statement inserted, reported success, and left the column at
                // its default. A row short of a column is a different matter —
                // that one is filled with null, which is a reasonable reading of
                // an absent value — but a value the caller supplied and the
                // database never saw is not something to infer an intent from.
                throw new InvalidArgumentException(sprintf(
                    'Bulk INSERT row %d names columns the first row does not: %s. '
                    . 'Every row is inserted against the first row\'s columns, so give them all '
                    . 'the same keys.',
                    $index,
                    implode(', ', $extra)
                ));
            }
        }

        return $columns;
    }

    /**
     * Placeholders for every row, each row parenthesised.
     *
     * @param array<int, string> $columns The columns being written.
     * @return string
     */
    private function rowPlaceholders(array $columns): string
    {
        $row = '(' . implode(',', array_fill(0, count($columns), $this->getPlaceHolder())) . ')';

        return implode(',', array_fill(0, count($this->attributes), $row));
    }

    /**
     * Append every row's values in the column order the statement declares.
     *
     * @param array<int, string> $columns The columns being written.
     * @return void
     */
    private function appendBulkBinds(array $columns): void
    {
        foreach ($this->attributes as $row) {
            /** @var array<string|int, mixed> $row */
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $this->appendBinds($values);
        }
    }

    /**
     * Determine if attributes represent bulk data.
     *
     * @return bool
     */
    protected function isBulkData(): bool
    {
        return array_is_list($this->attributes);
    }

    /**
     * Build SQL for single-row insert.
     *
     * @return string
     */
    protected function normalData(): string
    {
        $this->appendBinds(array_values($this->attributes));

        return implode(' VALUES ', [
            '(' . implode(', ', $this->getAttributes($this->attributes)) . ')',
            '(' . implode(',', array_fill(0, count($this->attributes), $this->getPlaceHolder())) . ')',
        ]);
    }

    /**
     * Build SQL for bulk insert.
     *
     * All rows are written against the first row's columns. A row missing one
     * of them supplies null; a row naming one the first does not is refused by
     * columnNames(), since its value would otherwise be dropped in silence.
     *
     * @return string
     */
    protected function bulkData(): string
    {
        $columns = $this->columnNames();
        $this->appendBulkBinds($columns);

        return implode(' VALUES ', [
            '(' . implode(', ', array_map(fn(string $col): string => $this->quoteColumn($col), $columns)) . ')',
            $this->rowPlaceholders($columns),
        ]);
    }

    /**
     * Get quoted attribute names.
     *
     * @param array<int|string, mixed> $attributes The attributes array.
     * @return array<int, string>
     */
    protected function getAttributes(array $attributes): array
    {
        return array_map(
            fn($attribute) => $this->quoteColumn((string)$attribute),
            array_keys($attributes)
        );
    }
}
