<?php

namespace Simsoft\DB\MySQL\Builder\Conditions;

use Simsoft\DB\MySQL\Builder\Clauses\Clause;
use Simsoft\DB\MySQL\Builder\Raw;

/**
 * Class WhereCondition
 *
 */
class Condition extends Clause
{
    /** @var string The condition operator */
    protected string $operator = '=';

    /**
     * Set the condition operator
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
        if (is_array($this->attribute)) {
            $sql = [];
            if (array_is_list($this->attribute)) {
                foreach ($this->attribute as [$field, $operator, $value]) {
                    if (in_array($operator = strtoupper($operator), ['IN', 'NOT IN'])) {
                        $sql[] = "{$this->queryAttribute($field)} $operator ("
                            . implode(',', array_fill(0, count($value), $this->getPlaceHolder()))
                            . ')';
                    } else {
                        $sql[] = "{$this->queryAttribute($field)} $operator ?";
                    }
                    $this->appendBinds($value);
                }
            } else {
                foreach ($this->attribute as $field => $value) {
                    if (is_array($value)) {
                        $sql[] = "{$this->queryAttribute($field)} IN ("
                            . implode(',', array_fill(0, count($value), $this->getPlaceHolder()))
                            . ')';
                    } else {
                        $sql[] = "{$this->queryAttribute($field)} = ?";
                    }
                    $this->appendBinds($value);
                }
            }

            return $sql ? '(' . implode(' AND ', $sql) . ')' : '';
        }

        if ($this->attribute instanceof Raw) {
            $this->appendBinds($this->attribute->getBinds());
            return (string)$this->attribute;
        }

        if ($this->value === null) {
            $this->appendBinds($this->operator);
            $this->operator = '=';
        } else {
            $this->appendBinds($this->value);
        }

        return "{$this->queryAttribute($this->attribute)} $this->operator {$this->getPlaceHolder()}";

    }
}
