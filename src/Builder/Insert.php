<?php

namespace Simsoft\DB\MySQL\Builder;

use Simsoft\DB\MySQL\Traits\Ignore;

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
     * @param array $attributes Attributes => values to be inserted.
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
            "INTO `$this->table`",
            $this->isBulkData() ? $this->bulkData() : $this->normalData(),
        ])));
    }

    protected function isBulkData(): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($this->attributes);
        } else {
            return is_numeric(array_key_first($this->attributes));
        }
    }

    protected function normalData(): string
    {
        $this->appendBinds(array_values($this->attributes));

        return implode(' VALUES ', [
            '(' . implode(', ', $this->getAttributes($this->attributes)) . ')',
            '(' . implode(',', array_fill(0, count($this->attributes), $this->getPlaceHolder())) . ')',
        ]);
    }

    protected function bulkData(): string
    {
        $data = [];
        foreach ($this->attributes as $attributes) {
            $data[] = '(' . implode(',', array_fill(0, count($attributes), $this->getPlaceHolder())) . ')';
            $this->appendBinds(array_values($attributes));
        }

        return implode(' VALUES ', [
            '(' . implode(', ', $this->getAttributes($this->attributes[0])) . ')',
            implode(',', $data),
        ]);
    }

    protected function getAttributes(array $attributes): array
    {
        return array_map(
            function ($attribute) {
                return "`$attribute`";
            },
            array_keys($attributes)
        );
    }
}
