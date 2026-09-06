<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Connection;
use Simsoft\DB\Traits\Condition;
use Simsoft\DB\Traits\Ignore;
use Simsoft\DB\Traits\LowPriority;

/**
 * Delete Query Builder Class.
 */
class Delete extends Builder
{
    use LowPriority, Ignore, Condition;

    /** @var bool */
    protected bool $quick = false; // used by delete operation only

    /** @var array<int, string> Columns to return via RETURNING clause */
    protected array $returningColumns = [];

    /** @var array<int, array<string, mixed>>|null Rows returned by RETURNING clause */
    protected ?array $returningResult = null;

    /**
     * Constructor.
     *
     * @param string $table Table name.
     * @param string|ActiveQuery|Raw|null $condition
     */
    public function __construct(protected string $table, string|ActiveQuery|Raw|null $condition = null)
    {
        if ($condition instanceof ActiveQuery) {
            $this->setPlaceHolder($condition->getPlaceHolder());
        }
        $this->condition($condition);
    }

    /**
     * Enable quick delete.
     *
     * @return $this
     */
    public function quick(): self
    {
        $this->quick = true;
        return $this;
    }

    /**
     * Add a RETURNING clause to the DELETE statement.
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
     * Check if this DELETE has a RETURNING clause.
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
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $sql = implode(' ', array_filter([
            'DELETE',
            $this->lowPriorityModifier(),
            // QUICK is MySQL's, like LOW_PRIORITY and IGNORE: a MyISAM index
            // hint elsewhere read as the name of the table being deleted from.
            $this->quick && $this->supportsModifiers() ? 'QUICK' : null,
            $this->ignoreModifier(),
            'FROM ' . $this->quoteTable($this->table),
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
