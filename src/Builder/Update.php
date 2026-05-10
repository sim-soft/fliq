<?php

namespace Simsoft\DB\MySQL\Builder;

use Simsoft\DB\MySQL\Traits\Condition;
use Simsoft\DB\MySQL\Traits\Ignore;
use Simsoft\DB\MySQL\Traits\LowPriority;

/**
 * Update Query Builder Class
 */
class Update extends Builder
{
    use LowPriority, Ignore, Condition;

    /** @var array */
    protected array $set = [];

    /**
     * Constructor
     *
     * @param string $table The table name.
     * @param array $attributes The attributes => values to be updated.
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
     * Set counter
     *
     * @return $this
     */
    public function setCounter(string $attribute, int|float $value): static
    {
        if ($value) {
            $operator = $value > 0 ? '+' : '-';
            $this->set[] = "`$attribute` = `$attribute` $operator {$this->getPlaceHolder()}";
            $this->appendBinds(abs($value));
        } else {
            $this->set[] = "`$attribute` = {$this->getPlaceHolder()}";
            $this->appendBinds($value);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $data = [];
        foreach ($this->attributes as $attribute => $value) {
            $data[] = "`$attribute` = {$this->getPlaceHolder()}";
            $this->appendBinds($value);
        }

        $sets = array_merge($data, $this->set);

        $sql = implode(' ', array_filter([
            'UPDATE',
            $this->lowPriorityModifier(),
            $this->ignoreModifier(),
            "`$this->table`",
            'SET ' . ($sets ? implode(', ', $sets) : '1 = 1'),
            $this->getCondition(),
        ]));

        return $this->getQualifiedSQL($sql);
    }
}
