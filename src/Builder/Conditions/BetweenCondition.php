<?php

namespace Simsoft\DB\Builder\Conditions;

use InvalidArgumentException;
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
     *
     * @throws InvalidArgumentException If the bounds are not exactly two values.
     */
    protected function buildSQL(): string
    {
        // The clause always emits two placeholders, but bound whatever it was
        // given. Any other count left the statement with a placeholder/bind
        // mismatch, which the driver reports far from the call responsible
        // ("must consist of ... elements") rather than naming the attribute.
        if (!is_array($this->value) || count($this->value) !== 2) {
            throw new InvalidArgumentException(sprintf(
                'between() on "%s" needs exactly two bounds; got %s.',
                is_scalar($this->attribute) ? (string)$this->attribute : get_debug_type($this->attribute),
                is_array($this->value) ? count($this->value) . ' values' : get_debug_type($this->value)
            ));
        }

        $this->appendBinds($this->value);
        return match (true) {
                $this->attribute instanceof Raw => (string)$this->attribute,
                default => $this->queryAttribute($this->attribute),
        }
            . ($this->is ? '' : ' NOT')
            . " BETWEEN {$this->getPlaceHolder()} AND {$this->getPlaceHolder()}";
    }
}
