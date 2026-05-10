<?php

namespace Simsoft\DB\MySQL;

use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Clauses\Clause;
use Simsoft\DB\MySQL\Builder\Raw;

/**
 * Relation
 *
 * @method static select(...$attributes)
 * @method static where(string|array|callable|Raw|Clause $attribute, mixed $operator = '=', mixed $value = null, string $logicalOperator = 'AND')
 * @method static whereRaw(string $statement, ?array $binds = null)
 * @method static not(string $attribute, mixed $value)
 * @method static isNull(string $attribute)
 * @method static notNull(string $attribute)
 * @method static in(string $attribute, array|ActiveQuery|Raw $values)
 * @method static notIn(string $attribute, array|ActiveQuery|Raw $values)
 * @method static exists(ActiveQuery|Raw $query)
 * @method static notExists(ActiveQuery|Raw $query)
 */
class Relation
{
    /**
     * Constructor.
     *
     * @param ActiveQuery $query
     * @param string $foreignKey
     * @param int|string|null $foreignValue
     * @param bool $multiple
     */
    public function __construct(
        protected ActiveQuery     $query,
        protected string          $foreignKey,
        protected int|string|null $foreignValue,
        protected bool            $multiple = false
    )
    {
        if ($this->foreignValue) {
            $this->query->where($foreignKey, $this->foreignValue);
        }
    }

    public function withJoin(): static
    {
        return $this;
    }

    /**
     * @return array|Collection|Model|null
     */
    public function fetch(): mixed
    {
        return $this->multiple ? $this->query->get() : $this->query->first();
    }

    /**
     * Perform magic call.
     *
     * @param string $name
     * @param array $arguments
     * @return static
     */
    public function __call(string $name, array $arguments)
    {
        if (method_exists($this->query, $name)) {
            call_user_func_array([$this->query, $name], $arguments);
        }

        return $this;
    }

}
