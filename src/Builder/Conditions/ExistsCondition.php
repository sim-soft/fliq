<?php

namespace Simsoft\DB\MySQL\Builder\Conditions;

use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Clauses\Clause;
use Simsoft\DB\MySQL\Builder\Raw;

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
        $sql = ($this->is ? '' : 'NOT') . ' EXISTS (';
        $sql .= match (true) {
            $this->attribute instanceof ActiveQuery => $this->attribute->buildSQL(false),
            $this->attribute instanceof Raw => (string)$this->attribute,
            default => null
        };

        return $sql . ')';
    }
}
