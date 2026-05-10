<?php

namespace Simsoft\DB\MySQL\Builder;

use Closure;
use Iterator;
use JetBrains\PhpStorm\NoReturn;
use Simsoft\DB\MySQL\Builder\Aggregations\Avg;
use Simsoft\DB\MySQL\Builder\Aggregations\Count;
use Simsoft\DB\MySQL\Builder\Aggregations\Max;
use Simsoft\DB\MySQL\Builder\Aggregations\Min;
use Simsoft\DB\MySQL\Builder\Aggregations\Sum;
use Simsoft\DB\MySQL\Builder\Clauses\Clause;
use Simsoft\DB\MySQL\Builder\Clauses\OrderByClause;
use Simsoft\DB\MySQL\Builder\Clauses\SelectClause;
use Simsoft\DB\MySQL\Builder\Conditions\BetweenCondition;
use Simsoft\DB\MySQL\Builder\Conditions\BetweenDateCondition;
use Simsoft\DB\MySQL\Builder\Conditions\Condition;
use Simsoft\DB\MySQL\Builder\Conditions\ExistsCondition;
use Simsoft\DB\MySQL\Builder\Conditions\InCondition;
use Simsoft\DB\MySQL\Builder\Conditions\LikeCondition;
use Simsoft\DB\MySQL\Collection;
use Simsoft\DB\MySQL\Interfaces\Deletable;
use Simsoft\DB\MySQL\Interfaces\Executable;
use Simsoft\DB\MySQL\Interfaces\Updatable;
use Simsoft\DB\MySQL\Model;
use Simsoft\DB\MySQL\Traits\Binds;
use Simsoft\DB\MySQL\Traits\Execute;
use Simsoft\DB\MySQL\Traits\PlaceHolder;
use Simsoft\DB\MySQL\Traits\Qualifier;

/**
 * Class ActiveQuery.
 *
 */
class ActiveQuery implements Executable, Updatable, Deletable
{
    use Qualifier, Execute, PlaceHolder, Binds;

    /** @var null|string The table name */
    protected ?string $table = null;

    /** @var bool Distinct is enabled */
    protected bool $distinct = false;

    /** @var array The select statement. */
    public array $selects = [];

    /** @var array The WHERE statements. */
    public array $conditions = [];

    /** @var array Jointed relationship. */
    public array $joins = [];

    /** @var array The GROUP BY statements. */
    public array $groupBys = [];

    /** @var array The query having */
    public array $having = [];

    /** @var array The query order */
    public array $orderBys = [];

    /** @var int The limit value */
    protected int $limit = 0;

    /** @var null|int The offset value */
    protected ?int $offset = null;

    /** @var bool Enable UNION ALL. default: false */
    protected bool $unionAll = false;

    /** @var array The UNION statements */
    protected array $unions = [];

    /** @var Closure|string|null Set index by. */
    protected Closure|string|null $indexBy = null;

    /** @var string|null Attribute to be plucked */
    protected ?string $pluckAttribute = null;

    /**
     * Constructor.
     *
     * @param string|Model|null $modelClass The model class
     */
    public function __construct(protected string|null|Model $modelClass = null)
    {
        if ($this->modelClass instanceof Model) {
            $this->from($this->modelClass);
        } elseif (is_string($this->modelClass)) {
            $this->from(new $this->modelClass());
        }
    }

    /**
     * Set index by.
     *
     * @param Closure|string $index
     * @return $this
     */
    public function indexBy(Closure|string $index): static
    {
        $this->indexBy = $index;
        return $this;
    }

    /**
     * Determine has indexBy value.
     *
     * @return bool
     */
    public function hasIndexBy(): bool
    {
        return $this->indexBy !== null;
    }

    /**
     * Get pluck attribute name.
     *
     * @return string|null
     */
    public function getPluckAttribute(): ?string
    {
        return $this->pluckAttribute;
    }

