<?php

namespace Simsoft\DB\Builder\Clauses;

use Simsoft\DB\Builder\Raw;

/**
 * Class HavingClause
 *
 */
class HavingClause extends Clause
{
    /** @var string The condition operator */
    protected string $operator = '=';

    /**
     * Set condition operator
     *
     * @param string $operator The condition operator.
     * @return self
     */
    public function operator(string $operator): self
    {
        $this->operator = $this->validateOperator($operator);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        if ($this->attribute instanceof Raw) {
            return (string)$this->attribute;
        }

        // The placeholder is emitted unconditionally, so skipping the bind for
        // a null value left the statement one short and the driver rejected it
        // ("must consist of ... elements") without naming the attribute. A null
        // binds like any other value.
        $this->appendBinds($this->value);

        return "{$this->queryAttribute($this->attribute)} $this->operator ?";
    }
}
