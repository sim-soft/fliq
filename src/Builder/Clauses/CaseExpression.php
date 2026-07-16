<?php

namespace Simsoft\DB\Builder\Clauses;

/**
 * Case Expression Class.
 *
 * Fluent builder for SQL CASE WHEN ... THEN ... ELSE ... END expressions.
 * Works anywhere a Clause or Raw expression is accepted (select, where, orderBy).
 *
 * Usage:
 *   use Simsoft\DB\Builder\Clauses\CaseExpression;
 *
 *   User::find()->select(
 *       'name',
 *       CaseExpression::when('score', '>', 90)->then('A')
 *           ->andWhen('score', '>', 70)->then('B')
 *           ->else('C')
 *           ->as('grade')
 *   )->get();
 */
class CaseExpression extends Clause
{
    /** @var array<int, array{condition: string, value: mixed}> WHEN/THEN pairs */
    private array $conditions = [];

    /** @var mixed The ELSE value */
    private mixed $elseValue = null;

    /** @var bool Whether an ELSE clause was set */
    private bool $hasElse = false;

    /** @var string|null Column alias (AS name) */
    private ?string $aliasName = null;

    /** @var string|null Pending WHEN condition SQL (before then() is called) */
    private ?string $pendingCondition = null;

    /** @var array<int, mixed> Pending WHEN binds (before then() is called) */
    private array $pendingBinds = [];

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(null);
    }

    /**
     * Start a CASE expression with the first WHEN clause.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @return self
     */
    public static function when(string $column, string $operator, mixed $value): self
    {
        $instance = new self();
        $instance->addWhen($column, $operator, $value);
        return $instance;
    }

    /**
     * Start a CASE expression with a raw WHEN condition.
     *
     * @param string $condition Raw SQL condition.
     * @param array<int, mixed> $binds Bind values for the condition.
     * @return self
     */
    public static function whenRaw(string $condition, array $binds = []): self
    {
        $instance = new self();
        $instance->pendingCondition = $condition;
        $instance->pendingBinds = $binds;
        return $instance;
    }

    /**
     * Set the THEN value for the current WHEN clause.
     *
     * @param mixed $value The value to return when condition is true.
     * @return static
     */
    public function then(mixed $value): static
    {
        if ($this->pendingCondition === null) {
            return $this;
        }

        $this->conditions[] = [
            'condition' => $this->pendingCondition,
            'value' => $value,
        ];

        // Move pending binds into the main bind array
        foreach ($this->pendingBinds as $bind) {
            $this->appendBinds($bind);
        }
        $this->appendBinds($value);

        $this->pendingCondition = null;
        $this->pendingBinds = [];

        return $this;
    }

    /**
     * Add another WHEN clause (chainable after then()).
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @return static
     */
    public function andWhen(string $column, string $operator, mixed $value): static
    {
        $this->addWhen($column, $operator, $value);
        return $this;
    }

    /**
     * Add another raw WHEN clause (chainable after then()).
     *
     * @param string $condition Raw SQL condition.
     * @param array<int, mixed> $binds Bind values for the condition.
     * @return static
     */
    public function andWhenRaw(string $condition, array $binds = []): static
    {
        $this->pendingCondition = $condition;
        $this->pendingBinds = $binds;
        return $this;
    }

    /**
     * Start a CASE expression comparing two columns.
     *
     * @param string $column The left column name.
     * @param string $operator The comparison operator.
     * @param string $otherColumn The right column name.
     * @return self
     */
    public static function whenColumn(string $column, string $operator, string $otherColumn): self
    {
        $instance = new self();
        $instance->addWhenColumn($column, $operator, $otherColumn);
        return $instance;
    }

    /**
     * Add another WHEN clause comparing two columns (chainable after then()).
     *
     * @param string $column The left column name.
     * @param string $operator The comparison operator.
     * @param string $otherColumn The right column name.
     * @return static
     */
    public function andWhenColumn(string $column, string $operator, string $otherColumn): static
    {
        $this->addWhenColumn($column, $operator, $otherColumn);
        return $this;
    }

    /**
     * Set the ELSE value.
     *
     * @param mixed $value The fallback value when no WHEN matches.
     * @return static
     */
    public function else(mixed $value): static
    {
        $this->elseValue = $value;
        $this->hasElse = true;
        return $this;
    }

    /**
     * Set a column alias (AS name) for use in SELECT.
     *
     * @param string $name The alias name.
     * @return static
     */
    public function as(string $name): static
    {
        $this->aliasName = $name;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $placeholder = $this->getPlaceHolder() ?: '?';
        $parts = ['CASE'];

        foreach ($this->conditions as $pair) {
            $parts[] = "WHEN {$pair['condition']} THEN $placeholder";
        }

        if ($this->hasElse) {
            $this->appendBinds($this->elseValue);
            $parts[] = "ELSE $placeholder";
        }

        $parts[] = 'END';

        $sql = implode(' ', $parts);

        if ($this->aliasName !== null) {
            $sql .= ' AS ' . $this->aliasName;
        }

        return $sql;
    }

    /**
     * Prepare a WHEN condition from column/operator/value.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value.
     * @return void
     */
    private function addWhen(string $column, string $operator, mixed $value): void
    {
        $qualifiedCol = $this->queryAttribute($column);
        $placeholder = $this->getPlaceHolder() ?: '?';
        $this->pendingCondition = "$qualifiedCol $operator $placeholder";
        $this->pendingBinds = [$value];
    }

    /**
     * Prepare a WHEN condition comparing two columns (no bind values).
     *
     * @param string $column The left column name.
     * @param string $operator The comparison operator.
     * @param string $otherColumn The right column name.
     * @return void
     */
    private function addWhenColumn(string $column, string $operator, string $otherColumn): void
    {
        $leftCol = $this->queryAttribute($column);
        $rightCol = $this->queryAttribute($otherColumn);
        $this->pendingCondition = "$leftCol $operator $rightCol";
        $this->pendingBinds = [];
    }
}
