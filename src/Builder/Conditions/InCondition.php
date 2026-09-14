<?php

namespace Simsoft\DB\Builder\Conditions;

use InvalidArgumentException;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Raw;

/**
 * Class InCondition
 *
 * Set membership: IN and NOT IN, over a list of values, a subquery or a Raw
 * expression.
 */
class InCondition extends Clause
{
    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException If the value cannot form a set.
     */
    protected function buildSQL(): string
    {
        // An empty list is not a missing condition, it is a set with nothing
        // in it: no row is a member, and every row is a non-member. Both of
        // those are constants, so neither needs the column.
        if ($this->value === []) {
            return $this->emptySetSQL();
        }

        return $this->queryAttribute($this->attribute)
            . ($this->is ? '' : ' NOT')
            . ' IN (' . $this->setSQL() . ')';
    }

    /**
     * Build the parenthesised set.
     *
     * The three branches used to be consecutive ifs with no else and no final
     * throw, so a value that was none of them fell through all three and left
     * the set empty — `id IN ()`, which every engine rejects as a syntax
     * error, reported from the server with nothing pointing back at the call
     * that built it.
     *
     * @return string
     * @throws InvalidArgumentException If the value cannot form a set.
     */
    private function setSQL(): string
    {
        if (is_array($this->value)) {
            $this->appendBinds($this->value);
            return implode(',', array_fill(0, count($this->value), $this->getPlaceHolder()));
        }

        if ($this->value instanceof ActiveQuery) {
            // Ask for the SQL first: a builder collects its binds while it
            // builds, so reading them beforehand reads them too early.
            $sql = $this->value->getSQL();
            $this->absorbBinds($this->value->getBinds());
            return $sql;
        }

        if ($this->value instanceof Raw) {
            $this->absorbBinds($this->value->getBinds());
            return (string)$this->value;
        }

        throw new InvalidArgumentException(sprintf(
            '%s on "%s" needs an array, subquery or Raw expression; got %s.',
            $this->is ? 'IN' : 'NOT IN',
            is_string($this->attribute) ? $this->attribute : get_debug_type($this->attribute),
            get_debug_type($this->value)
        ));
    }

    /**
     * Build the constant an empty set reduces to.
     *
     * Skipping the condition instead is only correct half the time and only
     * under AND. `in('id', [])` dropped left the caller's other conditions to
     * answer alone, so an empty allow-list returned the whole table rather
     * than nothing — and under OR the reverse also broke, with
     * `orNotIn('id', [])` matching only what the preceding condition matched
     * when it should have matched everything. The server agrees on all three
     * engines: membership of the empty set is false for every row, including
     * a NULL one, and non-membership is true for every row.
     *
     * @return string
     */
    private function emptySetSQL(): string
    {
        return $this->is ? '1 = 0' : '1 = 1';
    }
}
