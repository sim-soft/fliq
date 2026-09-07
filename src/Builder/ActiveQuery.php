<?php

namespace Simsoft\DB\Builder;

use Closure;
use InvalidArgumentException;
use Simsoft\DB\Builder\Clauses\Clause;
use Simsoft\DB\Builder\Conditions\BetweenDateCondition;
use Simsoft\DB\Builder\Conditions\Condition;
use Simsoft\DB\Builder\Conditions\ExistsCondition;
use Simsoft\DB\Builder\Conditions\InCondition;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Interfaces\Deletable;
use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Interfaces\Updatable;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;
use Simsoft\DB\Traits\Aggregation;
use Simsoft\DB\Traits\Binds;
use Simsoft\DB\Traits\Execute;
use Simsoft\DB\Traits\Groupable;
use Simsoft\DB\Traits\Joinable;
use Simsoft\DB\Traits\Likeable;
use Simsoft\DB\Traits\Fetchable;
use Simsoft\DB\Traits\PlaceHolder;
use Simsoft\DB\Traits\Qualifier;
use Simsoft\DB\Traits\SectionBinds;
use Simsoft\DB\Traits\TemporaryAlias;

/**
 * Class ActiveQuery.
 *
 * Fluent SQL query builder for SELECT statements with condition chaining,
 * joins, aggregation, and result fetching.
 */
class ActiveQuery implements Executable, Updatable, Deletable
{
    use Qualifier, Execute, PlaceHolder, Aggregation, Fetchable, TemporaryAlias, Likeable, Groupable, Joinable;

    // SectionBinds supplies getBinds() and clearBinds(), which cover every
    // section; the Binds versions cover the WHERE list alone and are reached
    // through these aliases.
    use SectionBinds, Binds {
        SectionBinds::getBinds insteadof Binds;
        SectionBinds::clearBinds insteadof Binds;
        Binds::getBinds as private whereSectionBinds;
        Binds::clearBinds as private clearWhereSectionBinds;
    }

    // The FROM and JOIN sources are kept unquoted and only rendered in
    // getSQL(). Quoting them at from()/join() time froze whichever grammar was
    // current into the stored string, so a connection named afterwards — as
    // DB::table('user', 'pg') does, and as the fluent order invites everywhere
    // else — produced a statement quoted for one engine and run against
    // another. It also left getTable() returning "`user` `u`", which every
    // caller then tried to unpick with trim($t, '`"'); that cannot remove the
    // interior backticks, so count(), sum() and updateAll() on an aliased query
    // asked the server for a table named "user` `u".

    /** @var null|string The FROM table name, unquoted and without its alias. */
    protected ?string $table = null;

    /** @var null|string The FROM sub-query SQL, when the query selects from one. */
    protected ?string $fromSubQuery = null;

    /** @var bool Distinct is enabled */
    protected bool $distinct = false;

    /** @var array<int, string> The select statement. */
    protected array $selects = [];

    /** @var array<int, string> The WHERE statements. */
    protected array $conditions = [];

    /** @var array<int, string> The GROUP BY statements. */
    protected array $groupBys = [];

    /** @var array<int, string> The query having, with logical operators interleaved. */
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

    /** @var array<string> Relations to an eager load */
    protected array $eagerLoad = [];

    /** @var array<string, callable> Eager load constraints keyed by relation name */
    protected array $eagerLoadConstraints = [];

    /** @var int Cache TTL in seconds. 0 means no caching. */
    protected int $cacheTtl = 0;

    /** @var string|null Row-level lock type (e.g., 'update', 'share', 'noWait', 'skipLocked'). */
    protected ?string $lockType = null;

    /**
     * Constructor.
     *
     * @param string|Model|null $modelClass The model class
     * @param bool $withScopes Whether to apply model scopes (soft delete, global).
     */
    public function __construct(protected string|null|Model $modelClass = null, bool $withScopes = true)
    {
        if ($this->modelClass instanceof Model) {
            $this->from($this->modelClass);
            if ($withScopes) {
                $this->applyModelScopes($this->modelClass);
            }
            return;
        }

        if (is_string($this->modelClass)) {
            /** @var Model $instance */
            $instance = new $this->modelClass();
            $this->from($instance);
            if ($withScopes) {
                $this->applyModelScopes($instance);
            }
        }
    }

