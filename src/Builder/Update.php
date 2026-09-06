<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Connection;
use Simsoft\DB\Traits\Condition;
use Simsoft\DB\Traits\Ignore;
use Simsoft\DB\Traits\LowPriority;

/**
 * Update Query Builder Class
 */
class Update extends Builder
{
    use LowPriority, Ignore, Condition;

    /** @var array<int, string> */
    protected array $set = [];

    /** @var array<int, string> Columns to return via RETURNING clause */
    protected array $returningColumns = [];

    /** @var array<int, array<string, mixed>>|null Rows returned by RETURNING clause */
    protected ?array $returningResult = null;

    /**
     * Constructor
     *
     * @param string $table The table name.
     * @param array<string, mixed> $attributes The attributes => values to be updated.
     * @param string|ActiveQuery|Raw|null $condition
     */
    public function __construct(
        protected string $table,
        protected array $attributes = [],
        string|ActiveQuery|Raw|null $condition = null
    )
    {
        if ($condition instanceof ActiveQuery) {
            $this->setPlaceHolder($condition->getPlaceHolder());
        }
        $this->condition($condition);
    }

    /**
     * Add a RETURNING clause to the UPDATE statement.
     *
     * Supported by PostgreSQL and SQLite 3.35+.
     *
     * @param string ...$columns The columns to return. Empty = RETURNING *.
     * @return static
     */
    public function returning(string ...$columns): static
    {
        $this->returningColumns = array_values($columns);
        return $this;
    }

    /**
     * Check if this UPDATE has a RETURNING clause.
     *
     * @return bool
     */
    public function hasReturning(): bool
    {
        return !empty($this->returningColumns) || $this->returningResult !== null;
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
     * Set counter.
     *
     * @param string $attribute The attribute to increment/decrement.
     * @param int|float $value The counter value.
     * @return static
     */
    public function setCounter(string $attribute, int|float $value): static
    {
        $quoted = $this->quoteColumn($attribute);

        if ($value == 0) {
            $this->set[] = "$quoted = {$this->getPlaceHolder()}";
            $this->appendBinds($value);
            return $this;
        }

        $operator = $value > 0 ? '+' : '-';
        $this->set[] = "$quoted = $quoted $operator {$this->getPlaceHolder()}";
        $this->appendBinds(abs($value));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $data = [];
        foreach ($this->attributes as $attribute => $value) {
            $data[] = $this->quoteColumn($attribute) . " = {$this->getPlaceHolder()}";
            $this->appendBinds($value);
        }

        $sets = array_merge($data, $this->set);

        $sql = implode(' ', array_filter([
            'UPDATE',
            $this->lowPriorityModifier(),
            $this->ignoreModifier(),
            $this->quoteTable($this->table),
            'SET ' . ($sets ? implode(', ', $sets) : '1 = 1'),
            $this->getCondition(),
        ]));

        // Append RETURNING clause if columns specified
        if (!empty($this->returningColumns)) {
            $grammar = Connection::grammar($this->connection);
            if ($grammar->supportsReturning()) {
                $sql .= ' ' . $grammar->returningColumnsSQL($this->returningColumns);
            }
        }

        return $this->getQualifiedSQL($sql);
    }
}
