<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Traits\Ignore;

/**
 * Insert Query Builder Class.
 *
 */
class Insert extends Builder
{
    use Ignore;

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
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        return $this->getQualifiedSQL(implode(' ', array_filter([
            'INSERT',
            $this->ignoreModifier(),
            'INTO ' . $this->quote($this->table),
            $this->isBulkData() ? $this->bulkData() : $this->normalData(),
        ])));
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