    /**
     * Apply soft delete scope and global scopes from the model.
     *
     * @param Model $model The model instance.
     * @return void
     */
    private function applyModelScopes(Model $model): void
    {
        if (method_exists($model, 'softDeleteScope')) {
            $model->softDeleteScope($this);
        }

        $model::applyGlobalScopes($this);
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
     * Get FROM table name, unquoted and without its alias.
     *
     * Null when the query selects from a sub-query, which has no table name to
     * report — a caller needing one for its own FROM clause cannot use this
     * query's source and should say so rather than build a table reference out
     * of a SELECT statement.
     *
     * @return string|null
     */
    public function getTable(): ?string
    {
        return $this->table;
    }

    /**
     * Get the FROM sub-query SQL, if this query selects from one.
     *
     * @return string|null
     */
    public function getFromSubQuery(): ?string
    {
        return $this->fromSubQuery;
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
        if ($this->table !== $query->getTable() || $this->fromSubQuery !== $query->getFromSubQuery()) {
            throw new QueryException(
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

        $this->mergeSectionBinds($query);

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
     * @throws InvalidArgumentException If the logical operator is not AND or OR.
     */
    private function mergeConditions(array $conditions, string $logicalOperator): void
    {
        if (empty($conditions)) {
            return;
        }

        $logicalOperator = $this->validateLogicalOperator($logicalOperator);

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
     * @throws InvalidArgumentException If the sub-query has no usable alias.
     */
    public function from(string|array|Model $table): static
    {
        if (is_array($table)) {
            // The key names the derived table, which MySQL and PostgreSQL both
            // require. Written as a list — from(['SELECT ...']) — the key is the
            // integer 0, which quoted to the identifier `0`; given no entry at
            // all it was the empty string, which quoted to ``. Both cases built
            // a statement naming a table the caller never wrote, so say which
            // argument is at fault rather than reporting an odd identifier or
            // leaving the server to complain.
            $alias = array_key_first($table);
            if (!is_string($alias) || $alias === '') {
                throw new InvalidArgumentException(
                    'A sub-query used as a table must be given an alias as the array key: '
                    . "from(['t' => \$subQuery])."
                );
            }

            $subQuery = current($table);
            $this->table = null;
            $this->fromSubQuery = (string)$subQuery;
            $this->alias($alias);
            if ($subQuery instanceof ActiveQuery || $subQuery instanceof Raw) {
                $this->appendSectionBinds($this->fromBinds, $subQuery->getBinds());
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

        self::validateIdentifier($table);
        if ($table !== $alias) {
            self::validateIdentifier($alias);
        }

        $this->fromSubQuery = null;
        $this->table = $table;

        // Columns are qualified against the alias, and an unaliased table is
        // its own qualifier. A schema-qualified name keeps only its last part,
        // since `schema`.`table`.`column` is not a valid reference.
        $parts = explode('.', $table);
        $this->alias($table === $alias ? end($parts) : $alias);

        return $this;
    }

    /**
     * Render the FROM clause for the current grammar.
     *
     * @return string
     */
    private function getFromSQL(): string
    {
        $alias = $this->getAlias();

        if ($this->fromSubQuery !== null) {
            // from() will not accept a sub-query without an alias, but alias()
            // is public and alias(null) after the fact left this quoting the
            // empty string. `` is an identifier MySQL happens to accept and
            // PostgreSQL rejects outright, so the same builder produced a
            // statement that ran on one engine and would not parse on the
            // other — and on MySQL the derived table then had no name to
            // reference it by.
            if ($alias === null) {
                throw new InvalidArgumentException(
                    'A sub-query used as a table must keep an alias to be referred to by.'
                );
            }

            return 'FROM (' . $this->fromSubQuery . ') ' . $this->quote($alias);
        }

        if ($this->table === null) {
            return '';
        }

        $source = $this->quoteTableName($this->table);

        // The table's own name is its default alias and is not repeated.
        $parts = explode('.', $this->table);
        if ($alias === null || $alias === end($parts)) {
            return "FROM $source";
        }

        return "FROM $source " . $this->quote($alias);
    }

    /**
     * Select statement.
     *
     * @param string|Raw|Clause ...$attributes
     * @return static
     */
    public function select(string|Raw|Clause ...$attributes): static
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof Clause) {
                $attribute->alias($this->getAlias());
                $attribute->setPlaceHolder($this->getPlaceHolder());
                $sql = (string)$attribute;

                // A clause naming no columns builds an empty string, which was
                // still added to the list and emitted as `SELECT  FROM` — or
                // `SELECT a, , b` beside others. An empty entry contributes no
                // column, so it is dropped; a query left with none falls back
                // to `*` as it already does when select() is never called.
                if ($sql === '') {
                    continue;
                }

                $this->selects[] = $sql;
                $this->appendSectionBinds($this->selectBinds, $attribute->getBinds());
                continue;
            }

            if ($attribute instanceof Raw) {
                $this->selects[] = (string)$attribute;

                // A Raw select expression may carry placeholders of its own —
                // `IF(score > ?, 1, 0) AS grade`. Its binds were dropped on the
                // floor, so the statement was left one value short and the
                // driver refused to execute it at all.
                $this->appendSectionBinds($this->selectBinds, $attribute->getBinds());
                continue;
            }

            $this->selects[] = $this->queryAttribute($attribute);
        }
        return $this;
    }

    /**
     * Select distinct statement
     *
     * @param string|Raw|Clause ...$attributes
     * @return static
     */
    public function selectDistinct(string|Raw|Clause ...$attributes): static
    {
        $this->select(...$attributes);
        return $this->distinct();
    }

    /**
     * Construct the query conditions.
     *
     * Two array forms are accepted: a list of [attribute, operator, value]
     * triplets, and a map of attribute => value where an array value means IN.
     * Only the list form was declared, so the map form — which the method has
     * always built, and which the array tests cover — did not type-check.
     *
     * @param string|array<int, array<int, mixed>>|array<string, mixed>|callable|Raw|Clause $attribute the attribute
     * @param mixed $operator the comparison operator or the attribute value
     * @param mixed $value the value for the attribute
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return static
     * @throws InvalidArgumentException If the operator is not on the whitelist,
     *     or its value does not match the shape the operator needs.
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

        // Normalize operator/value and handle NULL comparisons for string attributes
        if (is_string($attribute)) {
            $resolved = $this->resolveNullCondition($attribute, $operator, $value, $logicalOperator);
            if ($resolved !== null) {
                return $resolved;
            }

            $resolved = $this->resolveShapedOperator($attribute, $operator, $value, $logicalOperator);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        [$operator, $value] = $this->normaliseOperatorValue($operator, $value);
        $operator = $this->validateOperator((string)$operator);
        $this->assertNullComparison($operator, $value);

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
     * Normalize operator/value when called as where('col', 'value') shorthand.
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
        // Only a null value can be a NULL check. A non-string operator is the
        // where('col', $value) shorthand carrying its value in the operator
        // slot, which the caller normalises afterwards.
        if ($value !== null || !($operator === null || is_string($operator))) {
            return null;
        }

        // IS and IS NOT are on the documented operator whitelist, but nothing
        // handled them: the shorthand saw a null value and took the operator
        // as the value, so the query became `col = 'IS'` and matched nothing
        // at all — wrong results with no error to notice. `<>` had the same
        // fate, though `!=` was already handled.
        return match ($operator === null ? '=' : strtoupper(trim($operator))) {
            '=', 'IS' => $this->isNull($attribute, $logicalOperator),
            '!=', '<>', 'IS NOT' => $this->notNull($attribute, $logicalOperator),
            default => null,
        };
    }

    /**
     * Route operators whose right-hand side is not a single placeholder.
     *
     * @param string $attribute The attribute name.
     * @param mixed $operator The operator.
     * @param mixed $value The value.
     * @param string $logicalOperator The logical operator.
     * @return static|null Null when normal processing should continue.
     * @throws InvalidArgumentException If a range operator is not given exactly two bounds.
     */
    private function resolveShapedOperator(
        string $attribute,
        mixed  $operator,
        mixed  $value,
        string $logicalOperator
    ): ?static
    {
        if (!is_string($operator) || $value === null) {
            return null;
        }

        // IN, NOT IN, BETWEEN and NOT BETWEEN are on the documented operator
        // whitelist, so where('id', 'IN', [1, 2]) looks supported. Both paths
        // below emit exactly one placeholder, so it built `id IN ?` and the
        // server rejected the statement. These are the same conditions in()
        // and between() already build correctly, so they are routed there.
        return match (strtoupper(trim($operator))) {
            'IN' => $this->in($attribute, $this->setValues($attribute, 'IN', $value), $logicalOperator),
            'NOT IN' => $this->notIn($attribute, $this->setValues($attribute, 'NOT IN', $value), $logicalOperator),
            'BETWEEN' => $this->betweenBounds($attribute, $value, true, $logicalOperator),
            'NOT BETWEEN' => $this->betweenBounds($attribute, $value, false, $logicalOperator),
            default => null,
        };
    }

    /**
     * Normalise the right-hand side of a set operator.
     *
     * @param string $attribute The attribute being matched, for the error message.
     * @param string $operator The set operator.
     * @param mixed $value The values to match against.
     * @return array<int, mixed>|ActiveQuery|Raw The values as a list.
     * @throws InvalidArgumentException If the value cannot form a set.
     */
    private function setValues(string $attribute, string $operator, mixed $value): array|ActiveQuery|Raw
    {
        // A set only uses the values, so keys are discarded; keeping them
        // would leave a map here where the placeholders are positional.
        if (is_array($value)) {
            return array_values($value);
        }

        if ($value instanceof ActiveQuery || $value instanceof Raw) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            '%s on "%s" needs an array, subquery or Raw expression; got %s.',
            $operator,
            $attribute,
            get_debug_type($value)
        ));
    }

    /**
     * Apply a range condition from a two-element bounds array.
     *
     * @param string $attribute The attribute name.
     * @param mixed $value The bounds.
     * @param bool $is False to negate the range.
     * @param string $logicalOperator The logical operator.
     * @return static
     * @throws InvalidArgumentException If the bounds are not exactly two values.
     */
    private function betweenBounds(string $attribute, mixed $value, bool $is, string $logicalOperator): static
    {
        $keyword = $is ? 'BETWEEN' : 'NOT BETWEEN';

        if (!is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException(sprintf(
                '%s on "%s" needs exactly two bounds; got %s.',
                $keyword,
                $attribute,
                is_array($value) ? count($value) . ' values' : get_debug_type($value)
            ));
        }

        [$start, $end] = array_values($value);

        return $this->between($attribute, $start, $end, $is, $logicalOperator);
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
        $this->pushCondition($sql, $logicalOperator);
        $this->appendBinds($bindValue);
    }

    /**
     * Append a condition fragment, joining it to any preceding condition.
     *
     * Every condition method needs the same three steps: validate the logical
     * operator, emit it unless this is the first condition in the current
     * group, then push the fragment. Nine methods each inlined that sequence
     * and none of them validated, so this exists to give them one
     * implementation and one place where the operator is checked.
     *
     * @param string $sql The condition SQL fragment.
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @return void
     * @throws InvalidArgumentException If the logical operator is not AND or OR.
     */
    private function pushCondition(string $sql, string $logicalOperator): void
    {
        $logicalOperator = $this->validateLogicalOperator($logicalOperator);

        if ($this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $logicalOperator;
        }

        $this->conditions[] = $sql;
    }

    /**
     * Or condition.
     *
     * Accepts everything where() accepts. The signature used to omit the array
     * and Clause forms and narrow the operator to ?string, so orWhere([...])
     * raised a TypeError and orWhere($clause) stringified the clause into the
     * attribute slot, building SQL that could not be executed.
     *
     * @param string|array<int, array<int, mixed>>|array<string, mixed>|callable|Raw|Clause $attribute the attribute
     * @param mixed $operator the comparison operator or the attribute value
     * @param mixed $value the value for the attribute
     * @return static
     * @throws InvalidArgumentException If the operator is not on the whitelist,
     *     or its value does not match the shape the operator needs.
     */
    public function orWhere(
        string|array|callable|Raw|Clause $attribute,
        mixed $operator = '=',
        mixed $value = null
    ): static
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
        $this->pushCondition($sql, $logicalOperator);
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
        $this->pushCondition($sql, $logicalOperator);
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
     * Where all the given columns match the condition (AND logic).
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
     * Or where all the given columns match the condition.
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
     * @param string $joiner Inner logical operator ('AND' or 'OR').
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
        $operator = $this->validateOperator($operator);
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = $this->queryAttribute($column) . " $operator ?";
            $this->appendBinds($value);
        }

        $group = '(' . implode(" $joiner ", $parts) . ')';
        $sql = $negate ? "NOT $group" : $group;
        $this->pushCondition($sql, $logicalOperator);
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
     * $this->orderBy(['attribute1', 'attribute2'], 'DESC'); // both DESC
     *
     * A list entry takes the $direction argument; a keyed entry takes its own
     * value. The two may be mixed in one array.
     *
     * @param string|array<int|string, string> $attribute the attribute
     * @param string $direction the order direction for the attribute
     * @return static
     */
    public function orderBy(string|array $attribute, string $direction = 'ASC'): static
    {
        if (is_array($attribute)) {
            foreach ($attribute as $col => $dir) {
                // A list entry arrives as position => name, so the name is the
                // value and the direction is the argument. Reading the key as
                // the name put the position into the SQL — ['a','b'] ordered by
                // columns "0" and "1", which the server rejects as unknown —
                // and reading the value as the direction meant orderByDesc()
                // silently sorted ASC.
                $this->addOrderBy(
                    is_int($col) ? $dir : $col,
                    is_int($col) ? $direction : $dir
                );
            }
            return $this;
        }

        $this->addOrderBy($attribute, $direction);
        return $this;
    }

    /**
     * Append a single ORDER BY term.
     *
     * Shared by both shapes of orderBy() so the RAND() special case and the
     * direction whitelist cannot apply to one and not the other.
     *
     * @param string $attribute The attribute name.
     * @param string $direction The requested sort direction.
     * @return void
     */
    private function addOrderBy(string $attribute, string $direction): void
    {
        if (strtoupper($attribute) === 'RAND()') {
            $this->orderBys[] = 'RAND()';
            return;
        }

        $this->orderBys[] = $this->queryAttribute($attribute) . ' ' . $this->normaliseDirection($direction);
    }

    /**
     * Normalize a sort direction to a safe keyword.
     *
     * Only ASC and DESC are permitted; anything else falls back to ASC. This
     * prevents arbitrary SQL from reaching the ORDER BY clause when the
     * direction originates from user input (e.g., a `?sort=` parameter).
     *
     * @param string $direction The requested sort direction.
     * @return string Either 'ASC' or 'DESC'.
     */
    private function normaliseDirection(string $direction): string
    {
        return strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * Order by desc.
     *
     * Accepts the same shapes as orderBy(): a name, a list of names, or a map
     * of name => direction. A list takes DESC; a keyed entry keeps its own.
     *
     * @param string|array<int|string, string> $attribute The attribute name.
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
     * @throws InvalidArgumentException If the limit or offset is negative.
     */
    public function limit(int $max, ?int $offset = null): static
    {
        if ($max < 0) {
            throw new InvalidArgumentException("Limit must not be negative, $max given.");
        }

        $this->limit = $max;
        if ($offset) {
            $this->offset = $this->validateOffset($offset);
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
     * @throws InvalidArgumentException If the offset is negative.
     */
    public function offset(int $value): static
    {
        $this->offset = $this->validateOffset($value);

        return $this;
    }

    /**
     * Validate an offset value.
     *
     * @param int $offset The offset to validate.
     * @return int The validated offset.
     * @throws InvalidArgumentException If the offset is negative.
     */
    private function validateOffset(int $offset): int
    {
        if ($offset < 0) {
            throw new InvalidArgumentException("Offset must not be negative, $offset given.");
        }

        return $offset;
    }

    /**
     * Limit per page statement.
     *
     * @param int $currentPage the current page (1-based)
     * @param int $maxPerPage the max records returned per current page
     * @return static
     * @throws InvalidArgumentException If the page number is below 1.
     */
    public function page(int $currentPage, int $maxPerPage = 50): static
    {
        if ($currentPage < 1) {
            throw new InvalidArgumentException("Page must be 1 or greater, $currentPage given.");
        }

        return $this->limit($maxPerPage, --$currentPage * $maxPerPage);
    }

    // ------------------------------------------------------------------
    // ROW-LEVEL LOCKING
    // ------------------------------------------------------------------

    /**
     * Add a FOR UPDATE lock to the query.
     *
     * Acquires an exclusive row-level lock. Other transactions cannot modify
     * the selected rows until this transaction completes.
     *
     * @return static
     */
    public function forUpdate(): static
    {
        $this->lockType = 'update';
        return $this;
    }

    /**
     * Add a FOR SHARE lock to the query.
     *
     * Acquires a shared row-level lock. Other transactions can read but
     * cannot modify the selected rows until this transaction completes.
     *
     * @return static
     */
    public function forShare(): static
    {
        $this->lockType = 'share';
        return $this;
    }

    /**
     * Add a FOR UPDATE NOWAIT lock to the query.
     *
     * Like forUpdate(), but returns an error immediately if the row is
     * already locked instead of waiting for the lock to be released.
     *
     * @return static
     */
    public function forUpdateNoWait(): static
    {
        $this->lockType = 'noWait';
        return $this;
    }

    /**
     * Add a FOR UPDATE SKIP LOCKED to the query.
     *
     * Like forUpdate(), but skips rows that are already locked by other
     * transactions instead of waiting. Useful for job queue patterns.
     *
     * @return static
     */
    public function forUpdateSkipLocked(): static
    {
        $this->lockType = 'skipLocked';
        return $this;
    }

    /**
     * Get the lock clause SQL.
     *
     * @return string|null
     */
    public function getLockSQL(): ?string
    {
        if ($this->lockType === null) {
            return null;
        }

        $lockClause = $this->getGrammar()->lockSQL($this->lockType);
        return $lockClause !== '' ? $lockClause : null;
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
        $this->appendSectionBinds($this->unionBinds, $query->getBinds());
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
        $this->appendSectionBinds($this->unionBinds, $query->getBinds());
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
        $this->appendSectionBinds($this->unionBinds, $query->getBinds());
        return $this;
    }

    /**
     * Append to existing query conditions.
     *
     * @param string|Clause $query the SQL query statement
     * @param null|string $operator The logical operator. Either: "AND" or "OR".
     * @return static
     * @throws InvalidArgumentException If the logical operator is not AND or OR.
     */
    protected function onCondition(string|Clause $query, ?string $operator = null): static
    {
        // Null means "this is the first condition, do not join it to anything",
        // which is distinct from an unrecognised operator and stays permitted.
        if ($operator !== null) {
            $operator = $this->validateLogicalOperator($operator);
        }

        $binds = null;

        if ($query instanceof Clause) {
            $query->setPlaceHolder($this->getPlaceHolder());
            // Eagerly build SQL and collect binds now. The binds are populated
            // while the SQL is built, so they can only be read afterwards.
            $sql = (string)$query;
            $binds = $query->getBinds();
            $query = $sql;
        }

        // A clause with nothing to say builds an empty string — a LIKE with no
        // patterns, or an array condition with no fields. The operator was
        // appended before the clause was built, so the empty result left a
        // dangling `AND` behind it and the query died with a syntax error
        // pointing nowhere near the call. Testing the built SQL here covers
        // every clause rather than each one separately.
        if ($query === '') {
            return $this;
        }

        if ($operator && $this->conditions && end($this->conditions) !== '(') {
            $this->conditions[] = $operator;
        }

        $this->conditions[] = $query;

        if ($binds) {
            $this->appendBinds($binds);
        }

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
        $segments = array_filter([$this->buildSelectClause(), $this->getFromSQL()]);

        $clauses = [
            $this->getJoinSQL(),
            $this->getWhereSQL(),
            $this->getGroupSQL(),
            $this->getHavingSQL(),
            $this->getOrderSQL(),
            $this->getLimitSQL(),
            $this->getLockSQL(),
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
        // The entries were joined with a comma, which HAVING does not accept —
        // unlike GROUP BY, it takes one boolean expression. So a second
        // having() call produced a syntax error and the query never ran. The
        // logical operators are interleaved into the list as conditions are
        // added, exactly as the WHERE list does, and joined with spaces here.
        return empty($this->having) ? null : 'HAVING ' . implode(' ', $this->having);
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
     * Specify relations to an eager load.
     *
     * Supports multiple formats:
     *   ->with('posts', 'profile') // simple
     *   ->with('posts.comments') // nested
     *   ->with(['posts' => fn($q) => $q->where(...)]) // constrained
     *
     * @param string|array<string, callable> ...$relations
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
     * Supports shorthand: whereDate('col', '2024-01-01') defaults the operator to '='.
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
        $operator = $this->validateOperator($operator);
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
        $operator = $this->validateOperator((string)$operator);
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
        $operator = $this->validateOperator((string)$operator);
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
        $operator = $this->validateOperator($operator);
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
        $operator = $this->validateOperator($operator);
        $sql = $this->queryAttribute($first) . " $operator " . $this->queryAttribute($second);
        $this->pushCondition($sql, $logicalOperator);
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
        $operator = $this->validateOperator($operator);
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
     * Or where a JSON column path equals a value.
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
        $sql = $this->getGrammar()->jsonKeyExists($this->qualifyJsonColumn($col), $path);
        $this->pushCondition($sql, $logicalOperator);
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
        $sql = 'NOT ' . $this->getGrammar()->jsonKeyExists($this->qualifyJsonColumn($col), $path);
        $this->pushCondition($sql, $logicalOperator);
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
        $operator = $this->validateOperator($operator);
        $lengthExpr = $this->getGrammar()->jsonLength($this->qualifyJsonColumn($col), $path);
        $sql = "$lengthExpr $operator ?";
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Or where the JSON array length matches.
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
     * Or where the JSON key exists.
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
     * Or where the JSON key does not exist.
     *
     * @param string $column The JSON column path.
     * @return static
     */
    public function orWhereJsonDoesntContainKey(string $column): static
    {
        return $this->jsonMissing($column, 'OR');
    }

    /**
     * Or where the JSON column matches.
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

    // ------------------------------------------------------------------
    // FULLTEXT SEARCH
    // ------------------------------------------------------------------

    /**
     * Add a full-text search condition.
     *
     * PostgreSQL: Uses to_tsvector/to_tsquery with configurable mode.
     * MySQL: Uses MATCH...AGAINST in BOOLEAN MODE via Grammar.
     *
     * @param array<int, string>|string $columns Column(s) to search.
     * @param string $term The search term.
     * @param string $mode The search mode: 'plain', 'phrase', or 'websearch'.
     *     Use 'websearch' for boolean operators (+, -, "); any other name is
     *     refused rather than answered in plain mode.
     * @param string $language The text search config/language. Default: 'english'.
     * @param string $logicalOperator The logical operator.
     * @return static
     * @throws InvalidArgumentException If the mode is not one of the three supported.
     */
    public function whereFulltext(
        array|string $columns,
        string       $term,
        string       $mode = 'plain',
        string       $language = 'english',
        string       $logicalOperator = 'AND'
    ): static
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $qualifiedCols = array_map(fn($col) => $this->queryAttribute($col), $cols);
        $sql = $this->getGrammar()->fulltextSearch($qualifiedCols, $mode, $language);
        $this->addConditionSQL($sql, $term, $logicalOperator);
        return $this;
    }

    /**
     * Or variant of whereFulltext.
     *
     * @param array<int, string>|string $columns Column(s) to search.
     * @param string $term The search term.
     * @param string $mode The search mode: 'plain', 'phrase', or 'websearch'.
     * @param string $language The text search config/language. Default: 'english'.
     * @return static
     * @throws InvalidArgumentException If the mode is not one of the three supported.
     */
    public function orWhereFulltext(
        array|string $columns,
        string       $term,
        string       $mode = 'plain',
        string       $language = 'english'
    ): static
    {
        return $this->whereFulltext($columns, $term, $mode, $language, 'OR');
    }

    // ------------------------------------------------------------------
    // ARRAY COLUMN QUERIES (PostgreSQL)
    // ------------------------------------------------------------------

    /**
     * Check if an array column contains a value.
     *
     * PostgreSQL: column @> ARRAY[?]::type[]
     * MySQL fallback: JSON_CONTAINS(column, '$')
     *
     * @param string $column The array column name.
     * @param mixed $value The value to check for.
     * @param string $type The PG array element type (text, int, varchar, etc.).
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function arrayContains(string $column, mixed $value, string $type = 'text', string $logicalOperator = 'AND'): static
    {
        $col = $this->queryAttribute($column);
        $sql = $this->getGrammar()->arrayContains($col, $type);
        $this->addConditionSQL($sql, $value, $logicalOperator);
        return $this;
    }

    /**
     * Or variant of arrayContains.
     *
     * @param string $column The array column name.
     * @param mixed $value The value to check for.
     * @param string $type The PG array element type.
     * @return static
     */
    public function orArrayContains(string $column, mixed $value, string $type = 'text'): static
    {
        return $this->arrayContains($column, $value, $type, 'OR');
    }

    /**
     * Check if an array column overlaps with given values (has any match).
     *
     * PostgreSQL: column && ARRAY[?,?]::type[]
     * MySQL fallback: JSON_OVERLAPS(column, JSON_ARRAY(?,?))
     *
     * @param string $column The array column name.
     * @param array<int, mixed> $values The values to check for overlap.
     * @param string $type The PG array element type (text, int, varchar, etc.).
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function arrayOverlaps(string $column, array $values, string $type = 'text', string $logicalOperator = 'AND'): static
    {
        $col = $this->queryAttribute($column);
        $sql = $this->getGrammar()->arrayOverlaps($col, count($values), $type);
        $this->addConditionSQL($sql, $values, $logicalOperator);
        return $this;
    }

    /**
     * Or variant of arrayOverlaps.
     *
     * @param string $column The array column name.
     * @param array<int, mixed> $values The values to check for overlap.
     * @param string $type The PG array element type.
     * @return static
     */
    public function orArrayOverlaps(string $column, array $values, string $type = 'text'): static
    {
        return $this->arrayOverlaps($column, $values, $type, 'OR');
    }

    /**
     * Alias for arrayContains.
     *
     * @param string $column The array column name.
     * @param mixed $value The value to check for.
     * @param string $type The PG array element type.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereArrayContains(string $column, mixed $value, string $type = 'text', string $logicalOperator = 'AND'): static
    {
        return $this->arrayContains($column, $value, $type, $logicalOperator);
    }

    /**
     * Alias for orArrayContains.
     *
     * @param string $column The array column name.
     * @param mixed $value The value to check for.
     * @param string $type The PG array element type.
     * @return static
     */
    public function orWhereArrayContains(string $column, mixed $value, string $type = 'text'): static
    {
        return $this->arrayContains($column, $value, $type, 'OR');
    }

    /**
     * Alias for arrayOverlaps.
     *
     * @param string $column The array column name.
     * @param array<int, mixed> $values The values to check for overlap.
     * @param string $type The PG array element type.
     * @param string $logicalOperator The logical operator.
     * @return static
     */
    public function whereArrayOverlaps(string $column, array $values, string $type = 'text', string $logicalOperator = 'AND'): static
    {
        return $this->arrayOverlaps($column, $values, $type, $logicalOperator);
    }

    /**
     * Alias for orArrayOverlaps.
     *
     * @param string $column The array column name.
     * @param array<int, mixed> $values The values to check for overlap.
     * @param string $type The PG array element type.
     * @return static
     */
    public function orWhereArrayOverlaps(string $column, array $values, string $type = 'text'): static
    {
        return $this->arrayOverlaps($column, $values, $type, 'OR');
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
     * @param string $col The column name (may contain a dot for 'table.column').
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
     * Filter by the existence of a relation (has related records).
     *
     * Generates: WHERE EXISTS (SELECT 1 FROM related WHERE related.fk = parent.pk)
     *
     * @param string $relationName The relation method name on the model.
     * @param string $logicalOperator The logical operator.
     * @return static
     * @throws InvalidArgumentException If the name is not a relation on the model.
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
     * @throws InvalidArgumentException If the name is not a relation on the model.
     */
    public function doesntHave(string $relationName, string $logicalOperator = 'AND'): static
    {
        return $this->whereDoesntHave($relationName, null, $logicalOperator);
    }

    /**
     * Filter by the existence of a relation with additional conditions.
     *
     * Generates: WHERE EXISTS (SELECT 1 FROM related WHERE related.fk = parent.pk AND ...)
     *
     * @param string $relationName The relation method name on the model.
     * @param callable|null $callback Optional callback to add conditions to the sub-query.
     * @param string $logicalOperator The logical operator.
     * @return static
     * @throws InvalidArgumentException If the name is not a relation on the model.
     */
    public function whereHas(string $relationName, ?callable $callback = null, string $logicalOperator = 'AND'): static
    {
        $subQuery = $this->buildRelationExistsQuery($relationName, $callback);

        $this->pushCondition("EXISTS ($subQuery)", $logicalOperator);
        return $this;
    }

    /**
     * Filter by non-existence of a relation with additional conditions.
     *
     * @param string $relationName The relation method name on the model.
     * @param callable|null $callback Optional callback to add conditions to the sub-query.
     * @param string $logicalOperator The logical operator.
     * @return static
     * @throws InvalidArgumentException If the name is not a relation on the model.
     */
    public function whereDoesntHave(string $relationName, ?callable $callback = null, string $logicalOperator = 'AND'): static
    {
        $subQuery = $this->buildRelationExistsQuery($relationName, $callback);

        $this->pushCondition("NOT EXISTS ($subQuery)", $logicalOperator);
        return $this;
    }

    /**
     * Build the EXISTS sub-query for a relation.
     *
     * @param string $relationName The relation method name.
     * @param callable|null $callback Optional callback for additional conditions.
     * @return string The sub-query SQL.
     * @throws InvalidArgumentException If the name is not a relation on the model.
     */
    private function buildRelationExistsQuery(string $relationName, ?callable $callback): string
    {
        $relation = $this->resolveRelation($relationName);

        /** @var Model $model */
        $model = new $this->modelClass();

        // The parent row is referenced by the name it has in THIS query. Built
        // from $model->getTable(), an aliased query produced
        // `post`.`user_id` = `user`.`id` under FROM `user` `u` and died with
        // "Unknown column 'user.id'" — every relation filter was unusable
        // together with alias().
        $parent = $this->getAlias() ?? $model->getTable();

        // The sub-query must speak the same dialect as its parent. A default
        // ActiveQuery resolves the DEFAULT connection's grammar, so a query on
        // any other connection mixed quoting styles in a single statement —
        // SELECT "user".* ... EXISTS (SELECT 1 FROM `post` ...) — which no
        // engine parses.
        $subQuery = new ActiveQuery();
        $subQuery->withConnection($this->getConnectionName());
        $subQuery->selectRaw('1');

        $this->relationExistsSource($subQuery, $relation, $parent);

        if ($callback !== null) {
            $callback($subQuery);
        }

        $sql = $subQuery->getSQL();
        $binds = $subQuery->getBinds();
        if ($binds !== null) {
            $this->appendBinds($binds);
        }

        return $sql;
    }

    /**
     * Resolve a relation name against the query's model.
     *
     * @param string $relationName The relation method name.
     * @return Relation
     * @throws InvalidArgumentException If the name is not a relation on the model.
     */
    private function resolveRelation(string $relationName): Relation
    {
        // Returning the query untouched on a name that is not a relation made
        // has('psots') a filter that silently did nothing: the caller asked to
        // narrow the result and got every row back instead. A typo must not
        // widen a result set.
        if (!$this->modelClass) {
            throw new InvalidArgumentException(
                "Cannot filter by the relation '$relationName': this query has no model to resolve it against."
            );
        }

        $class = is_string($this->modelClass) ? $this->modelClass : $this->modelClass::class;

        if (!method_exists($this->modelClass, $relationName)) {
            throw new InvalidArgumentException("$class has no method '$relationName' to use as a relation.");
        }

        /** @var Model $model */
        $model = new $this->modelClass();

        // The same question __get() and EagerLoader ask, asked the same way.
        // method_exists() was the whole guard here too, and then the name was
        // called: has('getTable') ran getTable() before deciding it was not a
        // relation — harmless there, but has('delete') is the same code path —
        // and a method declaring a required argument escaped as a bare
        // ArgumentCountError naming neither the filter nor the relation. A
        // filter must not run the thing it is deciding about.
        if (!$model->isRelationMethod($relationName)) {
            throw new InvalidArgumentException(
                "$class::$relationName() does not return a relation: a relation is a public method that takes"
                . ' no arguments and declares Relation as its return type.'
            );
        }

        // No instanceof check after the call: eligibility already required a
        // declared Relation return type, which PHP enforces itself. The old
        // check was the only thing standing between the caller and whatever the
        // method did, and it ran after the damage.
        return $model->{$relationName}();
    }

    /**
     * Point an EXISTS sub-query at the related rows, correlated to the parent.
     *
     * @param ActiveQuery $subQuery The sub-query to populate.
     * @param Relation $relation The relation being tested.
     * @param string $parent The name the parent row is known by in the outer query.
     * @return void
     */
    private function relationExistsSource(ActiveQuery $subQuery, Relation $relation, string $parent): void
    {
        /** @var Model $relatedModel */
        $relatedModel = new ($relation->getRelatedClass())();
        $relatedTable = $relatedModel->getTable();
        $foreignKey = $relation->getForeignKey();

        $parentRef = $this->quote($parent) . '.' . $this->quote($relation->getLocalKey());

        $viaTable = $relation->getViaTable();
        $viaLink = $relation->getViaLink();

        // Many-to-many: the parent is not in the related table at all, it is in
        // the junction. Ignoring viaTable produced `tag`.`tag_id` = `post`.`id`
        // — a column that does not exist — so every M:N relation filter raised
        // "Unknown column" rather than answering. Only the junction is needed
        // to decide existence; the related table itself adds nothing.
        if ($viaTable !== null && $viaLink !== null) {
            $junction = $viaTable === $parent ? "{$viaTable}_exists" : $viaTable;
            $subQuery->from($junction === $viaTable ? $viaTable : "$viaTable $junction");
            $subQuery->whereRaw(
                $subQuery->quote($junction) . '.' . $subQuery->quote((string)key($viaLink)) . " = $parentRef"
            );
            return;
        }

        // A self-referencing relation puts the same name on both sides, so an
        // unaliased sub-query resolved `category`.`parent_id` = `category`.`id`
        // against its own FROM. That is a row compared to itself, and it
        // answered with the wrong rows in silence — 0 parents instead of 3, and
        // every row for doesntHave() instead of the 6 that have no children.
        // Aliasing only on a collision keeps `related_table`.`col` working in
        // callbacks everywhere else.
        $related = $relatedTable === $parent ? "{$relatedTable}_exists" : $relatedTable;
        $subQuery->from($related === $relatedTable ? $relatedTable : "$relatedTable $related");
        $subQuery->whereRaw(
            $subQuery->quote($related) . '.' . $subQuery->quote($foreignKey) . " = $parentRef"
        );
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
