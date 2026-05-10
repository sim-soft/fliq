<?php

namespace Simsoft\DB\MySQL\Builder\Conditions;

use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Clauses\Clause;
use Simsoft\DB\MySQL\Builder\Raw;

/**
 * Class InCondition
 *
 */
class InCondition extends Clause
{
    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $sql = $this->queryAttribute($this->attribute)
            . ($this->is ? '' : ' NOT')
            . ' IN (';

        if (is_array($this->value)) {
            $this->appendBinds($this->value);
            $sql .= implode(',', array_fill(0, count($this->value), $this->getPlaceHolder()));
        } else {
            $this->appendBinds($this->value->getBinds());
            $sql .= match (true) {
                $this->value instanceof ActiveQuery => $this->value->buildSQL(false),
                $this->value instanceof Raw => (string)$this->value,
                default => null,
            };
        }

        return $sql . ')';
    }
}
