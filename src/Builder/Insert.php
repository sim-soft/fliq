<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Connection;
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
        $grammar = Connection::grammar($this->connection);

        // PostgreSQL/SQLite: grammar provides full INSERT...ON CONFLICT DO NOTHING SQL
        if ($this->ignore) {
            $fullSQL = $this->buildIgnoreSQL($grammar);
            if ($fullSQL !== null) {
                return $this->getQualifiedSQL($fullSQL);
            }
        }

        // MySQL uses INSERT IGNORE keyword; otherwise plain INSERT
        $insertKeyword = $this->ignore ? $grammar->insertIgnoreSQL() : 'INSERT';

        $sql = implode(' ', array_filter([
            $insertKeyword,
            'INTO ' . $this->quote($this->table),
            $this->isBulkData() ? $this->bulkData() : $this->normalData(),
        ]));

        // Append RETURNING clause if set
        if ($this->returningColumn !== null && $grammar->supportsReturning()) {
            $sql .= ' ' . $grammar->returningSQL($this->returningColumn);
        }

        return $this->getQualifiedSQL($sql);
    }

    /**
     * Build INSERT IGNORE SQL using grammar-specific full override.
     *
     * @param \Simsoft\DB\Grammar\Grammar $grammar The grammar instance.
     * @return string|null Full SQL if grammar provides override, null otherwise.
     */
    private function buildIgnoreSQL(\Simsoft\DB\Grammar\Grammar $grammar): ?string
    {
        /** @var array<int, string> $columns */
        $columns = $this->isBulkData()
            ? array_map('strval', array_keys($this->attributes[0]))
            : array_map('strval', array_keys($this->attributes));
        $quotedTable = $this->quote($this->table);

        if ($this->isBulkData()) {
            // Check if grammar supports full override before appending binds
            $placeholders = $this->getBulkPlaceholders();
            $fullSQL = $grammar->insertIgnoreFullSQL($quotedTable, $columns, $placeholders);
            if ($fullSQL === null) {
                return null;
            }
            $this->bulkData();
            return $fullSQL;
        }

        $placeholders = implode(',', array_fill(0, count($this->attributes), $this->getPlaceHolder()));
        $fullSQL = $grammar->insertIgnoreFullSQL($quotedTable, $columns, $placeholders);
        if ($fullSQL === null) {
            return null;
        }
        $this->appendBinds(array_values($this->attributes));

        return $fullSQL;
    }

    /**
     * Get bulk insert placeholders string.
     *
     * @return string
     */
    private function getBulkPlaceholders(): string
    {
        $columns = array_keys($this->attributes[0]);
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), $this->getPlaceHolder())) . ')';
        return implode(',', array_fill(0, count($this->attributes), $rowPlaceholder));
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
     * Normalizes all rows to have the same columns (based on the first row).
     * Missing keys in subsequent rows default to null.
     *
     * @return string
     */
    protected function bulkData(): string
    {
        $columns = array_keys($this->attributes[0]);
        $data = [];

        foreach ($this->attributes as $attributes) {
            $data[] = '(' . implode(',', array_fill(0, count($columns), $this->getPlaceHolder())) . ')';
            // Normalize row to match column order, defaulting missing keys to null
            $row = [];
            foreach ($columns as $col) {
                $row[] = $attributes[$col] ?? null;
            }
            $this->appendBinds($row);
        }

        return implode(' VALUES ', [
            '(' . implode(', ', $this->getAttributes($this->attributes[0])) . ')',
            implode(',', $data),
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
            fn($attribute) => $this->quote((string)$attribute),
            array_keys($attributes)
        );
    }
}
