<?php

namespace Simsoft\DB\Builder;

use Closure;
use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Conditions\BetweenDateCondition;
use Simsoft\DB\Builder\Conditions\Condition;
use Simsoft\DB\Builder\Conditions\ExistsCondition;
use Simsoft\DB\Builder\Conditions\InCondition;
use Simsoft\DB\Interfaces\Deletable;
use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Interfaces\Updatable;
use Simsoft\DB\Model;
use Simsoft\DB\Traits\Aggregation;
use Simsoft\DB\Traits\Binds;
use Simsoft\DB\Traits\Execute;
use Simsoft\DB\Traits\Fetchable;
use Simsoft\DB\Traits\PlaceHolder;
use Simsoft\DB\Traits\Qualifier;

/**
 * Class ActiveQuery.
 *
 * Fluent SQL query builder for SELECT statements with condition chaining,
 * joins, aggregation, and result fetching.
 */
class ActiveQuery implements Executable, Updatable, Deletable
{
    use Qualifier, Execute, PlaceHolder, Binds, Aggregation, Fetchable;

    /** @var null|string The table name */
    protected ?string $table = null;

    /** @var bool Distinct is enabled */
    protected bool $distinct = false;

    /** @var array<int, string> The select statement. */
    protected array $selects = [];

    /** @var array<int, string> The WHERE statements. */
    protected array $conditions = [];

    /** @var array<string, string> Jointed relationship. */
    protected array $joins = [];

    /** @var array<int, string> The GROUP BY statements. */
    protected array $groupBys = [];

    /** @var array<int, string> The query having */
    protected array $having = [];

    /** @var array<int, string> The query order */
    protected array $orderBys = [];

    /** @var int The limit value */
    protected int $limit = 0;

    /** @var null|int The offset value */
    protected ?int $offset = null;

    /** @var array<int, array{type: string, sql: string}> The UNION statements */
    protected array $unions = [];

    /** @var Closure|string|null Set index by. */
    protected Closure|string|null $indexBy = null;

    /** @var string|null Attribute to be plucked */
    protected ?string $pluckAttribute = null;

    /** @var array<string> Relations to eager load */
    protected array $eagerLoad = [];

    /** @var array<string, callable> Eager load constraints keyed by relation name */
    protected array $eagerLoadConstraints = [];

    /** @var int Cache TTL in seconds. 0 means no caching. */
    protected int $cacheTtl = 0;

    /**
     * Constructor.
     *
     * @param string|Model|null $modelClass The model class
     */
    public function __construct(protected string|null|Model $modelClass = null)
    {
        if ($this->modelClass instanceof Model) {
            $this->from($this->modelClass);
            return;
        }

        if (is_string($this->modelClass)) {
            /** @var Model $instance */
            $instance = new $this->modelClass();
            $this->from($instance);
        }
    }

    /**
     * Enable query result caching.
     *
     * When a cache driver is configured via QueryCache::setDriver(),
     * results will be cached for the specified TTL.
     *
     * @param int $ttl Time-to-live in seconds. Default: 60.
     * @return static
     */
    public function cache(int $ttl = 60): static
    {
        $this->cacheTtl = $ttl;
        return $this;
    }

    /**
     * Get the cache TTL value.
     *
     * @return int
     */
    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    /**
     * Set index by.
     *
     * @param Closure|string $index
     * @return static
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
     * @return static
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
     * Merge with another query object.
     *
     * Only can merge if the other query has the same table/ alias.
     *
     * @param ActiveQuery $query the other Query object
     * @param string $logicalOperator The logical operator. Default 'AND'.
     * @return static
     */
    public function merge(ActiveQuery $query, string $logicalOperator = 'AND'): static
    {
        if ($this->table !== $query->getTable()) {
            throw new \Simsoft\DB\Exceptions\QueryException(
                "Cannot merge queries from different tables: '$this->table' and '{$query->getTable()}'",
                ''
            );
        }

        $this->mergeSelects($query->selects);
        $this->mergeConditions($query->conditions, $logicalOperator);
        $this->mergeHaving($query->having, $logicalOperator);
        $this->mergeSimpleList($this->groupBys, $query->groupBys);
        $this->mergeSimpleList($this->orderBys, $query->orderBys);
        $this->mergeJoins($query->joins);

        $queryBinds = $query->getBinds();
        if ($queryBinds !== null) {
            $this->appendBinds($queryBinds);
        }

        return $this;
    }

    /**
     * Merge select clauses from another query.
     *
     * @param array<int, string> $selects
     * @return void
     */
    private function mergeSelects(array $selects): void
    {
        foreach ($selects as $select) {
            $this->selects[] = $select;
        }
    }

    /**
     * Merge condition clauses with a logical operator separator.
     *
     * @param array<int, string> $conditions
     * @param string $logicalOperator
     * @return void
     */
    private function mergeConditions(array $conditions, string $logicalOperator): void
    {
        if (empty($conditions)) {
            return;
        }

        if (!empty($this->conditions)) {
            $this->conditions[] = $logicalOperator;
        }

        foreach ($conditions as $condition) {
            $this->conditions[] = $condition;
        }
    }

    /**
     * Merge HAVING clauses with a logical operator separator.
     *
     * @param array<int, string> $having
     * @param string $logicalOperator
     * @return void
     */
    private function mergeHaving(array $having, string $logicalOperator): void
    {
        if (empty($having)) {
            return;
        }

        if (!empty($this->having)) {
            $this->having[] = $logicalOperator;
        }

        foreach ($having as $item) {
            $this->having[] = $item;
        }
    }

    /**
     * Merge a simple list (groupBys or orderBys) by appending items.
     *
     * @param array<int, string> $target Reference to the target list.
     * @param array<int, string> $source Items to append.
     * @return void
     */
    private function mergeSimpleList(array &$target, array $source): void
    {
        foreach ($source as $item) {
            $target[] = $item;
        }
    }

    /**
     * Merge JOIN clauses from another query.
     *
     * @param array<string, string> $joins
     * @return void
     */
    private function mergeJoins(array $joins): void
    {
        foreach ($joins as $key => $join) {
            $this->joins[$key] = $join;
        }
    }

    /**
     * Merge with another query object.
     *
     * Prepend 'OR' to the query.
     *
     * @param ActiveQuery $query the other Query object
     * @return static
     */
    public function orMerge(ActiveQuery $query): static
    {
        return $this->merge($query, 'OR');
    }

    /**
     * Is the current query having conditions?
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
     * $this->from('tableName'); // FROM tableName
     * $this->from('tableName t') // FROM tableName AS t
     * $this->from('tableName AS t') // FROM tableName AS t
     * $this->from(['t' => 'SELECT * FROM.']) // FROM (SELECT * FROM ...) AS t sub query
     *
     * @param string|array<string, string|ActiveQuery|Raw>|Model $table the table name
     * @return static
     */
    public function from(string|array|Model $table): static
    {
        if (is_array($table)) {
            $subQuery = current($table);
            $alias = (string)array_key_first($table);
            $this->table = $this->getQualifiedSubQuery((string)$subQuery, $alias);
            if ($subQuery instanceof ActiveQuery || $subQuery instanceof Raw) {
                $this->appendBinds($subQuery->getBinds());
            }
            return $this;
        }

        if ($table instanceof Model) {
            $this->withConnection($table->getConnectionName());
            $table = $table->getTable();
        }

        $expressions = explode(' ', trim($table));
        $table = $expressions[0];
        $alias = end($expressions);
        $this->table = $this->getQualifiedTable($table, $table === $alias ? null : $alias);

        return $this;
    }

