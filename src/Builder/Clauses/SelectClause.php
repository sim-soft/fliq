<?php

namespace Simsoft\DB\MySQL\Builder\Clauses;

use Simsoft\DB\MySQL\Builder\Raw;

/**
 * Class SelectClause
 *
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
            $sql[] = match (true) {
                $attribute instanceof Raw => (string)$attribute,
                default => $this->queryAttribute($attribute),
            };
        }
        return implode(', ', $sql);
    }
}
