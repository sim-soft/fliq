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
        $subBinds = $this->attribute->getBinds();
        if ($subBinds !== null) {
            $this->appendBinds($subBinds);
        }

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