    /**
     * Join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table The table name
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @param string $type Join type. LEFT, RIGHT, INNER, OUTER, etc.
     * @return static
     */
    public function join(string|array $table, array $on = [], string $type = 'INNER'): static
    {
        $alias = null;

        if (is_array($table)) {
            $alias = (string)array_key_first($table);
            $subQuery = current($table);
            $table = "($subQuery) AS $alias";
        }

        if (!str_contains($table, '(')) {
            $expressions = explode(' ', trim($table));
            $table = $expressions[0];
            $alias = end($expressions);
        }

        $join = $type ? strtoupper($type) . ' JOIN' : 'JOIN';

        $foreignKey = (string)array_key_first($on);
        $localKey = (string)current($on);

        if ($table === $alias) {
            $quotedTable = $this->quote($table);
            $this->joins[$table] = "$join $quotedTable ON $quotedTable." . $this->quote($foreignKey) . " = " . $this->queryAttribute($localKey);
            return $this;
        }

        $qt = $this->quote($table);
        $qa = $this->quote((string)$alias);
        $this->joins[(string)$alias] = "$join $qt AS $qa ON $qa." . $this->quote($foreignKey) . " = " . $this->queryAttribute($localKey);

        return $this;
    }

    /**
     * Cross-join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table the join table
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
     */
    public function crossJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'CROSS');
    }

    /**
     * Left join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table the join table
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
     */
    public function leftJoin(string|array $table, array $on = []): static
    {
        return $this->join($table, $on, 'LEFT');
    }

    /**
     * Right join table.
     *
     * @param string|array<string, string|ActiveQuery|Raw> $table the join table
     * @param array<string, string> $on The matching attributes. ['join_table_attribute' => 'main_table_attribute']
     * @return static
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
     * @return static
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
     * @return static
     */
    public function rightOuterJoin(string $table, array $on = []): static
    {
        return $this->join($table, $on, 'RIGHT OUTER');
    }

    /**
     * Apply conditions with a temporary alias.
     *
     * @param string $alias The alias to use temporarily.
     * @param callable $condition The condition callback.
     * @return static
     */
    public function withAlias(string $alias, callable $condition): static
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
     * @param string|Raw ...$attributes the list of attribute value to be select
     * @return static
     */
    public function select(string|Raw ...$attributes): static
    {
        foreach ($attributes as $attribute) {
            $this->selects[] = $attribute instanceof Raw
                ? (string)$attribute
                : $this->queryAttribute($attribute);
        }
        return $this;
    }

    /**
     * Select distinct statement
     *
     * @param string|Raw ...$attributes List of SELECT attributes
     * @return static
     */
    public function selectDistinct(string|Raw ...$attributes): static
    {
        $this->select(...$attributes);
        return $this->distinct();
    }

    /**
     * Construct the query conditions.
     *
     * @param string|array<mixed>|callable|Raw|Clause $attribute the attribute
     * @param mixed $operator the comparison operator or the attribute value
     * @param mixed $value the value for the attribute
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return static
     */
    public function where(
        string|array|callable|Raw|Clause $attribute,
        mixed  $operator = '=',
        mixed  $value = null,
        string $logicalOperator = 'AND'
    ): static
    {
        if ($attribute instanceof Closure) {
            return $this->applyClosureCondition($attribute, $logicalOperator);
        }

        if ($attribute instanceof Clause) {
            return $this->onCondition($attribute->alias($this->getAlias()), $logicalOperator);
        }

        // Normalise operator/value and handle NULL comparisons for string attributes
        if (is_string($attribute)) {
            $resolved = $this->resolveNullCondition($attribute, $operator, $value, $logicalOperator);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        [$operator, $value] = $this->normaliseOperatorValue($operator, $value);

        // Fast path: a simple string attribute with scalar value (the most common case)
        // Avoids Condition object allocation entirely
        if (is_string($attribute) && !is_array($value)) {
            $sql = $this->queryAttribute($attribute) . " $operator ?";
            $this->addConditionSQL($sql, $value, $logicalOperator);
            return $this;
        }

        return $this->onCondition(
            (new Condition($attribute, $value))
                ->operator($operator),
            $logicalOperator
        );
    }

    /**
     * Normalise operator/value when called as where('col', 'value') shorthand.
     *
     * When $value is null and $operator is not a comparison operator, treat
     * $operator as the value and default the operator to '='.
     *
     * @param mixed $operator The operator or shorthand value.
     * @param mixed $value The value.
     * @return array{0: mixed, 1: mixed} [operator, value]
     */
    private function normaliseOperatorValue(mixed $operator, mixed $value): array
    {
        if ($value === null && $operator !== '=' && $operator !== '!=' && $operator !== null) {
            return ['=', $operator];
        }
        return [$operator, $value];
    }

    /**
     * Apply a Closure as a grouped condition block.
     *
     * @param Closure $closure The closure to bind and call.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    private function applyClosureCondition(Closure $closure, string $logicalOperator): static
    {
        $callable = Closure::bind($closure, $this, get_class($this));
        if ($callable) {
            $this->onCondition('(', $logicalOperator);
            $callable($this);
            return $this->onCondition(')');
        }
        return $this;
    }

    /**
     * Resolve NULL-related conditions for a string attribute.
     *
     * Returns a static result when the condition is a NULL check, or null
     * when normal processing should continue.
     *
     * @param string $attribute The attribute name.
     * @param mixed $operator The operator.
     * @param mixed $value The value.
     * @param string $logicalOperator The logical operator.
     * @return static|null
     */
    private function resolveNullCondition(string $attribute, mixed $operator, mixed $value, string $logicalOperator): ?static
    {
        // where('col', null) → IS NULL
        if ($value === null && $operator === null) {
            return $this->isNull($attribute, $logicalOperator);
        }

        // where('col', '=', null) → IS NULL
        if ($value === null && $operator === '=') {
            return $this->isNull($attribute, $logicalOperator);
        }

        // where('col', '!=', null) → IS NOT NULL
        if ($value === null && $operator === '!=') {
            return $this->notNull($attribute, $logicalOperator);
        }

        return null;
    }

    /**
     * Add a pre-built condition SQL string with its bind value(s).
     *
     * @param string $sql The condition SQL fragment.
     * @param mixed $bindValue The bind value (scalar or array).
     * @param string $logicalOperator The logical operator.
     * @return void
     */
    private function addConditionSQL(string $sql, mixed $bindValue, string $logicalOperator): void
    {
        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        $this->appendBinds($bindValue);
    }

    /**
     * Or condition.
     *
     * @param string|callable|Raw $attribute the attribute name
     * @param string|null $operator the comparison operator or the attribute value
     * @param mixed $value the value for the attribute
     */
    public function orWhere(string|callable|Raw $attribute, ?string $operator = '=', mixed $value = null): static
    {
        return $this->where($attribute, $operator, $value, 'OR');
    }

    /**
     * Not a condition query.
     *
     * @param string $attribute the attribute name
     * @param mixed $value
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return static
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
     * @return static
     */
    public function orNot(string $attribute, mixed $value): static
    {
        return $this->not($attribute, $value, 'OR');
    }

    /**
     * Where not condition (alias for not).
     *
     * @param string $attribute The attribute name.
     * @param mixed $value The value.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereNot(string $attribute, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->not($attribute, $value, $logicalOperator);
    }

    /**
     * Or where not condition (alias for orNot).
     *
     * @param string $attribute The attribute name.
     * @param mixed $value The value.
     * @return static
     */
    public function orWhereNot(string $attribute, mixed $value): static
    {
        return $this->not($attribute, $value, 'OR');
    }

    /**
     * Is null condition.
     *
     * @param string $attribute the attribute name
     * @param string $logicalOperator The logical operator. Either: 'AND' or 'OR'.
     * @return static
     */
    public function isNull(string $attribute, string $logicalOperator = 'AND'): static
    {
        $sql = $this->queryAttribute($attribute) . ' IS NULL';

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    /**
     * Or is a null condition.
     *
     * @param string $attribute the attribute name
     * @return static
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
     * @return static
     */
    public function notNull(string $attribute, string $logicalOperator = 'AND'): static
    {
        $sql = $this->queryAttribute($attribute) . ' IS NOT NULL';

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    /**
     * Or not null condition.
     *
     * @param string $attribute the attribute name
     * @return static
     */
    public function orNotNull(string $attribute): static
    {
        return $this->notNull($attribute, 'OR');
    }

    /**
     * Where null condition (alias for isNull).
     *
     * @param string $attribute The attribute name.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereNull(string $attribute, string $logicalOperator = 'AND'): static
    {
        return $this->isNull($attribute, $logicalOperator);
    }

    /**
     * Or where null condition (alias for orIsNull).
     *
     * @param string $attribute The attribute name.
     * @return static
     */
    public function orWhereNull(string $attribute): static
    {
        return $this->isNull($attribute, 'OR');
    }

    /**
     * Where not null condition (alias for notNull).
     *
     * @param string $attribute The attribute name.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereNotNull(string $attribute, string $logicalOperator = 'AND'): static
    {
        return $this->notNull($attribute, $logicalOperator);
    }

    /**
     * Or where not null condition (alias for orNotNull).
     *
     * @param string $attribute The attribute name.
     * @return static
     */
    public function orWhereNotNull(string $attribute): static
    {
        return $this->notNull($attribute, 'OR');
    }

    /**
     * Where any of the given columns match the condition (OR logic).
     *
     * Generates: WHERE (col1 op ? OR col2 op ? OR col3 op ?)
     *
     * @param array<int, string> $columns The columns to check.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param string $logicalOperator The logical operator for the outer condition.
     * @return static
     */
    public function whereAny(array $columns, string $operator, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->whereMultiColumn($columns, $operator, $value, 'OR', false, $logicalOperator);
    }

    /**
     * Or where any of the given columns match the condition.
     *
     * @param array<int, string> $columns The columns to check.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static
     */
    public function orWhereAny(array $columns, string $operator, mixed $value): static
    {
        return $this->whereAny($columns, $operator, $value, 'OR');
    }

    /**
     * Where all of the given columns match the condition (AND logic).
     *
     * Generates: WHERE (col1 op ? AND col2 op ? AND col3 op ?)
     *
     * @param array<int, string> $columns The columns to check.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param string $logicalOperator The logical operator for the outer condition.
     * @return static
     */
    public function whereAll(array $columns, string $operator, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->whereMultiColumn($columns, $operator, $value, 'AND', false, $logicalOperator);
    }

    /**
     * Or where all of the given columns match the condition.
     *
     * @param array<int, string> $columns The columns to check.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static
     */
    public function orWhereAll(array $columns, string $operator, mixed $value): static
    {
        return $this->whereAll($columns, $operator, $value, 'OR');
    }

    /**
     * Where none of the given columns match the condition (negated OR).
     *
     * Generates: WHERE NOT (col1 op ? OR col2 op ? OR col3 op ?)
     *
     * @param array<int, string> $columns The columns to check.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param string $logicalOperator The logical operator for the outer condition.
     * @return static
     */
    public function whereNone(array $columns, string $operator, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->whereMultiColumn($columns, $operator, $value, 'OR', true, $logicalOperator);
    }

    /**
     * Or where none of the given columns match the condition.
     *
     * @param array<int, string> $columns The columns to check.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static
     */
    public function orWhereNone(array $columns, string $operator, mixed $value): static
    {
        return $this->whereNone($columns, $operator, $value, 'OR');
    }

    /**
     * Build a multi-column condition group.
     *
     * @param array<int, string> $columns The columns.
     * @param string $operator The comparison operator.
     * @param mixed $value The value.
     * @param string $joiner Inner logical operator (AND or OR).
     * @param bool $negate Whether to wrap with NOT.
     * @param string $logicalOperator Outer logical operator.
     * @return static
     */
    private function whereMultiColumn(
        array $columns,
        string $operator,
        mixed $value,
        string $joiner,
        bool $negate,
        string $logicalOperator
    ): static {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = $this->queryAttribute($column) . " $operator ?";
            $this->appendBinds($value);
        }

        $group = '(' . implode(" $joiner ", $parts) . ')';
        $sql = $negate ? "NOT $group" : $group;

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    /**
     * Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @param string $logicalOperator
     * @return static
     */
    public function exists(ActiveQuery|Raw $query, string $logicalOperator = 'AND'): static
    {
        return $this->onCondition(new ExistsCondition($query), $logicalOperator);
    }

    /**
     * Or there Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @return static
     */
    public function orExists(ActiveQuery|Raw $query): static
    {
        return $this->exists($query, 'OR');
    }

    /**
     * Not there Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @param string $logicalOperator
     * @return static
     */
    public function notExists(ActiveQuery|Raw $query, string $logicalOperator = 'AND'): static
    {
        return $this->onCondition(new ExistsCondition($query, is: false), $logicalOperator);
    }

    /**
     * Or not there Exists condition.
     *
     * @param ActiveQuery|Raw $query
     * @return static
     */
    public function orNotExists(ActiveQuery|Raw $query): static
    {
        return $this->notExists($query, 'OR');
    }

    /**
     * In condition.
     *
     * @param string $attribute the attribute name
     * @param array<int, mixed>|ActiveQuery|Raw $values the array of values for the query
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @return static
     */
    public function in(string $attribute, array|ActiveQuery|Raw $values, string $logicalOperator = 'AND'): static
    {
        if (!$values) {
            return $this;
        }

        // Fast path for array values (most common)
        if (is_array($values)) {
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $sql = "{$this->queryAttribute($attribute)} IN ($placeholders)";
            $this->addConditionSQL($sql, $values, $logicalOperator);
            return $this;
        }

        return $this->onCondition(new InCondition($attribute, $values), $logicalOperator);
    }

    /**
     * Or in condition.
     *
     * @param string $attribute the attribute name
     * @param array<int, mixed>|ActiveQuery|Raw $values the array of values for the query
     * @return static
     */
    public function orIn(string $attribute, array|ActiveQuery|Raw $values): static
    {
        return $this->in($attribute, $values, 'OR');
    }

    /**
     * Not in condition.
     *
     * @param string $attribute the attribute name
     * @param array<int, mixed>|ActiveQuery|Raw $values the array of values for the query
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @return static
     */
    public function notIn(string $attribute, array|ActiveQuery|Raw $values, string $logicalOperator = 'AND'): static
    {
        if (!$values) {
            return $this;
        }

        // Fast path for array values
        if (is_array($values)) {
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $sql = "{$this->queryAttribute($attribute)} NOT IN ($placeholders)";
            $this->addConditionSQL($sql, $values, $logicalOperator);
            return $this;
        }

        return $this->onCondition(new InCondition($attribute, $values, false), $logicalOperator);
    }

    /**
     * Or not in condition.
     *
     * @param string $attribute the attribute name
     * @param array<int, mixed>|ActiveQuery|Raw $values the array of values for the query
     * @return static
     */
    public function orNotIn(string $attribute, array|ActiveQuery|Raw $values): static
    {
        return $this->notIn($attribute, $values, 'OR');
    }

    /**
     * Where in condition (alias for in).
     *
     * @param string $attribute The attribute name.
     * @param array<int, mixed>|ActiveQuery|Raw $values The values.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereIn(string $attribute, array|ActiveQuery|Raw $values, string $logicalOperator = 'AND'): static
    {
        return $this->in($attribute, $values, $logicalOperator);
    }

    /**
     * Or where in condition (alias for orIn).
     *
     * @param string $attribute The attribute name.
     * @param array<int, mixed>|ActiveQuery|Raw $values The values.
     * @return static
     */
    public function orWhereIn(string $attribute, array|ActiveQuery|Raw $values): static
    {
        return $this->in($attribute, $values, 'OR');
    }

    /**
     * Where not in condition (alias for notIn).
     *
     * @param string $attribute The attribute name.
     * @param array<int, mixed>|ActiveQuery|Raw $values The values.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereNotIn(string $attribute, array|ActiveQuery|Raw $values, string $logicalOperator = 'AND'): static
    {
        return $this->notIn($attribute, $values, $logicalOperator);
    }

    /**
     * Or where not in condition (alias for orNotIn).
     *
     * @param string $attribute The attribute name.
     * @param array<int, mixed>|ActiveQuery|Raw $values The values.
     * @return static
     */
    public function orWhereNotIn(string $attribute, array|ActiveQuery|Raw $values): static
    {
        return $this->notIn($attribute, $values, 'OR');
    }

    /**
     * Like condition.
     *
     * Case-sensitive by default (plain LIKE). Set caseSensitive: false for case-insensitive matching.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $is the comparison operator (true = LIKE, false = NOT LIKE)
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: true.
     * @return static
     */
    public function like(
        string       $attribute,
        string|array $value,
        bool         $is = true,
        bool         $matchAll = true,
        string       $logicalOperator = 'AND',
        bool         $caseSensitive = true
    ): static
    {
        [$col, $operator, $useLowerBind] = $this->prepareLikeColumn($attribute, $is, $caseSensitive);

        // Fast path: single string value (most common)
        if (is_string($value)) {
            $sql = $this->buildLikePart($col, $operator, $useLowerBind);
            $this->addConditionSQL($sql, $value, $logicalOperator);
            return $this;
        }

        // Array of values — build compound condition
        $joiner = $matchAll ? ' AND ' : ' OR ';
        $parts = array_fill(0, count($value), $this->buildLikePart($col, $operator, $useLowerBind));
        $binds = array_values($value);

        $sql = '(' . implode($joiner, $parts) . ')';
        $this->addConditionSQL($sql, $binds, $logicalOperator);
        return $this;
    }

    /**
     * Prepare the column expression and operator for LIKE based on case sensitivity.
     *
     * @param string $attribute The attribute name.
     * @param bool $is Whether positive (LIKE) or negated (NOT LIKE).
     * @param bool $caseSensitive Whether the comparison is case-sensitive.
     * @return array{0: string, 1: string, 2: bool} [column, operator, useLowerBind]
     */
    private function prepareLikeColumn(string $attribute, bool $is, bool $caseSensitive): array
    {
        $col = $this->queryAttribute($attribute);
        $operator = $is ? 'LIKE' : 'NOT LIKE';

        if ($caseSensitive) {
            return [$col, $operator, false];
        }

        if ($this->getGrammar()->getDriverName() === 'pgsql') {
            return [$col, $is ? 'ILIKE' : 'NOT ILIKE', false];
        }

        return ["LOWER($col)", $operator, true];
    }

    /**
     * Build a single LIKE expression segment.
     *
     * @param string $col The column expression.
     * @param string $operator The LIKE operator.
     * @param bool $useLowerBind Whether to wrap the placeholder in LOWER().
     * @return string
     */
    private function buildLikePart(string $col, string $operator, bool $useLowerBind): string
    {
        return $useLowerBind ? "$col $operator LOWER(?)" : "$col $operator ?";
    }

    /**
     * Or like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @return static
     */
    public function orLike(string $attribute, string|array $value, bool $matchAll = true, bool $caseSensitive = true): static
    {
        return $this->like($attribute, $value, true, $matchAll, 'OR', $caseSensitive);
    }

    /**
     * Not like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @return static
     */
    public function notLike(string $attribute, string|array $value, bool $matchAll = true, bool $caseSensitive = true): static
    {
        return $this->like($attribute, $value, false, $matchAll, 'AND', $caseSensitive);
    }

    /**
     * Or not like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @return static
     */
    public function orNotLike(string $attribute, string|array $value, bool $matchAll = true, bool $caseSensitive = true): static
    {
        return $this->like($attribute, $value, false, $matchAll, 'OR', $caseSensitive);
    }

    /**
     * Between condition.
     *
     * @param string $attribute the attribute name
     * @param mixed $start the start value
     * @param mixed $end the end value
     * @param bool $is determine the comparison operator
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     * @return static
     */
    public function between(string $attribute, mixed $start, mixed $end, bool $is = true, string $logicalOperator = 'AND'): static
    {
        $col = $this->queryAttribute($attribute);
        $keyword = $is ? 'BETWEEN' : 'NOT BETWEEN';
        $sql = "$col $keyword ? AND ?";
        $this->addConditionSQL($sql, [$start, $end], $logicalOperator);
        return $this;
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
     * @return static
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
     * @return static
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
     * @return static
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
     * @return static
     */
    public function orNotRegex(string $attribute, string $regex): static
    {
        return $this->notRegex($attribute, $regex, 'OR');
    }

    /**
     * Attribute contains a word.
     *
     * @param string $attribute The attribute name.
     * @param string|array<int, string> $words The words to search.
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'
     * @return static
     */
    public function containsWords(string $attribute, string|array $words, string $logicalOperator = 'AND'): static
    {
        if (is_array($words)) {
            $conditions = [];
            foreach ($words as $word) {
                $conditions[] = [$attribute, 'REGEXP', '\\b' . addslashes(quotemeta($word)) . '\\b'];
            }

            return $this->where($conditions, logicalOperator: $logicalOperator);
        }

        return $this->regex($attribute, '\\b' . addslashes(quotemeta($words)) . '\\b', $logicalOperator);
    }

    /**
     * Attribute contains a word.
     *
     * @param string $attribute
     * @param string|array<int, string> $words
     * @return static
     */
    public function orContainsWords(string $attribute, string|array $words): static
    {
        return $this->containsWords($attribute, $words, 'OR');
    }

    /**
     * Raw query statement.
     *
     * @param string $statement
     * @param array<int, mixed>|null $binds
     * @return static
     */
    public function whereRaw(string $statement, ?array $binds = null): static
    {
        return $this->where(new Raw($statement, $binds));
    }

    /**
     * Or raw query statement.
     *
     * @param string $statement
     * @param array<int, mixed>|null $binds
     * @return static
     */
    public function orWhereRaw(string $statement, ?array $binds = null): static
    {
        return $this->where(new Raw($statement, $binds), logicalOperator: 'OR');
    }

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
     */
    public function having(mixed $attribute, ?string $operator = '=', mixed $value = null): static
    {
        if ($value === null && $operator != '=') {
            $value = $operator;
            $operator = '=';
        }

        $condition = (new Condition($attribute, $value))
            ->operator($operator ?? '=')
            ->setPlaceHolder($this->getPlaceHolder());

        // Eagerly build and collect binds
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

    /**
     * Order by statement.
     *
     * Example usages:
     *
     * $this->orderBy('attribute') // ORDER BY table. Attribute ASC
     * $this->orderBy('attribute', 'DESC'); // ORDER by table. Attribute DESC
     * $this->orderBy([ // ORDER BY table.attribute1 ASC, table.attribute2 DESC
     *  'attribute1' => 'ASC',
     *  'attribute2' => 'DESC',
     * ]);
     *
     * @param string|array<string, string> $attribute the attribute
     * @param string $direction the order direction for the attribute
     * @return static
     */
    public function orderBy(string|array $attribute, string $direction = 'ASC'): static
    {
        if (is_array($attribute)) {
            foreach ($attribute as $col => $dir) {
                $this->orderBys[] = $this->queryAttribute($col) . ' ' . strtoupper($dir);
            }
            return $this;
        }

        if (strtoupper($attribute) === 'RAND()') {
            $this->orderBys[] = 'RAND()';
            return $this;
        }

        $dir = strtoupper($direction);
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            $dir = 'ASC';
        }

        $this->orderBys[] = $this->queryAttribute($attribute) . ' ' . $dir;
        return $this;
    }

    /**
     * Order by desc.
     *
     * @param string|array<string, string> $attribute The attribute name.
     * @return static
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
     * Check if a limit has been explicitly set.
     *
     * @return bool
     */
    public function hasLimit(): bool
    {
        return $this->limit > 0;
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
     * @return static
     */
    public function page(int $currentPage, int $maxPerPage = 50): static
    {
        return $this->limit($maxPerPage, --$currentPage * $maxPerPage);
    }

    /**
     * Add a UNION query.
     *
     * @param ActiveQuery $query The query to union.
     * @return static
     */
    public function union(ActiveQuery $query): static
    {
        $this->unions[] = ['type' => 'UNION', 'sql' => (string)$query];
        $this->appendBinds($query->getBinds());
        return $this;
    }

    /**
     * Add a UNION DISTINCT query.
     *
     * @param ActiveQuery $query The query to union.
     * @return static
     */
    public function unionDistinct(ActiveQuery $query): static
    {
        $this->unions[] = ['type' => 'UNION DISTINCT', 'sql' => (string)$query];
        $this->appendBinds($query->getBinds());
        return $this;
    }

    /**
     * Add a UNION ALL query.
     *
     * @param ActiveQuery $query The query to union.
     * @return static
     */
    public function unionAll(ActiveQuery $query): static
    {
        $this->unions[] = ['type' => 'UNION ALL', 'sql' => (string)$query];
        $this->appendBinds($query->getBinds());
        return $this;
    }

    /**
     * Append to existing query conditions.
     *
     * @param string|Clause $query the SQL query statement
     * @param null|string $operator The logical operator. Either: "AND" or "OR".
     * @return static
     */
    protected function onCondition(string|Clause $query, ?string $operator = null): static
    {
        if ($operator && $this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $operator;
        }

        if ($query instanceof Clause) {
            $query->setPlaceHolder($this->getPlaceHolder());
            // Eagerly build SQL and collect binds now
            $this->conditions[] = (string)$query;
            if ($query->getBinds()) {
                $this->appendBinds($query->getBinds());
            }
            return $this;
        }

        $this->conditions[] = $query;
        return $this;
    }

    /**
     * Get SQL statement.
     *
     * Builds the full SELECT SQL directly without intermediate object allocation.
     *
     * @return string
     */
    public function getSQL(): string
    {
        $from = "FROM $this->table";
        $alias = $this->getAlias();
        if ($alias !== null && $this->table !== null) {
            $quotedAlias = $this->quote($alias);
            if (!str_contains($this->table, $quotedAlias)) {
                // Replace any existing alias or append new one
                // a Table format is either `table` or `table` `old_alias`
                $parts = explode(' ', $this->table, 2);
                $from = "FROM " . $parts[0] . " " . $quotedAlias;
            }
        }

        $segments = [$this->buildSelectClause(), $from];

        $clauses = [
            $this->getJoinSQL(),
            $this->getWhereSQL(),
            $this->getGroupSQL(),
            $this->getHavingSQL(),
            $this->getOrderSQL(),
            $this->getLimitSQL(),
        ];

        foreach ($clauses as $clause) {
            if ($clause !== null) {
                $segments[] = $clause;
            }
        }

        $sql = $this->getQualifiedSQL(implode(' ', $segments));

        return empty($this->unions) ? $sql : $this->buildUnionSQL($sql);
    }

    /**
     * Build the SELECT clause (SELECT [DISTINCT] columns).
     *
     * @return string
     */
    private function buildSelectClause(): string
    {
        $sql = 'SELECT ';

        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= empty($this->selects)
            ? $this->queryAttribute('*')
            : implode(', ', $this->selects);

        return $sql;
    }

    /**
     * Wrap the base SQL with UNION queries.
     *
     * @param string $baseSql The base SELECT SQL.
     * @return string
     */
    private function buildUnionSQL(string $baseSql): string
    {
        // SQLite does not support parenthesized SELECT in UNION
        $wrap = $this->getGrammar()->getDriverName() !== 'sqlite';

        $parts = [$wrap ? '(' . $baseSql . ')' : $baseSql];
        foreach ($this->unions as $union) {
            $parts[] = $union['type'];
            $parts[] = $wrap ? '(' . $union['sql'] . ')' : $union['sql'];
        }
        return implode(' ', $parts);
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
     * Generate WHERE statement.
     *
     * All binds are already collected at condition-building time.
     *
     * @return string|null
     */
    public function getWhereSQL(): ?string
    {
        if (empty($this->conditions)) {
            return null;
        }

        return 'WHERE ' . implode(' ', $this->conditions);
    }

    /**
     * Generate a GROUP BY statement.
     *
     * @return string|null
     */
    public function getGroupSQL(): ?string
    {
        return empty($this->groupBys) ? null : 'GROUP BY ' . implode(', ', $this->groupBys);
    }

    /**
     * Generate HAVING statement.
     *
     * @return string|null
     */
    public function getHavingSQL(): ?string
    {
        return empty($this->having) ? null : 'HAVING ' . implode(', ', $this->having);
    }

    /**
     * Generate an ORDER BY statement.
     *
     * @return string|null
     */
    public function getOrderSQL(): ?string
    {
        return empty($this->orderBys) ? null : 'ORDER BY ' . implode(', ', $this->orderBys);
    }

    /**
     * Generate LIMIT statement.
     *
     * @return string|null
     */
    public function getLimitSQL(): ?string
    {
        if (!$this->limit) {
            return null;
        }

        return $this->getGrammar()->limitSQL($this->limit, $this->offset);
    }

    /**
     * Specify relations to eager load.
     *
     * Supports multiple formats:
     *   ->with('posts', 'profile')                    // simple
     *   ->with('posts.comments')                      // nested
     *   ->with(['posts' => fn($q) => $q->where(...)]) // constrained
     *
     * @param string|array<string|int, string|callable> ...$relations Relation names or [name => callback] array.
     * @return static
     */
    public function with(string|array ...$relations): static
    {
        foreach ($relations as $relation) {
            if (is_array($relation)) {
                foreach ($relation as $name => $constraint) {
                    if (is_string($name) && is_callable($constraint)) {
                        $this->eagerLoad[] = $name;
                        $this->eagerLoadConstraints[$name] = $constraint;
                        continue;
                    }
                    // Numeric key with string value: ['posts', 'profile']
                    if (is_int($name) && is_string($constraint)) {
                        $this->eagerLoad[] = $constraint;
                    }
                }
                continue;
            }
            $this->eagerLoad[] = $relation;
        }
        return $this;
    }

    /**
     * Get the relations to eager load.
     *
     * @return array<string>
     */
    public function getEagerLoad(): array
    {
        return $this->eagerLoad;
    }

    /**
     * Get the eager load constraints.
     *
     * @return array<string, callable>
     */
    public function getEagerLoadConstraints(): array
    {
        return $this->eagerLoadConstraints;
    }

    /**
     * Apply a scope (reusable query fragment).
     *
     * @param callable $scope A callable that receives this query instance.
     * @return static
     */
    public function scope(callable $scope): static
    {
        $scope($this);
        return $this;
    }

    /**
     * Apply a scope conditionally.
     *
     * When the condition is true, applies $scope.
     * When the condition is false and $otherwise is provided, applies $otherwise.
     *
     * @param bool $condition Whether to apply the scope.
     * @param callable $scope The scope to apply when the condition is true.
     * @param callable|null $otherwise Optional scope to apply when the condition is false.
     * @return static
     */
    public function when(bool $condition, callable $scope, ?callable $otherwise = null): static
    {
        if ($condition) {
            $scope($this);
            return $this;
        }

        if ($otherwise !== null) {
            $otherwise($this);
        }

        return $this;
    }

    /**
     * Apply a scope when the condition is false (inverse of when).
     *
     * @param bool $condition When false, the scope is applied.
     * @param callable $scope The scope to apply.
     * @param callable|null $otherwise Optional scope to apply when the condition is true.
     * @return static
     */
    public function unless(bool $condition, callable $scope, ?callable $otherwise = null): static
    {
        return $this->when(!$condition, $scope, $otherwise);
    }

    /**
     * Inspect the query without modifying it.
     *
     * Useful for debugging during method chaining.
     *
     * @param callable $callback Receives the query instance. Return value is ignored.
     * @return static
     */
    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    /**
     * Get select clauses.
     *
     * @return array<int, string>
     */
    public function getSelects(): array
    {
        return $this->selects;
    }

    /**
     * Get condition clauses.
     *
     * @return array<int, string>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Filter by the date part of a datetime column.
     *
     * Supports shorthand: whereDate('col', '2024-01-01') defaults operator to '='.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator or value (shorthand).
     * @param string|null $value The date value.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereDate(string $column, string $operator, ?string $value = null, string $logicalOperator = 'AND'): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $col = $this->queryAttribute($column);
        $sql = $this->getGrammar()->dateExtract($col) . " $operator ?";
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Or filter by the date part of a datetime column.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator or value (shorthand).
     * @param string|null $value The date value.
     * @return static
     */
    public function orWhereDate(string $column, string $operator, ?string $value = null): static
    {
        return $this->whereDate($column, $operator, $value, 'OR');
    }

    /**
     * Filter by the month part of a datetime column.
     *
     * Supports shorthand: whereMonth('col', 3) defaults operator to '='.
     *
     * @param string $column The column name.
     * @param string|int $operator The comparison operator or value (shorthand).
     * @param int|null $value The month value (1-12).
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereMonth(string $column, string|int $operator, ?int $value = null, string $logicalOperator = 'AND'): static
    {
        if ($value === null) {
            $value = (int) $operator;
            $operator = '=';
        }

        $col = $this->queryAttribute($column);
        $sql = $this->getGrammar()->monthExtract($col) . " $operator ?";
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Or filter by the month part of a datetime column.
     *
     * @param string $column The column name.
     * @param string|int $operator The comparison operator or value (shorthand).
     * @param int|null $value The month value (1-12).
     * @return static
     */
    public function orWhereMonth(string $column, string|int $operator, ?int $value = null): static
    {
        return $this->whereMonth($column, $operator, $value, 'OR');
    }

    /**
     * Filter by the year part of a datetime column.
     *
     * Supports shorthand: whereYear('col', 2024) defaults operator to '='.
     *
     * @param string $column The column name.
     * @param string|int $operator The comparison operator or value (shorthand).
     * @param int|null $value The year value.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereYear(string $column, string|int $operator, ?int $value = null, string $logicalOperator = 'AND'): static
    {
        if ($value === null) {
            $value = (int) $operator;
            $operator = '=';
        }

        $col = $this->queryAttribute($column);
        $sql = $this->getGrammar()->yearExtract($col) . " $operator ?";
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Or filter by the year part of a datetime column.
     *
     * @param string $column The column name.
     * @param string|int $operator The comparison operator or value (shorthand).
     * @param int|null $value The year value.
     * @return static
     */
    public function orWhereYear(string $column, string|int $operator, ?int $value = null): static
    {
        return $this->whereYear($column, $operator, $value, 'OR');
    }

    /**
     * Filter by the time part of a datetime column.
     *
     * Supports shorthand: whereTime('col', '10:00:00') defaults operator to '='.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator or value (shorthand).
     * @param string|null $value The time value.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereTime(string $column, string $operator, ?string $value = null, string $logicalOperator = 'AND'): static
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $col = $this->queryAttribute($column);
        $sql = $this->getGrammar()->timeExtract($col) . " $operator ?";
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Or filter by the time part of a datetime column.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator or value (shorthand).
     * @param string|null $value The time value.
     * @return static
     */
    public function orWhereTime(string $column, string $operator, ?string $value = null): static
    {
        return $this->whereTime($column, $operator, $value, 'OR');
    }

    /**
     * Compare two columns.
     *
     * @param string $first The first column.
     * @param string $operator The comparison operator.
     * @param string $second The second column.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereColumn(string $first, string $operator, string $second, string $logicalOperator = 'AND'): static
    {
        $sql = $this->queryAttribute($first) . " $operator " . $this->queryAttribute($second);

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    /**
     * Query a JSON column path with a comparison operator.
     *
     * @param string $column The JSON column (e.g., 'meta->age' or 'data->address.city').
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereJson(string $column, string $operator, mixed $value, string $logicalOperator = 'AND'): static
    {
        [$col, $path] = $this->parseJsonPath($column);
        $extract = $this->getGrammar()->jsonExtract($this->qualifyJsonColumn($col), $path);
        $sql = "$extract $operator ?";
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Query a JSON column path for equality.
     *
     * Shorthand for whereJson($column, '=', $value).
     *
     * @param string $column The JSON column path (e.g., 'meta->status').
     * @param mixed $value The value to match.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereJsonValue(string $column, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->whereJson($column, '=', $value, $logicalOperator);
    }

    /**
     * Or where JSON column path equals a value.
     *
     * @param string $column The JSON column path (e.g., 'meta->status').
     * @param mixed $value The value to match.
     * @return static
     */
    public function orWhereJsonValue(string $column, mixed $value): static
    {
        return $this->whereJson($column, '=', $value, 'OR');
    }

    /**
     * Query a JSON column path for equality (short alias).
     *
     * Shorthand for whereJson($column, '=', $value).
     *
     * @param string $column The JSON column path (e.g., 'meta->status').
     * @param mixed $value The value to match.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function jsonValue(string $column, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->whereJson($column, '=', $value, $logicalOperator);
    }

    /**
     * Or JSON column path equality (short alias).
     *
     * Shorthand for orJson($column, '=', $value).
     *
     * @param string $column The JSON column path (e.g., 'meta->status').
     * @param mixed $value The value to match.
     * @return static
     */
    public function orJsonValue(string $column, mixed $value): static
    {
        return $this->whereJson($column, '=', $value, 'OR');
    }

    // ------------------------------------------------------------------
    // JSON primary methods (short names)
    // ------------------------------------------------------------------

    /**
     * Check if a JSON array/object contains a value.
     *
     * @param string $column The JSON column path (e.g., 'meta->tags').
     * @param mixed $value The value to check for.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function jsonContains(string $column, mixed $value, string $logicalOperator = 'AND'): static
    {
        [$col, $path] = $this->parseJsonPath($column);
        $sql = $this->getGrammar()->jsonContains($this->qualifyJsonColumn($col), $path);
        $bindValue = json_encode($value);
        $this->addConditionSQL($sql, $bindValue, $logicalOperator);
        return $this;
    }

    /**
     * Check if a JSON array/object does NOT contain a value.
     *
     * @param string $column The JSON column path (e.g., 'meta->tags').
     * @param mixed $value The value to check for absence.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function jsonNotContains(string $column, mixed $value, string $logicalOperator = 'AND'): static
    {
        [$col, $path] = $this->parseJsonPath($column);
        $sql = 'NOT ' . $this->getGrammar()->jsonContains($this->qualifyJsonColumn($col), $path);
        $bindValue = json_encode($value);
        $this->addConditionSQL($sql, $bindValue, $logicalOperator);
        return $this;
    }

    /**
     * Check if a JSON key exists at the given path.
     *
     * @param string $column The JSON column path (e.g., 'meta->address').
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function jsonHas(string $column, string $logicalOperator = 'AND'): static
    {
        [$col, $path] = $this->parseJsonPath($column);
        $jsonPath = $path === '' ? '$' : '$.' . $path;
        $sql = "JSON_CONTAINS_PATH(" . $this->qualifyJsonColumn($col) . ", 'one', '$jsonPath')";

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    /**
     * Check if a JSON key does NOT exist at the given path.
     *
     * @param string $column The JSON column path (e.g., 'meta->address').
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function jsonMissing(string $column, string $logicalOperator = 'AND'): static
    {
        [$col, $path] = $this->parseJsonPath($column);
        $jsonPath = $path === '' ? '$' : '$.' . $path;
        $sql = "NOT JSON_CONTAINS_PATH(" . $this->qualifyJsonColumn($col) . ", 'one', '$jsonPath')";

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    // ------------------------------------------------------------------
    // JSON OR variants
    // ------------------------------------------------------------------

    /**
     * Or variant of jsonContains.
     *
     * @param string $column The JSON column path.
     * @param mixed $value The value to check for.
     * @return static
     */
    public function orJsonContains(string $column, mixed $value): static
    {
        return $this->jsonContains($column, $value, 'OR');
    }

    /**
     * Or variant of jsonNotContains.
     *
     * @param string $column The JSON column path.
     * @param mixed $value The value to check for absence.
     * @return static
     */
    public function orJsonNotContains(string $column, mixed $value): static
    {
        return $this->jsonNotContains($column, $value, 'OR');
    }

    /**
     * Or variant of jsonHas.
     *
     * @param string $column The JSON column path.
     * @return static
     */
    public function orJsonHas(string $column): static
    {
        return $this->jsonHas($column, 'OR');
    }

    /**
     * Or variant of jsonMissing.
     *
     * @param string $column The JSON column path.
     * @return static
     */
    public function orJsonMissing(string $column): static
    {
        return $this->jsonMissing($column, 'OR');
    }

    // ------------------------------------------------------------------
    // JSON where* aliases (Laravel-compatible naming)
    // ------------------------------------------------------------------

    /**
     * Alias for jsonContains.
     *
     * @param string $column The JSON column path.
     * @param mixed $value The value to check for.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereJsonContains(string $column, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->jsonContains($column, $value, $logicalOperator);
    }

    /**
     * Check the length of a JSON array.
     *
     * @param string $column The JSON column path (e.g., 'meta->tags').
     * @param string $operator The comparison operator.
     * @param int $value The length to compare.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereJsonLength(string $column, string $operator, int $value, string $logicalOperator = 'AND'): static
    {
        [$col, $path] = $this->parseJsonPath($column);
        $lengthExpr = $this->getGrammar()->jsonLength($this->qualifyJsonColumn($col), $path);
        $sql = "$lengthExpr $operator ?";
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Or where JSON array length matches.
     *
     * @param string $column The JSON column path.
     * @param string $operator The comparison operator.
     * @param int $value The length to compare.
     * @return static
     */
    public function orWhereJsonLength(string $column, string $operator, int $value): static
    {
        return $this->whereJsonLength($column, $operator, $value, 'OR');
    }

    /**
     * Check JSON array length (alias for whereJsonLength).
     *
     * @param string $column The JSON column path (e.g., 'meta->tags').
     * @param string $operator The comparison operator.
     * @param int $value The length to compare.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function jsonLength(string $column, string $operator, int $value, string $logicalOperator = 'AND'): static
    {
        return $this->whereJsonLength($column, $operator, $value, $logicalOperator);
    }

    /**
     * Or JSON array length check (short alias).
     *
     * @param string $column The JSON column path (e.g., 'meta->tags').
     * @param string $operator The comparison operator.
     * @param int $value The length to compare.
     * @return static
     */
    public function orJsonLength(string $column, string $operator, int $value): static
    {
        return $this->whereJsonLength($column, $operator, $value, 'OR');
    }

    /**
     * Alias for jsonNotContains.
     *
     * @param string $column The JSON column path.
     * @param mixed $value The value to check for absence.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereJsonDoesntContain(string $column, mixed $value, string $logicalOperator = 'AND'): static
    {
        return $this->jsonNotContains($column, $value, $logicalOperator);
    }

    /**
     * Or where JSON does not contain a value.
     *
     * @param string $column The JSON column path.
     * @param mixed $value The value to check for absence.
     * @return static
     */
    public function orWhereJsonDoesntContain(string $column, mixed $value): static
    {
        return $this->jsonNotContains($column, $value, 'OR');
    }

    /**
     * Alias for jsonHas.
     *
     * @param string $column The JSON column path.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereJsonContainsKey(string $column, string $logicalOperator = 'AND'): static
    {
        return $this->jsonHas($column, $logicalOperator);
    }

    /**
     * Or where JSON key exists.
     *
     * @param string $column The JSON column path.
     * @return static
     */
    public function orWhereJsonContainsKey(string $column): static
    {
        return $this->jsonHas($column, 'OR');
    }

    /**
     * Alias for jsonMissing.
     *
     * @param string $column The JSON column path.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereJsonDoesntContainKey(string $column, string $logicalOperator = 'AND'): static
    {
        return $this->jsonMissing($column, $logicalOperator);
    }

    /**
     * Or where JSON key does not exist.
     *
     * @param string $column The JSON column path.
     * @return static
     */
    public function orWhereJsonDoesntContainKey(string $column): static
    {
        return $this->jsonMissing($column, 'OR');
    }

    /**
     * Or where JSON column matches.
     *
     * @param string $column The JSON column path.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @return static
     */
    public function orJson(string $column, string $operator, mixed $value): static
    {
        return $this->whereJson($column, $operator, $value, 'OR');
    }

    /**
     * Or where JSON path comparison (alias for orJson).
     *
     * @param string $column The JSON column path.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @return static
     */
    public function orWhereJson(string $column, string $operator, mixed $value): static
    {
        return $this->orJson($column, $operator, $value);
    }

    /**
     * Or where JSON contains a value.
     *
     * @param string $column The JSON column path.
     * @param mixed $value The value to check for.
     * @return static
     */
    public function orWhereJsonContains(string $column, mixed $value): static
    {
        return $this->jsonContains($column, $value, 'OR');
    }

    /**
     * Parse a JSON column path notation.
     *
     * Converts 'column->path.nested' to ['column', 'path.nested'].
     *
     * @param string $expression The JSON path expression.
     * @return array{0: string, 1: string} [column, path]
     */
    private function parseJsonPath(string $expression): array
    {
        $parts = explode('->', $expression, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Qualify a JSON column name for use in SQL.
     *
     * Handles dot notation (table.column) and simple names (deferred resolution).
     *
     * @param string $col The column name (may contain dot for table.column).
     * @return string The qualified column reference.
     */
    private function qualifyJsonColumn(string $col): string
    {
        if (str_contains($col, '.')) {
            $parts = explode('.', $col, 2);
            return $this->quote($parts[0]) . '.' . $this->quote($parts[1]);
        }

        return '{' . $col . '}';
    }

    /**
     * Filter by existence of a relation (has related records).
     *
     * Generates: WHERE EXISTS (SELECT 1 FROM related WHERE related.fk = parent.pk)
     *
     * @param string $relationName The relation method name on the model.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function has(string $relationName, string $logicalOperator = 'AND'): static
    {
        return $this->whereHas($relationName, null, $logicalOperator);
    }

    /**
     * Filter by non-existence of a relation (has no related records).
     *
     * @param string $relationName The relation method name on the model.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function doesntHave(string $relationName, string $logicalOperator = 'AND'): static
    {
        return $this->whereDoesntHave($relationName, null, $logicalOperator);
    }

    /**
     * Filter by existence of a relation with additional conditions.
     *
     * Generates: WHERE EXISTS (SELECT 1 FROM related WHERE related.fk = parent.pk AND ...)
     *
     * @param string $relationName The relation method name on the model.
     * @param callable|null $callback Optional callback to add conditions to the sub-query.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereHas(string $relationName, ?callable $callback = null, string $logicalOperator = 'AND'): static
    {
        $subQuery = $this->buildRelationExistsQuery($relationName, $callback);
        if ($subQuery === null) {
            return $this;
        }

        $sql = "EXISTS ($subQuery)";

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    /**
     * Filter by non-existence of a relation with additional conditions.
     *
     * @param string $relationName The relation method name on the model.
     * @param callable|null $callback Optional callback to add conditions to the sub-query.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereDoesntHave(string $relationName, ?callable $callback = null, string $logicalOperator = 'AND'): static
    {
        $subQuery = $this->buildRelationExistsQuery($relationName, $callback);
        if ($subQuery === null) {
            return $this;
        }

        $sql = "NOT EXISTS ($subQuery)";

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
        return $this;
    }

    /**
     * Build the EXISTS sub-query for a relation.
     *
     * @param string $relationName The relation method name.
     * @param callable|null $callback Optional callback for additional conditions.
     * @return string|null The sub-query SQL, or null if relation doesn't exist.
     */
    private function buildRelationExistsQuery(string $relationName, ?callable $callback): ?string
    {
        if (!$this->modelClass || !method_exists($this->modelClass, $relationName)) {
            return null;
        }

        /** @var Model $model */
        $model = new $this->modelClass();
        $relation = $model->{$relationName}();

        if (!$relation instanceof \Simsoft\DB\Relation) {
            return null;
        }

        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();
        $relatedClass = $relation->getRelatedClass();

        // Build: SELECT 1 FROM related_table WHERE related.fk = parent.local_key
        /** @var Model $relatedModel */
        $relatedModel = new $relatedClass();
        $relatedTable = $relatedModel->getTable();
        $parentTable = $model->getTable();

        $quotedTable = $this->quote($relatedTable);
        $fk = $this->quote($foreignKey);
        $parentRef = $this->quote($parentTable) . '.' . $this->quote($localKey);

        $subQuery = new ActiveQuery();
        $subQuery->selectRaw('1');
        $subQuery->from($relatedTable);
        $subQuery->whereRaw("$quotedTable.$fk = $parentRef");

        // Apply user callback for additional conditions
        if ($callback !== null) {
            $callback($subQuery);
        }

        // Build the SQL and collect binds
        $sql = $subQuery->getSQL();
        $binds = $subQuery->getBinds();
        if ($binds !== null) {
            $this->appendBinds($binds);
        }

        return $sql;
    }

    /**
     * Add a raw ORDER BY expression.
     *
     * @param string $expression The raw SQL expression.
     * @return static
     */
    public function orderByRaw(string $expression): static
    {
        $this->orderBys[] = $expression;
        return $this;
    }

    /**
     * Add a raw SELECT expression.
     *
     * @param string $expression The raw SQL expression.
     * @return static
     */
    public function selectRaw(string $expression): static
    {
        $this->selects[] = $expression;
        return $this;
    }

    /**
     * Or compare two columns.
     *
     * @param string $first The first column.
     * @param string $operator The comparison operator.
     * @param string $second The second column.
     * @return static
     */
    public function orWhereColumn(string $first, string $operator, string $second): static
    {
        return $this->whereColumn($first, $operator, $second, 'OR');
    }

    /**
     * Alias for like() — Where LIKE with case-sensitivity control.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return static
     */
    public function whereLike(string $attribute, string $value, bool $caseSensitive = false, string $logicalOperator = 'AND'): static
    {
        return $this->like($attribute, $value, true, true, $logicalOperator, $caseSensitive);
    }

    /**
     * Alias for orLike() — Or where LIKE with case-sensitivity control.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @return static
     */
    public function orWhereLike(string $attribute, string $value, bool $caseSensitive = false): static
    {
        return $this->like($attribute, $value, true, true, 'OR', $caseSensitive);
    }

    /**
     * Alias for notLike() — Where NOT LIKE with case-sensitivity control.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return static
     */
    public function whereNotLike(string $attribute, string $value, bool $caseSensitive = false, string $logicalOperator = 'AND'): static
    {
        return $this->like($attribute, $value, false, true, $logicalOperator, $caseSensitive);
    }

    /**
     * Alias for orNotLike() — Or where NOT LIKE with case-sensitivity control.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @return static
     */
    public function orWhereNotLike(string $attribute, string $value, bool $caseSensitive = false): static
    {
        return $this->like($attribute, $value, false, true, 'OR', $caseSensitive);
    }
}
