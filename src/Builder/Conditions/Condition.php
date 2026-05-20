<?php

namespace Simsoft\DB\Builder\Conditions;

use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Raw;

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
            return $this->buildArrayCondition();
        }

        if ($this->attribute instanceof Raw) {
            $rawBinds = $this->attribute->getBinds();
            if ($rawBinds !== null) {
                $this->appendBinds($rawBinds);
            }
            return (string)$this->attribute;
        }

        if ($this->value === null) {
            $this->appendBinds($this->operator);
            $this->operator = '=';
        }

        if ($this->value !== null) {
            $this->appendBinds($this->value);
        }

        return "{$this->queryAttribute($this->attribute)} $this->operator {$this->getPlaceHolder()}";
    }

    /**
     * Build SQL for array-based conditions.
     *
     * @return string
     */
    private function buildArrayCondition(): string
    {
        $sql = [];
        if (array_is_list($this->attribute)) {
            foreach ($this->attribute as [$field, $operator, $value]) {
                $upperOp = strtoupper($operator);
                if (in_array($upperOp, ['IN', 'NOT IN'])) {
                    $sql[] = "{$this->queryAttribute($field)} $upperOp ("
                        . implode(',', array_fill(0, count($value), $this->getPlaceHolder()))
                        . ')';
                }

                if (!in_array($upperOp, ['IN', 'NOT IN'])) {
                    $sql[] = "{$this->queryAttribute($field)} $upperOp ?";
                }

                $this->appendBinds($value);
            }

            return implode(' AND ', $sql);
        }

        foreach ($this->attribute as $field => $value) {
            if (is_array($value)) {
                $sql[] = "{$this->queryAttribute($field)} IN ("
                    . implode(',', array_fill(0, count($value), $this->getPlaceHolder()))
                    . ')';
            }

            if (!is_array($value)) {
                $sql[] = "{$this->queryAttribute($field)} = ?";
            }

            $this->appendBinds($value);
        }

        return $sql ? '(' . implode(' AND ', $sql) . ')' : '';
    }
}
