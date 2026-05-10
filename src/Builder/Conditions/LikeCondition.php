<?php

namespace Simsoft\DB\MySQL\Builder\Conditions;

use Simsoft\DB\MySQL\Builder\Clauses\Clause;

/**
 * LikeCondition clause
 */
class LikeCondition extends Clause
{
    /** @var string Logical operator. Default: AND */
    protected string $logicalOperator = 'AND';

    /**
     * Enable to match all keywords.
     *
     * @param bool $enabled Enable to match all keywords.
     * @return $this
     */
    public function matchAll(bool $enabled = true): static
    {
        $this->logicalOperator = $enabled ? 'AND' : 'OR';
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $attribute = $this->queryAttribute($this->attribute);
        $operator = $this->is ? 'LIKE' : 'NOT LIKE';

        if (is_array($this->value)) {
            $conditions = [];
            foreach ($this->value as $value) {
                $conditions[] = "$attribute $operator {$this->getPlaceHolder()}";
                $this->appendBinds($value);
            }

            return '(' . implode(" $this->logicalOperator ", $conditions) . ')';
        }

        $this->appendBinds($this->value);
        return "$attribute $operator {$this->getPlaceHolder()}";
    }
}
