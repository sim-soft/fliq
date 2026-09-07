<?php

namespace Simsoft\DB\Builder\Conditions;

use InvalidArgumentException;
use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Raw;

/**
 * Class WhereCondition
 *
 */
class Condition extends Clause
{
    /**
     * Operators whose right-hand side is not a single placeholder.
     *
     * @var array<int, string>
     */
    private const SHAPED_OPERATORS = ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'];

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
        $this->operator = $this->validateOperator($operator);
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException If the operator needs a shape this clause cannot emit.
     */
    protected function buildSQL(): string
    {
        if (is_array($this->attribute)) {
            return $this->buildArrayCondition();
        }

        if ($this->attribute instanceof Raw) {
            return $this->buildRawCondition($this->attribute);
        }

        $this->assertSinglePlaceholderShape();

        // A null value used to bind the operator in its place and reset the
        // operator to '=', a shorthand that predates operator() validating
        // its input — an operator can no longer hold a value, so the swap
        // only ever bound the literal "=" and silently matched nothing.
        // A null binds like any other value.
        $this->appendBinds($this->value);

        return "{$this->queryAttribute($this->attribute)} $this->operator {$this->getPlaceHolder()}";
    }

    /**
     * Build a condition whose left-hand side is a Raw expression.
     *
     * @param Raw $expression The expression standing in for the attribute.
     * @return string
     * @throws InvalidArgumentException If the operator needs a shape this clause cannot emit.
     */
    private function buildRawCondition(Raw $expression): string
    {
        $sql = (string)$expression;
        $this->absorbBinds($expression->getBinds());

        // The operator and value were dropped whenever the attribute was Raw,
        // so where(new Raw('score'), '>', 90) built a bare `WHERE score` — a
        // truthiness test matching every non-zero row, and valid SQL, so
        // nothing reported that the comparison had gone missing. An expression
        // given on its own still stands alone.
        if ($this->value === null) {
            return $sql;
        }

        $this->assertSinglePlaceholderShape();
        $this->appendBinds($this->value);

        return "$sql $this->operator {$this->getPlaceHolder()}";
    }

    /**
     * Assert the scalar form can be expressed as `attribute operator ?`.
     *
     * @return void
     * @throws InvalidArgumentException If the operator or value needs another shape.
     */
    private function assertSinglePlaceholderShape(): void
    {
        // Only one placeholder is emitted, so a set or range operator built
        // `id IN ?` — rejected by the server. ActiveQuery routes those to
        // in()/between() before reaching here; a clause constructed directly
        // says so rather than producing SQL that cannot run.
        if (in_array($this->operator, self::SHAPED_OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Condition cannot build "%s"; use in(), notIn(), between() or notBetween() instead.',
                $this->operator
            ));
        }

        // One placeholder against several values left the statement short and
        // the driver reported it ("must consist of exactly 1 elements") without
        // naming the attribute responsible.
        if (is_array($this->value)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" with operator "%s" takes a single value; got %d.',
                is_scalar($this->attribute) ? (string)$this->attribute : get_debug_type($this->attribute),
                $this->operator,
                count($this->value)
            ));
        }
    }

    /**
     * Build SQL for array-based conditions.
     *
     * @return string Empty when no entry contributed a condition.
     */
    private function buildArrayCondition(): string
    {
        if (array_is_list($this->attribute)) {
            $sql = $this->buildTripletConditions();
            return $sql === [] ? '' : implode(' AND ', $sql);
        }

        $sql = $this->buildMappedConditions();
        return $sql === [] ? '' : '(' . implode(' AND ', $sql) . ')';
    }

    /**
     * Build the list form: [[attribute, operator, value], ...].
     *
     * @return array<int, string> One fragment per contributing entry.
     * @throws InvalidArgumentException If an entry is not an [attribute, operator, value] triplet.
     */
    private function buildTripletConditions(): array
    {
        $sql = [];

        /** @var mixed $entry */
        foreach ($this->attribute as $index => $entry) {
            [$field, $operator, $value] = $this->readTriplet($index, $entry);

            if ($operator === 'IN' || $operator === 'NOT IN') {
                $group = $this->placeholderGroup($field, $operator, $value);
                if ($group === '') {
                    continue;
                }
                $sql[] = "{$this->queryAttribute($field)} $operator $group";
                $this->appendBinds($value);
                continue;
            }

            $sql[] = "{$this->queryAttribute($field)} $operator {$this->getPlaceHolder()}";
            $this->appendBinds($value);
        }

        return $sql;
    }

    /**
     * Read one [attribute, operator, value] entry of the list form.
     *
     * @param mixed $index The entry's position, for the error message.
     * @param mixed $entry The entry to read.
     * @return array{0: string, 1: string, 2: mixed} The attribute, validated operator and value.
     * @throws InvalidArgumentException If the entry is not a triplet, or its operator is not allowed.
     */
    private function readTriplet(mixed $index, mixed $entry): array
    {
        // The triplet was destructured without checking its shape, so a short
        // entry raised "Undefined array key 2" and then bound null — a warning
        // in the log and a condition matching nothing, rather than an error
        // naming the entry at fault.
        if (!is_array($entry) || count($entry) !== 3) {
            throw new InvalidArgumentException(sprintf(
                'Condition #%s must be [attribute, operator, value]; got %s.',
                is_scalar($index) ? (string)$index : get_debug_type($index),
                is_array($entry) ? count($entry) . ' elements' : get_debug_type($entry)
            ));
        }

        [$field, $operator, $value] = array_values($entry);

        return [
            is_string($field) ? $field : get_debug_type($field),
            $this->validateOperator(is_string($operator) ? $operator : get_debug_type($operator)),
            $value,
        ];
    }

    /**
     * Build the map form: [attribute => value, ...].
     *
     * An array value is matched with IN; anything else with =.
     *
     * @return array<int, string> One fragment per contributing entry.
     */
    private function buildMappedConditions(): array
    {
        $sql = [];

        /** @var mixed $value */
        foreach ($this->attribute as $field => $value) {
            $field = is_string($field) ? $field : (string)$field;

            if (is_array($value)) {
                $group = $this->placeholderGroup($field, 'IN', $value);
                if ($group === '') {
                    continue;
                }
                $sql[] = "{$this->queryAttribute($field)} IN $group";
                $this->appendBinds($value);
                continue;
            }

            $sql[] = "{$this->queryAttribute($field)} = {$this->getPlaceHolder()}";
            $this->appendBinds($value);
        }

        return $sql;
    }

    /**
     * Build a parenthesised placeholder group for a set operator.
     *
     * @param string $field The attribute being matched, for the error message.
     * @param string $operator The set operator.
     * @param mixed $value The values to match against.
     * @return string The group, or '' when there is nothing to match against.
     * @throws InvalidArgumentException If the values are not an array.
     */
    private function placeholderGroup(string $field, string $operator, mixed $value): string
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s on "%s" needs an array of values; got %s.',
                $operator,
                $field,
                get_debug_type($value)
            ));
        }

        // An empty set built `IN ()`, which the server rejects, so a filter
        // narrowed to nothing took the whole query down. The entry is dropped
        // instead, as in() already does for an empty value list; a condition
        // left with no entries builds an empty string and the caller skips it.
        if ($value === []) {
            return '';
        }

        return '(' . implode(',', array_fill(0, count($value), $this->getPlaceHolder())) . ')';
    }
}
