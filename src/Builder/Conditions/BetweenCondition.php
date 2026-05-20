<?php

namespace Simsoft\DB\Builder\Conditions;

use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Raw;

/**
 * Class BetweenCondition
 *
 */
class BetweenCondition extends Clause
{
    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $this->appendBinds($this->value);
        return match (true) {
                $this->attribute instanceof Raw => (string)$this->attribute,
                default => $this->queryAttribute($this->attribute),
            }
            . ($this->is ? '' : ' NOT')
            . " BETWEEN {$this->getPlaceHolder()} AND {$this->getPlaceHolder()}";
    }
}
