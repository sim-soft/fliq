<?php

namespace Simsoft\DB\Builder;

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
     * Set counter.
     *
     * @param string $attribute The attribute to increment/decrement.
     * @param int|float $value The counter value.
     * @return static
     */
    public function setCounter(string $attribute, int|float $value): static
    {
        $quoted = $this->quote($attribute);

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
            $data[] = $this->quote($attribute) . " = {$this->getPlaceHolder()}";
            $this->appendBinds($value);
        }

        $sets = array_merge($data, $this->set);

        $sql = implode(' ', array_filter([
            'UPDATE',
            $this->lowPriorityModifier(),
            $this->ignoreModifier(),
            $this->quote($this->table),
            'SET ' . ($sets ? implode(', ', $sets) : '1 = 1'),
            $this->getCondition(),
        ]));

        return $this->getQualifiedSQL($sql);
    }
}
