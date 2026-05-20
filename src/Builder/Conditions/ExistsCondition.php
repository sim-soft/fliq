<?php

namespace Simsoft\DB\Builder\Conditions;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Raw;

/**
 * Class ExistsCondition
 *
 */
class ExistsCondition extends Clause
{
    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $this->appendBinds($this->attribute->getBinds());
        $prefix = $this->is ? '' : 'NOT ';
        $sql = $prefix . 'EXISTS (';

        if ($this->attribute instanceof ActiveQuery) {
            $sql .= $this->attribute->getSQL();
        }

        if ($this->attribute instanceof Raw) {
            $sql .= (string)$this->attribute;
        }

        return $sql . ')';
    }
}
