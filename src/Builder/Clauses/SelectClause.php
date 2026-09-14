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
            if ($attribute instanceof Raw) {
                $sql[] = (string)$attribute;

                // A Raw column may carry placeholders of its own —
                // `IF(score > ?, 1, 0) AS grade`. Its values were dropped, so
                // the statement was left short and the driver refused to run it
                // at all. select() takes the same care with a bare Raw; a Raw
                // wrapped in a clause is no different.
                $this->absorbBinds($attribute->getBinds());
                continue;
            }

            $sql[] = $this->queryAttribute($attribute);
        }
        return implode(', ', $sql);
    }
}
