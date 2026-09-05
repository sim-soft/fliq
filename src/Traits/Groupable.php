<?php

namespace Simsoft\DB\Traits;

use InvalidArgumentException;
use Simsoft\DB\Builder\Conditions\Condition;
use Simsoft\DB\Builder\Raw;

/**
 * Grouping and post-aggregation filtering.
 *
 * Builds GROUP BY and HAVING. HAVING is filtered after aggregation, so unlike
 * WHERE it may reference aggregate functions — usually via a Raw expression.
 */
trait Groupable
{
    /**
     * Group by statement.
     *
     * Accepts column names or Raw expressions.
     *
     * @param string|Raw ...$attributes The attributes or raw expressions.
     * @return static
     */
    public function groupBy(string|Raw ...$attributes): static
    {
        foreach ($attributes as $name) {
            if ($name instanceof Raw) {
                $this->groupBys[] = (string)$name;
                if ($name->getBinds()) {
                    $this->appendBinds($name->getBinds());
                }
                continue;
            }
            $this->groupBys[] = $this->queryAttribute($name);
        }

        return $this;
    }

    /**
     * Group by raw expression.
     *
     * @param string $expression The raw SQL expression.
     * @param array<int, mixed>|null $binds Optional bind values.
     * @return static
     */
    public function groupByRaw(string $expression, ?array $binds = null): static
    {
        $this->groupBys[] = $expression;
        if ($binds !== null) {
            $this->appendBinds($binds);
        }
        return $this;
    }

    /**
     * Having clause.
     *
     * @param string|Raw $attribute The attribute or Raw expression.
     * @param string|null $operator The comparison operator or the attribute value.
     * @param mixed|null $value The value for the attribute.
     * @return static
     * @throws InvalidArgumentException If the operator is not on the whitelist,
     *     or its value does not match the shape the operator needs.
     */
    public function having(mixed $attribute, ?string $operator = '=', mixed $value = null): static
    {
        if ($value === null && $operator != '=') {
            $value = $operator;
            $operator = '=';
        }

        $condition = (new Condition($attribute, $value))
            ->operator($this->validateOperator($operator ?? '='))
            ->setPlaceHolder($this->getPlaceHolder());

        // Eagerly build and collect binds. The binds are populated while the
        // SQL is built, so they can only be read afterwards.
        $this->having[] = (string)$condition;
        if ($condition->getBinds()) {
            $this->appendBinds($condition->getBinds());
        }

        return $this;
    }

    /**
     * Having with raw expression.
     *
     * @param string $expression The raw SQL expression.
     * @param array<int, mixed>|null $binds Optional bind values.
     * @return static
     */
    public function havingRaw(string $expression, ?array $binds = null): static
    {
        $this->having[] = $expression;
        if ($binds !== null) {
            $this->appendBinds($binds);
        }
        return $this;
    }
}