    /**
     * Enable select distinct
     *
     * @return $this
     */
    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Get current query SQL.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getSQL();
    }

    /**
     * Get FROM table name
     *
     * @return string|null
     */
    public function getTable(): ?string
    {
        return $this->table;
    }

    /**
     * Merge with other query object.
     *
     * Only can merge if the other query has the same table/ alias.
     *
     * @param ActiveQuery $query the other Query object
     * @param string $logicalOperator The logical operator. Default 'AND'.
     * @return $this
     */
    public function merge(ActiveQuery $query, string $logicalOperator = 'AND'): static
    {
        if ($this->table === $query->getTable()) {
            $properties = ['selects', 'conditions', 'groupBys', 'having', 'orderBys', 'joins', 'binds'];
            foreach ($properties as $property) {
                if (!empty($query->{$property})) {
                    if (in_array($property, ['conditions', 'having']) && !empty($this->{$property})) {
                        $this->{$property}[] = $logicalOperator;
                    }
                    $this->{$property} = array_merge($this->{$property}, $query->{$property});
                }
            }
        }

        return $this;
    }

    /**
     * Merge with other query object.
     *
     * Prepend 'OR' to the query.
     *
     * @param ActiveQuery $query the other Query object
     * @return $this
     */
    public function orMerge(ActiveQuery $query): static
    {
        return $this->merge($query, 'OR');
    }

    /**
     * Is the current query has conditions
     *
     * @return bool
     */
    public function hasConditions(): bool
    {
        return !(empty($this->conditions) && empty($this->groupBys) && empty($this->having) && empty($this->orderBys));
    }

    /**
     * Set from table for the query.
     *
     * Example usage:
     * $this->from('tableName');                    // FROM tableName
     * $this->from('tableName t')                   // FROM tableName AS t
     * $this->from('tableName AS t')                // FROM tableName AS t
     * $this->from(['t' => 'SELECT * FROM ..'])     // FROM (SELECT * FROM ...) AS t  sub query
     *
     * @param string|array|Model $table the table name
     * @return $this
     */
    public function from(string|array|Model $table): static
    {
        if (is_array($table)) {
            $subQuery = current($table);
            $this->table = $this->getQualifiedSubQuery($subQuery, array_key_first($table));
            if ($subQuery instanceof ActiveQuery || $subQuery instanceof Raw) {
                $this->appendBinds($subQuery->getBinds());
            }
        } else {
            if ($table instanceof Model) {
                $this->setConnection($table->getConnection());
                $table = $table->getTable();
            }

            $expressions = explode(' ', trim($table));
            $table = $expressions[0];
            $alias = end($expressions);
            $this->table = $this->getQualifiedTable($table, $table === $alias ? null : $alias);
        }

        return $this;
    }

    /**
     * Join table.
     *
     * @param string|array $table The table name
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @param string $type Join type. LEFT, RIGHT, INNER, OUTER, etc
     * @return $this
     */
    public function join(string|array $table, array $on = [], string $type = 'INNER'): static
    {
        if (is_array($table)) {
            $alias = array_key_first($table);
            $subQuery = current($table);
            $table = "($subQuery) AS $alias";
        } else {
            $expressions = explode(' ', trim($table));
            $table = $expressions[0];
            $alias = end($expressions);
        }

        $join = $type ? strtoupper($type) . ' JOIN' : 'JOIN';

        $foreignKey = array_key_first($on);
        $localKey = current($on);

        if ($table === $alias) {
            $this->joins[$table] = "$join `$table` ON `$table`.`$foreignKey` = " . $this->queryAttribute($localKey);
        } else {
            $this->joins[$alias] = "$join `$table` AS `$alias` ON `$alias`.`$foreignKey` = " . $this->queryAttribute($localKey);
        }

        return $this;
    }

    /**
     * Cross join table.
     *
     * @param string|array $table the join table
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return $this
     */
    public function crossJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'CROSS');
    }

    /**
     * Left join table.
     *
     * @param string|array $table the join table
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return $this
     */
    public function leftJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'LEFT');
    }

    /**
     * Right join table.
     *
     * @param string|array $table the join table
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return $this
     */
    public function rightJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'RIGHT');
    }

    /**
     * Left outer join table.
     *
     * @param string $table the join table
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return $this
     */
    public function leftOuterJoin(string $table, array $on = []): static
    {
        return $this->join($table, $on, 'LEFT OUTER');
    }

    /**
     * Right outer join table.
     *
     * @param string $table the join table
     * @param array<string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return $this
     */
    public function rightOuterJoin(string $table, array $on = []): static
    {
        return $this->join($table, $on, 'RIGHT OUTER');
    }

    /**
     * With alias name condition.
     *
     * @param string $alias the alias to be used
     * @param callable $condition The
     */
    public function with(string $alias, callable $condition): static
    {
        if ($condition instanceof Closure
            && ($callable = Closure::bind($condition, $this, get_class($this)))
        ) {
            $backup = $this->getAlias();
            $this->alias($alias);
            $callable($this);
            $this->alias($backup);
        }
        return $this;
    }

    /**
     * Select statement.
     *
     * @param array $attributes the list of attribute value to be select
     * @return $this
     */
    public function select(...$attributes): static
    {
        $this->selects[] = new SelectClause($attributes);
        return $this;
    }

    /**
     * Select distinct statement
     *
     * @param array $attributes List of SELECT attributes
     * @return $this
     */
    public function selectDistinct(...$attributes): static
    {
        $this->selects[] = new SelectClause($attributes);
        return $this->distinct();
    }

    /**
     * Construct the query conditions.
     *
     * @param string|array|callable|Raw|Clause $attribute the attribute
     * @param mixed $operator the comparison operator or the attribute value
     * @param mixed $value the value for the attribute
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return ActiveQuery
     */
    public function where(
        string|array|callable|Raw|Clause $attribute,
        mixed  $operator = '=',
        mixed  $value = null,
        string $logicalOperator = 'AND'
    ): static
    {
        if ($attribute instanceof Closure
            && ($callable = Closure::bind($attribute, $this, get_class($this)))
        ) {
            $this->onCondition('(', $logicalOperator);
            $callable($this);
            return $this->onCondition(')');
        } elseif ($attribute instanceof Clause) {
            return $this->onCondition($attribute->alias($this->getAlias()), $logicalOperator);
        }

        if ($value === null && $operator != '=') {
            $value = $operator;
            $operator = '=';
        }

        return $this->onCondition(
            (new Condition($attribute, $value))
                ->operator($operator),
            $logicalOperator
        );
    }

    /**
     * Or condition.
     *
     * @param string|callable|Raw $attribute the attribute name
     * @param mixed $operator the comparison operator or the attribute value
     * @param mixed $value the value for the attribute
     */
    public function orWhere(string|callable|Raw $attribute, ?string $operator = '=', mixed $value = null): static
    {
        return $this->where($attribute, $operator, $value, 'OR');
    }

    /**
     * Not condition query.
     *
     * @param string $attribute the attribute name
     * @param mixed $value
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return $this
     */
    public function not(string $attribute, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->where($attribute, '!=', $value, $logicalOperator);
    }

    /**
     * Or not condition.
     *
     * @param string $attribute the attribute name
     * @param mixed $value the value for the attribute
     */
    public function orNot(string $attribute, mixed $value): static
    {
        return $this->not($attribute, $value, 'OR');
    }

    /**
     * Is null condition.
     *
     * @param string $attribute the attribute name
     * @param string $logicalOperator The logical operator. Either: 'AND' or 'OR'.
     */
    public function isNull(string $attribute, string $logicalOperator = 'AND'): static
    {
        return $this->onCondition("{$this->queryAttribute($attribute)} IS NULL", $logicalOperator);
    }

    /**
     * Or is null condition.
     *
     * @param string $attribute the attribute name
     */
    public function orIsNull(string $attribute): static
    {
        return $this->isNull($attribute, 'OR');
    }

    /**
     * Not null condition.
     *
     * @param string $attribute the attribute name
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     */
    public function notNull(string $attribute, string $logicalOperator = 'AND'): static
    {
        return $this->onCondition("{$this->queryAttribute($attribute)} IS NOT NULL", $logicalOperator);
    }

    /**
     * Or not null condition.
     *
     * @param string $attribute the attribute name
     */
    public function orNotNull(string $attribute): static
    {
        return $this->notNull($attribute, 'OR');
    }

    /**
     * Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @param string $logicalOperator
     * @return $this
     */
    public function exists(ActiveQuery|Raw $query, string $logicalOperator = 'AND'): static
    {
        return $this->onCondition(new ExistsCondition($query), $logicalOperator);
    }

    /**
     * Or Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @return $this
     */
    public function orExists(ActiveQuery|Raw $query): static
    {
        return $this->exists($query, 'OR');
    }

    /**
     * Not Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @param string $logicalOperator
     * @return $this
     */
    public function notExists(ActiveQuery|Raw $query, string $logicalOperator = 'AND'): static
    {
        return $this->onCondition(new ExistsCondition($query, is: false), $logicalOperator);
    }

    /**
     * Or not Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @return $this
     */
    public function orNotExists(ActiveQuery|Raw $query): static
    {
        return $this->notExists($query, 'OR');
    }

    /**
     * In condition.
     *
     * @param string $attribute the attribute name
     * @param array|ActiveQuery|Raw $values the array of values for the query
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @return ActiveQuery
     */
    public function in(string $attribute, array|ActiveQuery|Raw $values, string $logicalOperator = 'AND'): static
    {
        if ($values) {
            return $this->onCondition(
                new InCondition($attribute, $values),
                $logicalOperator
            );
        }

        return $this;
    }

    /**
     * Or in condition.
     *
     * @param string $attribute the attribute name
     * @param array|ActiveQuery|Raw $values the array of values for the query
     * @return ActiveQuery
     */
    public function orIn(string $attribute, array|ActiveQuery|Raw $values): static
    {
        return $this->in($attribute, $values, 'OR');
    }

    /**
     * Not in condition.
     *
     * @param string $attribute the attribute name
     * @param array|ActiveQuery|Raw $values the array of values for the query
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @return ActiveQuery
     */
    public function notIn(string $attribute, array|ActiveQuery|Raw $values, string $logicalOperator = 'AND'): static
    {
        if ($values) {
            return $this->onCondition(
                new InCondition($attribute, $values, false),
                $logicalOperator
            );
        }
        return $this;
    }

    /**
     * Or not in condition.
     *
     * @param string $attribute the attribute name
     * @param array|ActiveQuery|Raw $values the array of values for the query
     * @return ActiveQuery
     */
    public function orNotIn(string $attribute, array|ActiveQuery|Raw $values): static
    {
        return $this->notIn($attribute, $values, 'OR');
    }

    /**
     * Like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $is the comparison operator
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @return $this
     */
    public function like(
        string $attribute,
        string|array $value,
        bool   $is = true,
        bool   $matchAll = true,
        string $logicalOperator = 'AND'
    ): static
    {
        return $this->onCondition((new LikeCondition($attribute, $value, $is))->matchAll($matchAll), $logicalOperator);
    }

    /**
     * Or like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @return $this
     */
    public function orLike(string $attribute, string|array $value, bool $matchAll = true): static
    {
        return $this->like($attribute, $value, true, $matchAll, 'OR');
    }

    /**
     * Not like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @return $this
     */
    public function notLike(string $attribute, string|array $value, bool $matchAll = true): static
    {
        return $this->like($attribute, $value, false, $matchAll);
    }

    /**
     * Or not like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @return $this
     */
    public function orNotLike(string $attribute, string|array $value, bool $matchAll = true): static
    {
        return $this->like($attribute, $value, false, $matchAll, 'OR');
    }

    /**
     * Between condition.
     *
     * @param string $attribute the attribute name
     * @param mixed $start the start value
     * @param mixed $end the end value
     * @param bool $is determine the comparison operator
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     */
    public function between(string $attribute, mixed $start, mixed $end, bool $is = true, string $logicalOperator = 'AND'): static
    {
        return $this->onCondition(
            new BetweenCondition($attribute, [$start, $end], $is),
            $logicalOperator
        );
    }

    /**
     * Or between condition.
     *
     * @param string $attribute the attribute name
     * @param mixed $start the start value
     * @param mixed $end the end value
     */
    public function orBetween(string $attribute, mixed $start, mixed $end): static
    {
        return $this->between($attribute, $start, $end, true, 'OR');
    }

    /**
     * Not between condition.
     *
     * @param string $attribute the attribute name
     * @param mixed $start the start value
     * @param mixed $end the end value
     */
    public function notBetween(string $attribute, mixed $start, mixed $end): static
    {
        return $this->between($attribute, $start, $end, false);
    }

    /**
     * Or not between condition.
     *
     * @param string $attribute the attribute name
     * @param mixed $start the start value
     * @param mixed $end the end value
     */
    public function orNotBetween(string $attribute, mixed $start, mixed $end): static
    {
        return $this->between($attribute, $start, $end, false, 'OR');
    }

    /**
     * Between date condition.
     *
     * @param string $attribute the attribute name
     * @param null|string $startDate the start date value
     * @param null|string $endDate the end date value
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     */
    public function betweenDate(
        string  $attribute,
        ?string $startDate = null,
        ?string $endDate = null,
        bool    $is = true,
        string  $logicalOperator = 'AND'
    ): static
    {
        return $this->onCondition(new BetweenDateCondition($attribute, [$startDate, $endDate], $is), $logicalOperator);
    }

    /**
     * Or between date condition.
     *
     * @param string $attribute the attribute name
     * @param null|string $startDate the start date value
     * @param null|string $endDate the end date value
     */
    public function orBetweenDate(string $attribute, ?string $startDate = null, ?string $endDate = null): static
    {
        return $this->betweenDate($attribute, $startDate, $endDate, true, 'OR');
    }

    /**
     * Not between date condition.
     *
     * @param string $attribute the attribute name
     * @param null|string $startDate the start date value
     * @param null|string $endDate the end date value
     */
    public function notBetweenDate(string $attribute, ?string $startDate = null, ?string $endDate = null): static
    {
        return $this->betweenDate($attribute, $startDate, $endDate, false);
    }

    /**
     * Or not between date condition.
     *
     * @param string $attribute the attribute name
     * @param null|string $startDate the start date value
     * @param null|string $endDate the end date value
     */
    public function orNotBetweenDate(string $attribute, ?string $startDate = null, ?string $endDate = null): static
    {
        return $this->betweenDate($attribute, $startDate, $endDate, false, 'OR');
    }

    /**
     * Between date interval condition.
     *
     * @param string $attribute the attribute name
     * @param string $startDate the start date value
     * @param int $interval the interval days from the start date
     * @param bool $is determine the comparison operator
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     */
    public function betweenDateInterval(
        string $attribute,
        string $startDate,
        int    $interval = 7,
        bool   $is = true,
        string $logicalOperator = 'AND'
    ): static
    {
        return $this->onCondition(
            (new BetweenDateCondition($attribute, [$startDate, $startDate], $is))->interval($interval),
            $logicalOperator
        );
    }

    /**
     * Or between date interval condition.
     *
     * @param string $attribute the attribute name
     * @param string $startDate the start date value
     * @param int $interval The interval days value. Default 7 days.
     */
    public function orBetweenDateInterval(string $attribute, string $startDate, int $interval = 7): static
    {
        return $this->betweenDateInterval($attribute, $startDate, $interval, true, 'OR');
    }

    /**
     * Not between date interval condition.
     *
     * @param string $attribute the attribute name
     * @param string $startDate the start date value
     * @param int $interval The interval days value. Default 7 days.
     */
    public function notBetweenDateInterval(string $attribute, string $startDate, int $interval = 7): static
    {
        return $this->betweenDateInterval($attribute, $startDate, $interval, false);
    }

    /**
     * Or not between date interval condition.
     *
     * @param string $attribute the attribute name
     * @param string $startDate the start date value
     * @param int $interval The interval days value. Default 7 days.
     */
    public function orNotBetweenDateInterval(string $attribute, string $startDate, int $interval = 7): static
    {
        return $this->betweenDateInterval($attribute, $startDate, $interval, false, 'OR');
    }

    /**
     * Regular expression pattern
     *
     * @param string $attribute The attribute name
     * @param string $regex The regular expression pattern.
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     * @return $this
     */
    public function regex(string $attribute, string $regex, string $logicalOperator = 'AND'): static
    {
        return $this->where($attribute, 'REGEXP', $regex, $logicalOperator);
    }

    /**
     * Or regular expression pattern
     *
     * @param string $attribute The attribute name.
     * @param string $regex The regular expression pattern.
     * @return $this
     */
    public function orRegex(string $attribute, string $regex): static
    {
        return $this->regex($attribute, $regex, 'OR');
    }

    /**
     * Not regular expression pattern
     *
     * @param string $attribute The attribute name
     * @param string $regex The regular expression pattern.
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     * @return $this
     */
    public function notRegex(string $attribute, string $regex, string $logicalOperator = 'AND'): static
    {
        return $this->where($attribute, 'NOT REGEXP', $regex, $logicalOperator);
    }

    /**
     * Or not regular expression pattern
     *
     * @param string $attribute The attribute name.
     * @param string $regex The regular expression pattern.
     * @return $this
     */
    public function orNotRegex(string $attribute, string $regex): static
    {
        return $this->notRegex($attribute, $regex, 'OR');
    }

    /**
     * Attribute contains word.
     *
     * @param string $attribute The attribute name.
     * @param string|array $words The words to search.
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     * @return $this
     */
    public function containsWords(string $attribute, string|array $words, string $logicalOperator = 'AND'): static
    {
        if (is_array($words)) {
            $conditions = [];
            foreach ($words as $word) {
                $conditions[] = [$attribute, 'REGEXP', '\\b' . addslashes(quotemeta($word)) . '\\b'];
            }

            return $this->where($conditions, logicalOperator: $logicalOperator);
        } else {
            return $this->regex($attribute, '\\b' . addslashes(quotemeta($words)) . '\\b', $logicalOperator);
        }
    }

    /**
     * Attribute contains word.
     *
     * @param string $attribute
     * @param string|array $words
     * @return $this
     */
    public function orContainsWords(string $attribute, string|array $words): static
    {
        return $this->containsWords($attribute, $words, 'OR');
    }

    /**
     * Raw query statement.
     *
     * @param string $statement
     * @param array|null $binds
     * @return $this
     */
    public function whereRaw(string $statement, ?array $binds = null): static
    {
        return $this->where(new Raw($statement, $binds));
    }

    /**
     * Or raw query statement.
     *
     * @param string $statement
     * @param array|null $binds
     * @return $this
     */
    public function orWhereRaw(string $statement, ?array $binds = null): static
    {
        return $this->where(new Raw($statement, $binds), logicalOperator: 'OR');
    }

    /**
     * Group by statement.
     *
     * Example usages:
     *
     * $this->groupBy('attribute');                 // GROUP BY table.attribute.
     * $this->groupBy('attribute', '!p.attribute'); // GROUP BY table.attribute, p.attribute
     *
     * @param array $attributes the attribute
     * @return $this
     */
    public function groupBy(...$attributes): static
    {
        foreach ($attributes as $name) {
            $this->groupBys[] = $this->queryAttribute($name);
        }

        return $this;
    }

    /**
     * Having clause
     *
     * @param string|Raw $attribute The attribute
     * @param string|null $operator The comparison operator or the attribute value
     * @param mixed|null $value The value for the attribute
     * @return $this
     */
    public function having(mixed $attribute, ?string $operator = '=', mixed $value = null): static
    {
        if ($value === null && $operator != '=') {
            $value = $operator;
            $operator = '=';
        }

        $this->having[] = (new Condition($attribute, $value))
            ->operator($operator)
            ->setPlaceHolder($this->getPlaceHolder());
        return $this;
    }

    /**
     * Order by statement.
     *
     * Example usages:
     *
     * $this->orderBy('attribute')           // ORDER BY table.attribute ASC
     * $this->orderBy('attribute', 'DESC');  // ORDER by table.attribute DESC
     * $this->orderBy([                      // ORDER BY table.attribute1 ASC, table.attribute2 DESC
     *  'attribute1' => 'ASC',
     *  'attribute2' => 'DESC',
     * ]);
     * $this->orderBy([                      // ORDER BY table.attribute1 ASC, p.attribute2 DESC
     *  'attribute1' => 'ASC',
     *  '!p.attribute2' => 'DESC',
     * ]);
     *
     * @param string|array $attribute the attribute
     * @param string $direction the order direction for the attribute
     * @return $this
     */
    public function orderBy(string|array $attribute, string $direction = 'ASC'): static
    {
        $this->orderBys[] = new OrderByClause($attribute, $direction);
        return $this;
    }

    /**
     * Order by desc.
     *
     * @param string|array $attribute
     * @return $this
     */
    public function orderByDesc(string|array $attribute): static
    {
        return $this->orderBy($attribute, 'DESC');
    }

    /**
     * Limit statement.
     *
     * @param int $max the maximum records to be returned
     * @param null|int $offset the offset value
     */
    public function limit(int $max, ?int $offset = null): static
    {
        $this->limit = $max;
        if ($offset) {
            $this->offset = $offset;
        }

        return $this;
    }

    /**
     * Offset statement.
     *
     * @param int $value the offset value
     */
    public function offset(int $value): static
    {
        $this->offset = $value;

        return $this;
    }

    /**
     * Limit per page statement.
     *
     * @param int $currentPage the current page
     * @param int $maxPerPage the max records returned per current page
     * @return $this
     */
    public function page(int $currentPage, int $maxPerPage = 50): static
    {
        return $this->limit($maxPerPage, --$currentPage * $maxPerPage);
    }

    public function union(ActiveQuery $q): static
    {
        $this->unions[] = (string)$q;
        $this->appendBinds($q->getBinds());
        return $this;
    }

    public function unionDistinct(ActiveQuery $q): static
    {
        return $this->distinct()->union($q);
    }

    public function unionAll(ActiveQuery $q): static
    {
        $this->unionAll = true;
        return $this->union($q);
    }

    /**
     * Append to existing query conditions.
     *
     * @param string|Clause $query the SQL query statement
     * @param null|string $operator The logical operator. Either: "AND" or "OR".
     */
    protected function onCondition(string|Clause $query, ?string $operator = null): static
    {
        if ($operator && $this->conditions && end($this->conditions) != '(') {
            $this->conditions[] = $operator;
        }

        $this->conditions[] = $query instanceof Clause ? $query->setPlaceHolder($this->getPlaceHolder()) : $query;

        return $this;
    }

    /**
     * Get SQL statement.
     *
     * @return string
     */
    public function getSQL(): string
    {
        return (new Select($this->getTable(), $this->selects, $this))
            ->distinct($this->distinct)
            ->alias($this->getAlias());
    }

    /**
     * Get full SQL statement.
     *
     * @return string
     */
    public function getFullSQL(): string
    {
        $sql = $this->getSQL();
        return $this->getBinds() === null
            ? $sql
            : $this->getReadableSQL($sql, $this->getBinds(), $this->getPlaceHolder());
    }

    /**
     * Generate JOINS statement
     *
     * @return string|null
     */
    public function getJoinSQL(): ?string
    {
        return empty($this->joins) ? null : implode(' ', $this->joins);
    }

    /**
     * Generate WHERE statement
     *
     * @return string|null
     */
    public function getWhereSQL(): ?string
    {
        $sql = [];
        foreach ($this->conditions as $condition) {
            if (is_string($condition)) {
                $sql[] = $condition;
            } elseif ($condition instanceof Clause) {
                $sql[] = (string)$condition;
                if ($condition->getBinds()) {
                    $this->appendBinds($condition->getBinds());
                }
            }
        }
        return $sql ? 'WHERE ' . implode(' ', $sql) : null;
    }

    /**
     * Generate GROUP BY statement
     *
     * @return string|null
     */
    public function getGroupSQL(): ?string
    {
        return empty($this->groupBys) ? null : 'GROUP BY ' . implode(', ', $this->groupBys);
    }

    /**
     * Generate HAVING statement
     *
     * @return string|null
     */
    public function getHavingSQL(): ?string
    {
        $sql = [];
        foreach ($this->having as $condition) {
            $sql[] = (string)$condition;
            $this->appendBinds($condition->getBinds());
        }

        return $sql ? 'HAVING ' . implode(', ', $sql) : null;
    }

    /**
     * Generate ORDER BY statement
     *
     * @return string|null
     */
    public function getOrderSQL(): ?string
    {
        return empty($this->orderBys) ? null : 'ORDER BY ' . implode(', ', $this->orderBys);
    }

    /**
     * Generate LIMIT statement
     *
     * @return string|null
     */
    public function getLimitSQL(): ?string
    {
        return match (true) {
            $this->limit && $this->offset => "LIMIT $this->offset, $this->limit",
            $this->limit && empty($this->offset) => "LIMIT $this->limit",
            default => null,
        };
    }

    /**
     * Get AVG() value.
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     *
     * @return mixed
     */
    public function avg(string $attribute, ?string $alias = 'avg'): mixed
    {
        return (new Avg($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->condition($this)
            ->queryScalar();
    }

    /**
     * Get AVG(DISTINCT attribute) value.
     *
     * @param string $attribute The attribute name or row select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     * @return mixed
     */
    public function avgDistinct(string $attribute, ?string $alias = 'avg'): mixed
    {
        return (new Avg($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->distinct()
            ->condition($this)
            ->queryScalar();
    }

    /**
     * Get COUNT() value
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     *
     * @return int
     */
    public function count(string $attribute = '*', ?string $alias = 'total'): int
    {
        return (new Count($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get COUNT(DISTINCT attribute) value
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     * @return int
     */
    public function countDistinct(string $attribute = '*', ?string $alias = 'total'): int
    {
        $count = (new Count($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->condition(clone $this);

        if ($attribute != '*') {
            $count->distinct();
        }
        return $count->queryScalar();
    }

    /**
     * Get total page of the current query.
     *
     * @param int $max Maximum record per page.
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param bool $distinct Whether to count distinct.
     * @return int
     */
    public function getTotalPages(int $max, string $attribute = '*', bool $distinct = false): int
    {
        return ceil($distinct ? $this->countDistinct($attribute) : $this->count($attribute) / $max);
    }

    /**
     * Get MAX(attribute) value.
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     *
     * @return mixed
     */
    public function max(string $attribute, ?string $alias = 'max'): mixed
    {
        return (new Max($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get MAX(DISTINCT ) value
     *
     * @param string $attribute
     * @param string|null $alias
     * @return mixed
     */
    public function maxDistinct(string $attribute, ?string $alias = 'max'): mixed
    {
        return (new Max($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->distinct()
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get MIN(attribute) value
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     *
     * @return mixed
     */
    public function min(string $attribute, ?string $alias = 'min'): mixed
    {
        return (new Min($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get MIN(DISTINCT attribute) value
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     * @return mixed
     */
    public function minDistinct(string $attribute, ?string $alias = 'min'): mixed
    {
        return (new Min($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->distinct()
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get SUM(attribute) value
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias The alias name for the aggregate function value.
     *
     * @return mixed
     */
    public function sum(string $attribute, ?string $alias = 'sum'): mixed
    {
        return (new Sum($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get SUM(DISTINCT attribute) value
     *
     * @param string $attribute The attribute name or raw select SQL statement.
     * @param string|null $alias Teh alias name for the aggregate function value.
     * @return mixed
     */
    public function sumDistinct(string $attribute, ?string $alias = 'sum'): mixed
    {
        return (new Sum($this->getTable(), $attribute, $alias))
            ->setConnection($this->connection)
            ->distinct()
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Find one active record.
     *
     * @return null|Model|array
     */
    public function first(): mixed
    {
        $result = $this->limit(1)->query($this);
        if ($result) {
            return $this->modelClass ? $this->getHydrated($result[0]) : $result[0];
        }

        return $this->modelClass ? null : [];
    }

    /**
     * Find by primary key attributes.
     *
     * @param array $pk
     * @return mixed
     */
    public function findByPk(array $pk): mixed
    {
        $keys = [];
        foreach ($pk as $attribute => $key) {
            $keys[] = [$attribute, '=', $key];
        }
        return $this->where($keys)->first();
    }

    /**
     * Get hydrated model.
     *
     * @param array $data
     * @return Iterator
     */
    public function getHydrated(array $data): mixed
    {
        return new $this->modelClass($data, false);
    }

    /**
     * Find all active records
     *
     * @return Iterator
     */
    public function getArray(): Iterator
    {
        if ($this->indexBy === null) {
            yield from $this->query($this);
        } elseif (is_string($this->indexBy)) {
            foreach ($this->query($this) as $row) {
                yield $row[$this->indexBy] => $row;
            }
        } else {
            foreach ($this->query($this) as $row) {
                yield ($this->indexBy)($row) => $row;
            }
        }
    }

    /**
     * Find all records.
     *
     * @return Iterator
     */
    public function all(): Iterator
    {
        if ($this->modelClass) {
            foreach ($this->getArray() as $index => $data) {
                yield $index => $this->getHydrated($data);
            }
        } else {
            yield from $this->getArray();
        }
    }

    /**
     * Find all active records
     *
     * @return Collection
     */
    public function get(): Collection
    {
        return new Collection($this);
    }

    /**
     * Perform batch query of each
     *
     * @param int $size Size of each loop.
     * @return Collection
     */
    public function each(int $size = 100): Collection
    {
        return (new Collection($this))->each($size);
    }

    /**
     * Perform batch query.
     *
     * @param int $size Size of each batch.
     * @return Collection
     */
    public function batch(int $size = 20): Collection
    {
        return (new Collection($this))->batch($size);
    }

    /**
     * Retrieving a List of Column Values
     *
     * @param string $attribute Specific attribute to be retrieved.
     * @param callable|string|null $indexBy
     * @return Collection
     */
    public function pluck(string $attribute, callable|string|null $indexBy = null): Collection
    {
        $this->pluckAttribute = $attribute;

        if ($indexBy) {
            $this->indexBy($indexBy);
        }

        return (new Collection($this))->toArray();
    }

    /**
     * Update attributes.
     *
     * @param array $attributes
     * @return bool
     */
    public function updateAll(array $attributes = []): bool
    {
        $update = new Update(trim($this->getTable(), '`'), $attributes, $this);
        $update->setConnection($this->connection);
        return (bool)$update->execute();
    }

    /**
     * Debug SQL statement.
     *
     * @param bool $fullSQL Determine to show full SQL. Default: false.
     * @return void
     */
    #[NoReturn]
    public function dd(bool $fullSQL = true): void
    {
        if ($fullSQL) {
            print PHP_EOL . $this->getFullSQL() . PHP_EOL;
            exit;
        }

        $query = clone $this;
        print PHP_EOL . $query->getSQL() . PHP_EOL;
        print 'Binds: ' . json_encode($query->getBinds()) . PHP_EOL;
        exit;
    }
}
