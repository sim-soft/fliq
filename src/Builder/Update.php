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

    /**
     * @var array<int, array{string, int|float}> Counter assignments, applied at build time.
     *
     * These used to be rendered and bound the moment setCounter() was called.
     * Two things followed from that. The column was quoted for whichever
     * grammar was current then, and Model::updateCounter() calls setCounter()
     * before withConnection() — so on PostgreSQL the statement was built with
     * MySQL backticks and the server rejected it outright. And the value was
     * bound ahead of the attribute values, though the assignment it belongs to
     * is emitted after them, so update(['name' => 'x']) combined with a counter
     * sent the two values in the wrong order and wrote each into the other's
     * column. Both go away once the rendering happens in the same pass, and in
     * the same order, as everything else.
     */
    protected array $counters = [];

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
        $this->invalidateSQL();
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
        // Validated here rather than only at build time so an invalid column
        // name is refused by the call that named it, which is where the caller
        // can see it. The quoted form is discarded: it belongs to whichever
        // grammar is current now, and the build quotes it again for whichever
        // grammar is current then.
        $this->quoteColumn($attribute);

        $this->counters[] = [$attribute, $value];
        $this->invalidateSQL();

        return $this;
    }

    /**
     * Render the counter assignments, binding their values in place.
     *
     * @return array<int, string>
     */
    private function buildCounterSQL(): array
    {
        $sets = [];

        foreach ($this->counters as [$attribute, $value]) {
            $quoted = $this->quoteColumn($attribute);

            if ($value == 0) {
                $sets[] = "$quoted = {$this->getPlaceHolder()}";
                $this->appendBinds($value);
                continue;
            }

            $operator = $value > 0 ? '+' : '-';
            $sets[] = "$quoted = $quoted $operator {$this->getPlaceHolder()}";
            $this->appendBinds(abs($value));
        }

        return $sets;
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

        // Appended after the attribute assignments because that is the order
        // they are emitted in, and the binds have to arrive in the order their
        // placeholders do.
        $sets = array_merge($data, $this->buildCounterSQL());

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
