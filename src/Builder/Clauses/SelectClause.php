<?php

namespace Simsoft\DB\Builder\Clauses;

use Simsoft\DB\Builder\Raw;

/**
 * Class SelectClause.
 *
 * Represents a SELECT column list.
 */
class SelectClause extends Clause
{
    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $sql = [];
        foreach ($this->attribute as $attribute) {
            $sql[] = $attribute instanceof Raw
                ? (string)$attribute
                : $this->queryAttribute($attribute);
        }
        return implode(', ', $sql);
    }
}
