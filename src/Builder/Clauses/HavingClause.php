<?php

namespace Simsoft\DB\MySQL\Builder\Clauses;

use Simsoft\DB\MySQL\Builder\Raw;

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
        $this->operator = $operator;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        if ($this->value !== null) {
            $this->appendBinds($this->value);
        }

        return match (true) {
            $this->attribute instanceof Raw => (string)$this->attribute,
            default => "{$this->queryAttribute($this->attribute)} $this->operator ?",
        };
    }
}
