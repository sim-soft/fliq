<?php

namespace Simsoft\DB\Builder\Conditions;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Raw;

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
        }

        if ($this->value instanceof ActiveQuery) {
            $this->appendBinds($this->value->getBinds());
            $sql .= $this->value->getSQL();
        }

        if ($this->value instanceof Raw) {
            $this->appendBinds($this->value->getBinds());
            $sql .= (string)$this->value;
        }

        return $sql . ')';
    }
}
