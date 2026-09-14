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
 *
 * HAVING takes a single boolean expression, so several calls have to be joined
 * with AND or OR the way WHERE conditions are. The logical operator is
 * interleaved into the list here; getHavingSQL() joins the list with spaces.
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
                $this->appendSectionBinds($this->groupBinds, $name->getBinds());
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
        $this->appendSectionBinds($this->groupBinds, $binds);

        return $this;
    }

    /**
     * Having clause.
     *
     * A Raw attribute is used as the expression on its own; an operator and
     * value given beside one are compared against it.
     *
     * @param string|Raw $attribute The attribute or Raw expression.
     * @param string|null $operator The comparison operator or the attribute value.
     * @param mixed|null $value The value for the attribute.
     * @param string $logicalOperator How to join this to the previous entry. Default: 'AND'.
     * @return static
     * @throws InvalidArgumentException If the operator is not on the whitelist,
     *     or its value does not match the shape the operator needs.
     */
    public function having(
        mixed $attribute,
        ?string $operator = '=',
        mixed $value = null,
        string $logicalOperator = 'AND'
    ): static {
        if (is_string($attribute) && $value === null) {
            $resolved = $this->resolveNullHaving($attribute, $operator, $logicalOperator);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($value === null && $operator != '=') {
            $value = $operator;
            $operator = '=';
        }

        $operator = $this->validateOperator($operator ?? '=');
        $this->assertNullComparison($operator, $value);

        $condition = (new Condition($attribute, $value))
            ->operator($operator)
            ->setPlaceHolder($this->getPlaceHolder());

        // Eagerly build and collect binds. The binds are populated while the
        // SQL is built, so they can only be read afterwards.
        $this->addHaving((string)$condition, $logicalOperator);
        $this->appendSectionBinds($this->havingBinds, $condition->getBinds());

        return $this;
    }

    /**
     * Or variant of having().
     *
     * @param string|Raw $attribute The attribute or Raw expression.
     * @param string|null $operator The comparison operator or the attribute value.
     * @param mixed|null $value The value for the attribute.
     * @return static
     */
    public function orHaving(mixed $attribute, ?string $operator = '=', mixed $value = null): static
    {
        return $this->having($attribute, $operator, $value, 'OR');
    }

    /**
     * Having with raw expression.
     *
     * @param string $expression The raw SQL expression.
     * @param array<int, mixed>|null $binds Optional bind values.
     * @param string $logicalOperator How to join this to the previous entry. Default: 'AND'.
     * @return static
     */
    public function havingRaw(string $expression, ?array $binds = null, string $logicalOperator = 'AND'): static
    {
        $this->addHaving($expression, $logicalOperator);
        $this->appendSectionBinds($this->havingBinds, $binds);

        return $this;
    }

    /**
     * Or variant of havingRaw().
     *
     * @param string $expression The raw SQL expression.
     * @param array<int, mixed>|null $binds Optional bind values.
     * @return static
     */
    public function orHavingRaw(string $expression, ?array $binds = null): static
    {
        return $this->havingRaw($expression, $binds, 'OR');
    }

    /**
     * Resolve a NULL check on a string attribute.
     *
     * @param string $attribute The attribute name.
     * @param string|null $operator The operator, or null for the default.
     * @param string $logicalOperator How to join this to the previous entry.
     * @return static|null Null when normal processing should continue.
     */
    private function resolveNullHaving(string $attribute, ?string $operator, string $logicalOperator): ?static
    {
        // where() routes IS and IS NOT to a NULL check; having() did not, so
        // its own value shorthand took the operator as the value and built
        // `HAVING col = 'IS'` — valid SQL, matching nothing, with no error to
        // say the NULL check had been thrown away.
        $sql = match ($operator === null ? '=' : strtoupper(trim($operator))) {
            'IS' => $this->queryAttribute($attribute) . ' IS NULL',
            'IS NOT' => $this->queryAttribute($attribute) . ' IS NOT NULL',
            default => null,
        };

        if ($sql === null) {
            return null;
        }

        $this->addHaving($sql, $logicalOperator);

        return $this;
    }

    /**
     * Append one HAVING fragment, joined to the previous entry.
     *
     * @param string $sql The fragment.
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @return void
     */
    private function addHaving(string $sql, string $logicalOperator): void
    {
        if ($this->having !== []) {
            $this->having[] = $logicalOperator;
        }

        $this->having[] = $sql;
    }
}
