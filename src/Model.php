<?php

namespace Simsoft\DB;

use ArrayAccess;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Exceptions\MassAssignmentException;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Traits\CastsAttributes;
use Simsoft\DB\Traits\Error;
use Simsoft\DB\Traits\HasEvents;
use Simsoft\DB\Traits\ResolvesRelations;
use stdClass;
use Throwable;

/**
 * Model class.
 *
 * @implements ArrayAccess<string, mixed>
 */
abstract class Model implements ArrayAccess
{
    use CastsAttributes;
    use Error;
    use HasEvents;
    use ResolvesRelations;

    /** @var string|array<int, string> Primary key fields */
    protected string|array $primaryKey = 'id';

    /** @var string Table name. */
    protected string $table = '';

    /** @var string Connection's name. */
    protected string $connection = 'mysql';

    /** @var array<string, mixed> Attributes and its values. */
    protected array $attributes = [];

    /** @var array<string, string> Create alias for attributes */
    protected array $aliasAttributes = [];

    /** @var array<int, string> Attributes that cannot be mass assigned. */
    protected array $guarded = [];

    /** @var array<int, string> Attributes that are mass assignable */
    protected array $fillable = [];

    /**
     * @var bool Whether a model declaring neither $fillable nor $guarded rejects
     *           mass assignment outright.
     *
     * A model that declares neither accepts every column except its primary key,
     * so `$model->fill($request)` writes whatever the request happens to contain.
     * That is convenient in a prototype and a liability in production, but making
     * it the default would break existing models silently, at runtime, in exactly
     * the paths that matter. So it is opt-in: call
     * `Model::requireAssignmentRules()` during bootstrap and any model that
     * has not declared its rules throws instead of guessing.
     */
    protected static bool $strictAssignment = false;

    /** @var bool Whether the model class declared $fillable or $guarded itself. */
    private bool $hasAssignmentRules = false;

    /** @var array<string, int> Dirty attributes */
    protected array $dirtyAttributes = [];

    /**
     * @var array<string, string> Attribute casts.
     *
     * Supported: int, integer, bool, boolean, float, double, real, string,
     * binary, array, json. Any other name throws — a cast the model asked for
     * and did not get is worse than one it never declared.
     *
     * A cast never applies to NULL: a nullable column reads back as null and
     * can be cleared by assigning null.
     *
     * protected array $casts = [
     *  'attribute1' => 'int',
     *  'attribute2' => 'bool',
     *   ...
     *
     */
    protected array $casts = [];

    /** @var array<string, string> All table fields */
    protected array $tableFields = [];

    /** @var bool Indicate the current model is a new record. */
    protected bool $exists = false;

    /** @var bool Determine the model is recently created. */
    protected bool $wasRecentlyCreated = false;

    /** @var array<string, mixed> Relations */
    protected array $relations = [];

    /** @var array<string, array<string, callable>> Global scopes per model class */
    protected static array $globalScopes = [];

    /**
     * Set a preloaded relation value.
     *
     * Used by eager loading to inject related models without triggering queries.
     *
     * @param string $name The relation name.
     * @param mixed $value The related model(s) or null.
     * @return static
     */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    /**
     * Determine if a relation has been loaded.
     *
     * @param string $name The relation name.
     * @return bool
     */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * Constructor.
     *
     * @param array<string, mixed> $attributes Create a model with attributes.
     * @param bool $setNew Set a newly created model to be a new record.
     */
    final public function __construct(array $attributes = [], bool $setNew = true)
    {
        $this->exists = !$setNew;

        // Recorded before the primary key is appended below, which would
        // otherwise make every model look like it had declared a rule.
        $this->hasAssignmentRules = $this->guarded !== [] || $this->fillable !== [];

        foreach ($attributes as $attribute => $value) {
            $this->$attribute = $value;
        }

        if (is_array($this->primaryKey)) {
            $this->guarded = [...$this->guarded, ...$this->primaryKey];
        }

        if (!is_array($this->primaryKey)) {
            $this->guarded[] = $this->primaryKey;
        }

        $this->init();
        if ($this->exists) {
            $this->afterFind();
        }
    }

    /**
     * initialization.
     *
     * @return void
     */
    protected function init(): void
    {

    }

    /**
     * Get the table's name.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the connection's name.
     *
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connection;
    }

    /**
     * Set attribute a value
     *
     * @param string $name Attribute's name.
     * @param mixed $value Value to be assigned to the attribute.
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $cast = $this->castValue($name, $value);

        // A NULL the caller assigned is a value, not the absence of one. Skipping
        // it here meant `$model->parent_id = null` on a new record never entered
        // dirtyAttributes, so insert() left the column out of the statement
        // entirely and the table DEFAULT won instead — the row came back holding
        // 5 where the caller had explicitly written null.
        if ($this->isNew()) {
            $this->dirtyAttributes[$name] = 1;
        }

        if ($this->exists()) {
            if (empty($this->tableFields[$name])) {
                $this->tableFields[$name] = gettype($value);
            }

            if ($this->isChanged($name, $cast)) {
                $this->dirtyAttributes[$name] = 1;
            }
        }

        $this->attributes[$name] = $cast;
    }

    /**
     * Get attribute's value
     *
     * @param string $name Attribute's name.
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        // Reading assigned into $attributes, so merely LOOKING at an attribute
        // rewrote the model: a column holding NULL in the database read back as 0
        // and then reported 0 from toArray(), toJson() and getAttributes(), with
        // no way left to tell a real zero from a missing value. Reading is now a
        // read — the stored value is left exactly as the database gave it.
        if (array_key_exists($name, $this->casts) && array_key_exists($name, $this->attributes)) {
            return $this->readCast($name, $this->attributes[$name]);
        }

        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        // Return pre-loaded relation (from eager loading or previous lazy load)
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        // Lazy load: call the relation method and cache the result.
        //
        // Only methods that declare they return a Relation are eligible; see
        // ResolvesRelations for why method_exists() alone was not enough.
        if ($this->isRelationMethod($name)) {
            return $this->relations[$name] = $this->{$name}()->fetch();
        }

        return null;
    }

    /**
     * Determine whether a property has a value.
     *
     * Reads the property to decide, so testing an unloaded relation lazy-loads
     * it exactly as reading it would; the result is cached, so the query
     * happens once. Use {@see relationLoaded()} to ask whether a relation is
     * already in memory without going to the database.
     *
     * @param string $name The attribute or relation name.
     * @return bool
     */
    public function __isset(string $name): bool
    {
        // Checking $attributes alone ignored relations, so isset() disagreed
        // with reading: `$user->profile` answered with a UserProfile while
        // `isset($user->profile)` was false, and `$user->profile ?? $default`
        // therefore discarded a loaded object and took the default. Deferring
        // to __get() keeps the two in step — true exactly when reading the
        // property answers with something other than null, which is also what
        // isset() means for an ordinary property holding NULL.
        return $this->__get($name) !== null;
    }

    /**
     * Discard an attribute or a loaded relation.
     *
     * Unsetting an attribute drops the pending change to it as well: the value
     * is gone, so there is nothing left to write.
     *
     * @param string $name The attribute or relation name to be unset.
     * @return void
     */
    public function __unset(string $name): void
    {
        // Only $attributes was cleared, leaving the name in $dirtyAttributes
        // and in $relations. The stale dirty entry made isDirty('col') and
        // getDirtyAttributes() name a column the model no longer holds, and
        // since save() writes array_intersect_key($attributes, $dirty) the
        // column silently dropped out of the UPDATE — save() returned true
        // having written nothing, which is indistinguishable from success.
        // The stale relation meant unset($user->posts) left the loaded posts
        // readable and still serialized by toArray().
        unset($this->attributes[$name], $this->dirtyAttributes[$name], $this->relations[$name]);
    }

    /**
     * Array access to set attribute's value.
     *
     * @param mixed $offset Attribute name.
     * @param mixed $value Attribute value.
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        is_string($offset) && $this->__set($offset, $value);
    }

    /**
     * Array access to check attribute isset.
     *
     * @param mixed $offset Attribute name.
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->__isset($offset);
    }

    /**
     * Array access to unset attribute.
     *
     * @param mixed $offset Attribute name.
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        is_string($offset) && $this->__unset($offset);
    }

    /**
     * Array access to get an attribute's value.
     *
     * @param mixed $offset Attribute name.
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->__get($offset) : null;
    }

    /**
     * Determine if the current model is a new record.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return !$this->exists;
    }

    /**
     * Determine the current model is an existing record.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Determine the current model is recently created.
     *
     * @return bool
     */
    public function wasRecentlyCreated(): bool
    {
        return $this->wasRecentlyCreated;
    }

    /**
     * Get primary key fields.
     *
     * @return string|array<int, string>
     */
    public function getPrimaryKeyFields(): string|array
    {
        return $this->primaryKey;
    }

    /**
     * Get the model primary key value.
     *
     * @return mixed
     */
    public function getKey(): mixed
    {
        if (!$this->exists()) {
            return null;
        }

        if (is_array($this->primaryKey)) {
            $keys = new stdClass();
            foreach ($this->primaryKey as $attribute) {
                $keys->{$attribute} = $this->{$attribute};
            }
            return $keys;
        }

        return $this->{$this->primaryKey};
    }

    /**
     * Register a global scope for this model.
     *
     * Global scopes are automatically applied to every query via find().
     *
     * @param string $name The scope name.
     * @param callable $scope A callable that receives an ActiveQuery instance.
     * @return void
     */
    public static function addGlobalScope(string $name, callable $scope): void
    {
        static::$globalScopes[static::class][$name] = $scope;
    }

    /**
     * Remove a registered global scope.
     *
     * @param string $name The scope name to remove.
     * @return void
     */
    public static function removeGlobalScope(string $name): void
    {
        unset(static::$globalScopes[static::class][$name]);
    }

    /**
     * Apply all registered global scopes to a query.
     *
     * @param ActiveQuery $query The query to apply scopes to.
     * @return void
     */
    public static function applyGlobalScopes(ActiveQuery $query): void
    {
        foreach (static::$globalScopes[static::class] ?? [] as $scope) {
            $scope($query);
        }
    }

    /**
     * Get a query without any global scopes applied.
     *
     * @return ActiveQuery
     */
    public static function withoutGlobalScopes(): ActiveQuery
    {
        return new ActiveQuery(get_called_class(), withScopes: false);
    }

    /**
     * Get a query without a specific global scope.
     *
     * @param string $name The scope name to exclude.
     * @return ActiveQuery
     */
    public static function withoutGlobalScope(string $name): ActiveQuery
    {
        $query = new ActiveQuery(get_called_class(), withScopes: false);

        // Apply soft delete scope still
        static::applySoftDeleteScope($query);

        // Apply all global scopes except the named one
        foreach (static::$globalScopes[static::class] ?? [] as $scopeName => $scope) {
            if ($scopeName !== $name) {
                $scope($query);
            }
        }
        return $query;
    }

    /**
     * Get query object.
     *
     * Scopes (soft delete, global) are applied automatically by ActiveQuery constructor.
     *
     * @return ActiveQuery
     */
    public static function find(): ActiveQuery
    {
        return new ActiveQuery(get_called_class());
    }

    /**
     * Apply soft delete scope if the model uses SoftDeletes trait.
     *
     * @param ActiveQuery $query The query to scope.
     * @return void
     */
    public static function applySoftDeleteScope(ActiveQuery $query): void
    {
        $model = new static();
        if (method_exists($model, 'softDeleteScope')) {
            $model->softDeleteScope($query);
        }
    }

    /**
     * Hydrate a model from database row data (fast path).
     *
     * Bypasses guarded/fillable checks and dirty tracking since
     * the data comes directly from the database.
     *
     * @param array<string, mixed> $attributes The raw database row.
     * @return static
     */
    public static function hydrate(array $attributes): static
    {
        $model = new static([], false);
        $model->attributes = $attributes;
        $model->dirtyAttributes = [];
        $model->afterFind();
        return $model;
    }

    /**
     * Find by primary keys.
     *
     * @param string|int|array<string, mixed> $pk The primary key values.
     * @return static|null
     */
    public static function findByPk(string|int|array $pk): ?static
    {
        $model = new static();
        $primaryKeyField = $model->getPrimaryKeyFields();

        if (is_array($pk)) {
            return static::find()->findByPk($pk);
        }

        if (is_string($primaryKeyField)) {
            return static::find()->findByPk([$primaryKeyField => $pk]);
        }

        return null;
    }

    /**
     * Find all records matching conditions.
     *
     * @param array<string, mixed> $conditions Attribute => value pairs for WHERE conditions.
     * @return Collection
     */
    public static function findAll(array $conditions = []): Collection
    {
        $query = static::find();
        foreach ($conditions as $attribute => $value) {
            if (is_array($value)) {
                $query->in($attribute, $value);
                continue;
            }
            if ($value === null) {
                $query->isNull($attribute);
                continue;
            }
            $query->where($attribute, $value);
        }
        return $query->get();
    }

    /**
     * Get a model query with its primary keys.
     *
     * @return array<int, array{0: string, 1: string, 2: mixed}>
     * @throws QueryException If the model exists but a key attribute is missing.
     */
    protected function getPKs(): array
    {
        $keys = [];

        if (!$this->exists()) {
            return $keys;
        }

        $attributes = is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];

        foreach ($attributes as $attribute) {
            $keys[] = [$attribute, '=', $this->requireKeyValue($attribute)];
        }

        return $keys;
    }

    /**
     * Read a primary key value, refusing to proceed without one.
     *
     * @param string $attribute The primary key attribute.
     * @return mixed The key value.
     * @throws QueryException If the value is null.
     */
    private function requireKeyValue(string $attribute): mixed
    {
        $value = $this->{$attribute};

        if ($value !== null) {
            return $value;
        }

        // A null key built `WHERE id = ?` bound to null, which matches no row
        // in SQL. update(), delete() and updateCounter() then affected nothing
        // and returned true — the driver reports a successful statement, not a
        // matched row — so `unset($user->id); $user->delete();` reported the
        // record deleted while it was still in the table. Refusing here turns a
        // silent no-op into an error at the point the key went missing.
        throw new QueryException(sprintf(
            'Cannot build a primary key condition for %s: the key attribute "%s" is null.'
            . ' The model is marked as existing but has no key, so the statement would match no row'
            . ' while reporting success. Reload it with refresh() or assign the key.',
            static::class,
            $attribute
        ));
    }

    /**
     * Perform find.
     *
     * @return void
     */
    protected function afterFind(): void
    {

    }

    /**
     * Implement before mass assignment.
     *
     * @return void
     */
    protected function beforeFill(): void
    {

    }

    /**
     * Mass assign attributes
     *
     * @param array<string, mixed> $attributes The array of attribute => value pairs.
     * @return $this
     */
    public function fill(array $attributes): static
    {
        foreach ($this->filterMassAssignable($attributes) as $attribute => $value) {
            $this->{$attribute} = $value;
        }
        return $this;
    }

    /**
     * Apply mass assignment rules to an array of attributes.
     *
     * Resolves attribute aliases, strips `$guarded` attributes (primary keys
     * are always guarded), and — when `$fillable` is non-empty — restricts the
     * result to the fillable whitelist.
     *
     * Use this for any attribute array that originates outside the
     * application (request payloads, API bodies). Attributes set by direct
     * assignment are trusted and are not subject to these rules.
     *
     * @param array<string, mixed> $attributes The attribute => value pairs to filter.
     * @return array<string, mixed> The attributes safe to assign.
     */
    protected function filterMassAssignable(array $attributes): array
    {
        $this->guardUnrestrictedMassAssignment($attributes);

        foreach ($this->aliasAttributes as $alias => $attribute) {
            if (array_key_exists($alias, $attributes)) {
                $attributes[$attribute] = $attributes[$alias];
                unset($attributes[$alias]);
            }
        }

        foreach (array_unique($this->guarded) as $attribute) {
            unset($attributes[$attribute]);
        }

        if ($this->fillable) {
            $this->beforeFill();
            $attributes = array_intersect_key($attributes, array_flip($this->fillable));
        }

        return $attributes;
    }

    /**
     * Require every model to declare its mass assignment rules.
     *
     * Call this once during bootstrap. A model that declares neither
     * `$fillable` nor `$guarded` then throws on mass assignment rather than
     * accepting every column, which turns an easily missed omission into a
     * failure you find on the first request instead of after a bad write.
     *
     * Direct assignment (`$model->column = $value`) is unaffected, as are
     * `updateAttributes()` and the other documented bypasses.
     *
     * @return void
     */
    public static function requireAssignmentRules(): void
    {
        static::$strictAssignment = true;
    }

    /**
     * Go back to accepting models that declare no mass assignment rules.
     *
     * The default. Provided so a test or a one-off script can undo
     * {@see requireAssignmentRules()} without restarting the process.
     *
     * @return void
     */
    public static function allowUndeclaredAssignment(): void
    {
        static::$strictAssignment = false;
    }

    /**
     * Reject mass assignment on a model that declared no rules.
     *
     * @param array<string, mixed> $attributes The attributes being assigned.
     * @return void
     * @throws MassAssignmentException If rules are required but not declared.
     */
    private function guardUnrestrictedMassAssignment(array $attributes): void
    {
        if (!static::$strictAssignment || $attributes === []) {
            return;
        }

        if ($this->hasAssignmentRules) {
            return;
        }

        throw new MassAssignmentException(sprintf(
            '%s declares neither $fillable nor $guarded, so mass assignment cannot be '
            . 'checked. Declare $fillable with the attributes that may be filled from '
            . 'external input, or assign the attributes directly.',
            static::class
        ));
    }

    /**
     * Get all attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Convert model to array.
     *
     * Includes attributes and loaded relations. Cast attributes are presented
     * through their cast, so a 'json' column serializes as the decoded document
     * rather than the encoded string, and matches what reading the property
     * gives. {@see getAttributes()} returns the raw stored values instead.
     *
     * @param array<int, string>|null $fields Specific fields to include. Null for all.
     * @return array<string, mixed>
     */
    public function toArray(?array $fields = null): array
    {
        $attributes = $fields === null
            ? $this->attributes
            : array_intersect_key($this->attributes, array_flip($fields));

        foreach (array_keys($this->casts) as $name) {
            if (array_key_exists($name, $attributes)) {
                $attributes[$name] = $this->readCast($name, $attributes[$name]);
            }
        }

        foreach ($this->relations as $name => $related) {
            if ($fields !== null && !in_array($name, $fields)) {
                continue;
            }

            $attributes[$name] = $this->serializeRelation($related);
        }

        return $attributes;
    }

    /**
     * Serialize a single relation value for toArray output.
     *
     * @param mixed $related The relation value: null, a Model, or a list of
     *                       Models as either an array or a Collection.
     * @return mixed
     */
    private function serializeRelation(mixed $related): mixed
    {
        if ($related === null) {
            return null;
        }

        if ($related instanceof self) {
            return $related->toArray();
        }

        // A to-many relation arrives as an array when eager-loaded and as a
        // Collection when lazy-loaded, and only the array was serialized. The
        // Collection fell through to "return it unchanged", so toJson() encoded
        // three posts as the empty object {} — Collection exposes no public
        // properties for json_encode to find — while the same model loaded with
        // with('posts') produced the full list.
        if ($related instanceof Collection) {
            $related = iterator_to_array($related);
        }

        if (!is_array($related)) {
            return $related;
        }

        $result = [];
        foreach ($related as $item) {
            $result[] = $item instanceof self ? $item->toArray() : $item;
        }
        return $result;
    }

    /**
     * Convert model to JSON string.
     *
     * @param int $options JSON encoding options. Default: 0.
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options) ?: '{}';
    }

    /**
     * Get only specified attributes.
     *
     * @param array<int, string> $fields The attribute names to include.
     * @return array<string, mixed>
     */
    public function only(array $fields): array
    {
        return array_intersect_key($this->attributes, array_flip($fields));
    }

    /**
     * Get all attributes except specified ones.
     *
     * @param array<int, string> $fields The attribute names to exclude.
     * @return array<string, mixed>
     */
    public function except(array $fields): array
    {
        return array_diff_key($this->attributes, array_flip($fields));
    }

    /**
     * Replicate the model as a new unsaved instance.
     *
     * Strips the primary key and resets the exists flag.
     *
     * @param array<int, string>|null $except Attributes to exclude from the copy.
     * @return static
     */
    public function replicate(?array $except = null): static
    {
        $attributes = $this->attributes;

        // Remove primary key
        if (is_array($this->primaryKey)) {
            foreach ($this->primaryKey as $key) {
                unset($attributes[$key]);
            }
        }

        if (!is_array($this->primaryKey)) {
            unset($attributes[$this->primaryKey]);
        }

        // Remove additional excluded attributes
        if ($except !== null) {
            foreach ($except as $key) {
                unset($attributes[$key]);
            }
        }

        return new static($attributes, true);
    }

    /**
     * Increment a column value atomically.
     *
     * @param string $attribute The column to increment.
     * @param int|float $amount The increment amount. Default: 1.
     * @return bool
     */
    public function increment(string $attribute, int|float $amount = 1): bool
    {
        return $this->updateCounter($attribute, abs($amount));
    }

    /**
     * Decrement a column value atomically.
     *
     * @param string $attribute The column to decrement.
     * @param int|float $amount The decrement amount. Default: 1.
     * @return bool
     */
    public function decrement(string $attribute, int|float $amount = 1): bool
    {
        return $this->updateCounter($attribute, -abs($amount));
    }

    /**
     * Determine if the model (or specific attributes) is dirty.
     *
     * @param string ...$attributes Attribute names to check. If empty, checks any.
     * @return bool
     */
    public function isDirty(string ...$attributes): bool
    {
        if (empty($attributes)) {
            return !empty($this->dirtyAttributes);
        }

        foreach ($attributes as $attribute) {
            if (isset($this->dirtyAttributes[$attribute])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get dirty attributes.
     *
     * @return array<int, string>
     */
    public function getDirtyAttributes(): array
    {
        return array_keys($this->dirtyAttributes);
    }

    /**
     * Perform transaction.
     *
     * @param callable $callback Callback function to perform insert/ update/ delete operations.
     * @return bool
     * @throws QueryException
     */
    public static function transaction(callable $callback): bool
    {
        try {
            return Connection::get((new static())->getConnectionName())->transaction($callback);
        } catch (Throwable $throwable) {
            throw new QueryException($throwable->getMessage(), '', null, 0, $throwable);
        }
    }

    /**
     * Refresh the current record from a database.
     *
     * @return bool
     */
    public function refresh(): bool
    {
        if ($this->isNew()) {
            return false;
        }

        $fresh = static::find()->where($this->getPKs())->first();
        if (!$fresh instanceof static) {
            return false;
        }

        $this->attributes = $fresh->getAttributes();
        $this->dirtyAttributes = [];

        return true;
    }

    /**
     * Perform validation.
     *
     * @return bool
     */
    public function validate(): bool
    {
        return true;
    }

    /**
     * Perform before save
     *
     * @return void
     */
    protected function beforeSave(): void
    {

    }

    /**
     * Perform after save.
     *
     * @return void
     */
    protected function afterSave(): void
    {

    }

    /**
     * Save record
     *
     * @param bool $validate Enable validation.
     * @return bool
     */
    public function save(bool $validate = true): bool
    {
        if ($validate && !$this->validate()) {
            return false;
        }

        if (!$this->fireEvent('saving')) {
            return false;
        }

        $isNew = $this->isNew();

        if (!$this->fireEvent($isNew ? 'creating' : 'updating')) {
            return false;
        }

        $this->beforeSave();
        $saved = $isNew ? $this->insert() : $this->performUpdate();

        if ($saved) {
            $this->afterSave();
            $this->fireEvent($isNew ? 'created' : 'updated');
            $this->fireEvent('saved');
        }

        return $saved;
    }

    /**
     * Perform an insert operation.
     *
     * @return bool
     */
    public function insert(): bool
    {
        $attributes = array_intersect_key($this->attributes, $this->dirtyAttributes);
        if ($attributes) {
            $query = new Insert($this->getTable(), $attributes);
            $query->withConnection($this->getConnectionName());

            // Use RETURNING for PostgreSQL to get the inserted ID reliably
            // (PDO::lastInsertId() requires sequence name on PG without RETURNING)
            $grammar = Connection::grammar($this->getConnectionName());
            if ($grammar->getDriverName() === 'pgsql' && is_string($this->primaryKey)) {
                $query->returning($this->primaryKey);
            }

            $status = $query->execute();
            if ($status) {
                $key = $query->getLastInsertId();
                if (is_string($this->primaryKey) && $key) {
                    $this->{$this->primaryKey} = is_numeric($key) ? (int)$key : $key;
                }
                $this->exists = true;
                $this->wasRecentlyCreated = true;
                $this->dirtyAttributes = [];
                return true;
            }
        }
        return false;
    }

    /**
     * Insert multiple records in batches.
     *
     * Efficiently inserts many records using chunked multi-row INSERT statements.
     * All records must have the same column structure (based on the first record's keys).
     *
     * @param array<int, array<string, mixed>> $records Array of associative arrays (column => value).
     * @param int $chunkSize Number of records per INSERT statement. Default: 500.
     * @return int The number of records inserted.
     */
    public static function insertBatch(array $records, int $chunkSize = 500): int
    {
        if (empty($records)) {
            return 0;
        }

        $model = new static();
        $table = $model->getTable();
        $connectionName = $model->getConnectionName();
        $inserted = 0;

        $chunkSize = max(1, $chunkSize);

        // Built here from a Raw, this assembled the same multi-row statement
        // Insert already builds — minus the identifier validation, and minus the
        // check that no record names a column the first one does not, whose
        // value would otherwise be dropped without a word.
        foreach (array_chunk($records, $chunkSize) as $chunk) {
            (new Insert($table, $chunk))->withConnection($connectionName)->execute();
            $inserted += count($chunk);
        }

        return $inserted;
    }

    /**
     * Update multiple records with different values using a single CASE WHEN query.
     *
     * Generates: UPDATE table SET col = CASE key WHEN ? THEN ? ... END WHERE key IN (...)
     *
     * @param array<int, array<string, mixed>> $updates Array of rows, each with key column + columns to update.
     * @param string $keyColumn The column used to identify each row. Default: 'id'.
     * @return bool
     */
    public static function updateBatch(array $updates, string $keyColumn = 'id'): bool
    {
        if (empty($updates)) {
            return false;
        }

        $model = new static();
        $table = $model->getTable();
        $connectionName = $model->getConnectionName();
        $grammar = Connection::grammar($connectionName);

        // Get all columns to update (exclude the key column)
        $columns = array_keys($updates[0]);
        $columns = array_values(array_filter($columns, fn(string $col): bool => $col !== $keyColumn));

        // Collect key values
        $keyValues = array_column($updates, $keyColumn);

        // Build CASE WHEN for each column
        $setClauses = [];
        $binds = [];

        $quotedKey = $grammar->quoteIdentifier($keyColumn);

        foreach ($columns as $col) {
            $cases = [];
            foreach ($updates as $row) {
                $cases[] = "WHEN ? THEN ?";
                $binds[] = $row[$keyColumn];
                $binds[] = $row[$col];
            }
            $quotedCol = $grammar->quoteIdentifier($col);
            $setClauses[] = "$quotedCol = CASE $quotedKey " . implode(' ', $cases) . " END";
        }

        // Build WHERE IN
        $placeholders = implode(',', array_fill(0, count($keyValues), '?'));
        $quotedTable = $grammar->quoteIdentifier($table);

        $sql = "UPDATE $quotedTable SET " . implode(', ', $setClauses)
            . " WHERE $quotedKey IN ($placeholders)";

        // Add key values to binds for WHERE IN
        foreach ($keyValues as $keyValue) {
            $binds[] = $keyValue;
        }

        $raw = new Raw($sql, $binds);
        $raw->withConnection($connectionName);
        return $raw->execute();
    }

    /**
     * Perform update operation.
     *
     * Attributes passed here are treated as mass assignment and are filtered
     * through `$fillable`/`$guarded`, making `$model->update($request)` safe.
     * Attributes already set by direct assignment (and therefore tracked as
     * dirty) are trusted and always included.
     *
     * The `beforeSave()` hook runs first, so traits that maintain columns
     * automatically — `Timestamps` and its `updated_at` in particular — apply
     * here just as they do for `save()`. Events are not fired; call `save()`
     * when you need those.
     *
     * To write a guarded attribute deliberately, assign it directly and call
     * `save()`, or use `updateAttributes()` to bypass these rules.
     *
     * @param array<string, mixed> $attributes Attributes to be updated. Array of Attribute => Value pairs.
     * @return bool
     */
    public function update(array $attributes = []): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $this->beforeSave();

        return $this->performUpdate($attributes);
    }

    /**
     * Write the pending changes for an existing record.
     *
     * Split out from update() so that save(), which has already run
     * beforeSave(), does not run it a second time.
     *
     * @param array<string, mixed> $attributes Mass-assignable attributes to apply.
     * @return bool
     */
    private function performUpdate(array $attributes = []): bool
    {
        $attributes = array_merge(
            array_intersect_key($this->attributes, $this->dirtyAttributes),
            $this->filterMassAssignable($attributes)
        );

        if ($attributes === []) { // nothing to update
            return true;
        }

        $result = (new Update($this->getTable(), $attributes, static::find()->where($this->getPKs())))
            ->withConnection($this->getConnectionName())->execute();

        if ($result) {
            $this->dirtyAttributes = [];
        }

        return $result;
    }

    /**
     * Update attributes directly, bypassing the model lifecycle.
     *
     * Security: this method does NOT apply `$fillable`/`$guarded` filtering —
     * every supplied attribute is written. Never pass unvalidated external
     * input (e.g. `$_POST`) to it; use `update()` for that.
     *
     * @param array<string, mixed> $attributes Array of Attribute => Value pairs.
     * @return bool
     */
    public function updateAttributes(array $attributes): bool
    {
        if ($this->exists()) {
            return (new Update($this->getTable(), $attributes, static::find()->where($this->getPKs())))
                ->withConnection($this->getConnectionName())->execute();
        }
        return false;
    }

    /**
     * Update all records.
     *
     * @param array<string, mixed> $attributes Array of attribute => new value pairs
     * @param ActiveQuery|null $query
     * @return bool
     */
    public function updateAll(array $attributes, ?ActiveQuery $query = null): bool
    {
        return (new Update($this->getTable(), $attributes, $query))->withConnection($this->getConnectionName())->execute();
    }

    /**
     * Update attribute's counter
     *
     * @param string $attribute
     * @param int|float $value
     * @return bool
     */
    public function updateCounter(string $attribute, int|float $value = 1): bool
    {
        if ($this->exists()) {
            return (new Update($this->getTable(), condition: static::find()->where($this->getPKs())))
                ->setCounter($attribute, $value)
                ->withConnection($this->getConnectionName())->execute();
        }

        return false;
    }

    /**
     * Perform delete operation.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        if (!$this->fireEvent('deleting')) {
            return false;
        }

        $result = (new Delete($this->getTable(), static::find()->where($this->getPKs())))
            ->withConnection($this->getConnectionName())
            ->execute();

        if ($result) {
            $this->fireEvent('deleted');
        }

        return $result;
    }

    /**
     * Delete all records from the table matching condition.
     *
     * Requires an explicit condition to prevent accidental full-table deletes.
     * Use deleteAllUnchecked() if you intentionally want to delete all records.
     *
     * @param string|ActiveQuery|Raw $condition The delete condition.
     * @return bool
     */
    public function deleteAll(string|ActiveQuery|Raw $condition): bool
    {
        return (new Delete($this->getTable(), $condition))
            ->withConnection($this->getConnectionName())
            ->execute();
    }

    /**
     * Delete ALL records from the table without any condition.
     *
     * WARNING: This will delete every row in the table. Use with caution.
     *
     * @return bool
     */
    public function deleteAllUnchecked(): bool
    {
        return (new Delete($this->getTable(), null))
            ->withConnection($this->getConnectionName())
            ->execute();
    }

    /**
     * Get has one relation.
     *
     * @param string $modelClass The related model class.
     * @param array<string, string> $onKeys ['foreign_key' => 'local_key'] mapping.
     * @return Relation
     */
    public function hasOne(string $modelClass, array $onKeys): Relation
    {
        $foreignKey = (string)array_key_first($onKeys);
        $localKey = (string)current($onKeys);

        /** @var ActiveQuery $query */
        $query = $modelClass::find();

        return new Relation(
            $query,
            $foreignKey,
            $this->{$localKey},
            false,
            $localKey,
            $modelClass
        );
    }

    /**
     * Get has much relation.
     *
     * @param string $modelClass The related model class.
     * @param array<string, string> $onKeys ['foreign_key' => 'local_key'] mapping.
     * @return Relation
     */
    public function hasMany(string $modelClass, array $onKeys): Relation
    {
        $foreignKey = (string)array_key_first($onKeys);
        $localKey = (string)current($onKeys);

        /** @var ActiveQuery $query */
        $query = $modelClass::find();

        return new Relation(
            $query,
            $foreignKey,
            $this->{$localKey},
            true,
            $localKey,
            $modelClass
        );
    }

    // ------------------------------------------------------------------
    // Save Together (recursive relation saving)
    // ------------------------------------------------------------------

    /**
     * Save the model and all nested relations in one transaction.
     *
     * @param array<string, mixed> $data Attributes and relation data.
     * @param bool $validate Enable validation before saving.
     * @return bool
     * @throws QueryException
     */
    public function saveTogether(array $data, bool $validate = true): bool
    {
        if ($validate) {
            [$attributes] = $this->separateRelations($data);
            if (!empty($attributes)) {
                $this->fill($attributes);
            }

            if (!$this->validate()) {
                return false;
            }
        }

        $exception = null;

        $result = static::transaction(function () use ($data, &$exception) {
            try {
                return $this->performSaveTogether($data);
            } catch (\Throwable $throwable) {
                $exception = $throwable;
                return false;
            }
        });

        if ($exception !== null) {
            throw new QueryException($exception->getMessage(), '', null, 0, $exception);
        }

        return $result;
    }

    /**
     * Internal recursive save logic.
     *
     * @param array<string, mixed> $data Attributes and relation data.
     * @return bool
     */
    private function performSaveTogether(array $data): bool
    {
        [$attributes, $relations] = $this->separateRelations($data);

        if (!empty($attributes)) {
            $this->fill($attributes);
        }

        if (!$this->save(validate: false)) {
            return false;
        }

        foreach ($relations as $relationName => $relationData) {
            if (!$this->saveRelationData($relationName, $relationData)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if a key represents a relation.
     *
     * @param string $key The key name.
     * @param mixed $value The value.
     * @return bool
     */
    protected function isRelationKey(string $key, mixed $value): bool
    {
        if (!method_exists($this, $key)) {
            return false;
        }

        if ($value instanceof self) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        $result = $this->{$key}();

        return $result instanceof Relation;
    }

    /**
     * Separate attributes from relation data.
     *
     * @param array<string, mixed> $data Mixed attributes and relation data.
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function separateRelations(array $data): array
    {
        $attributes = [];
        $relations = [];

        foreach ($data as $key => $value) {
            if (!is_string($key) || !$this->isRelationKey($key, $value)) {
                $attributes[$key] = $value;
                continue;
            }
            $relations[$key] = $value;
        }

        return [$attributes, $relations];
    }

    /**
     * Save relation data (handles hasOne and hasMany with recursion).
     *
     * @param string $relationName The relation method name.
     * @param mixed $data The relation data.
     * @return bool
     */
    protected function saveRelationData(string $relationName, mixed $data): bool
    {
        $relation = $this->{$relationName}();

        if (!$relation instanceof Relation) {
            return true;
        }

        if (!$relation->isMultiple()) {
            return $this->saveHasOneRelation($relation, $data);
        }

        return $this->saveHasManyRelation($relation, $data);
    }

    /**
     * Save a hasOne relation with nested support.
     *
     * @param Relation $relation The relation instance.
     * @param mixed $data Array or Model.
     * @return bool
     */
    private function saveHasOneRelation(Relation $relation, mixed $data): bool
    {
        if ($data instanceof self) {
            $relation->save($data);
            return true;
        }

        if (!is_array($data)) {
            return true;
        }

        $relatedClass = $relation->getRelatedClass();
        /** @var Model $tempModel */
        $tempModel = new $relatedClass();
        [$attributes, $nestedRelations] = $tempModel->separateRelations($data);

        $savedModel = $relation->save($attributes);

        foreach ($nestedRelations as $nestedName => $nestedData) {
            if (!$savedModel->saveRelationData($nestedName, $nestedData)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save a hasMany relation with nested support.
     *
     * @param Relation $relation The relation instance.
     * @param mixed $data Array of items.
     * @return bool
     */
    private function saveHasManyRelation(Relation $relation, mixed $data): bool
    {
        if (!is_array($data)) {
            return true;
        }

        foreach ($data as $item) {
            if (!$this->saveHasManyItem($relation, $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save a single hasMany item with nested support.
     *
     * @param Relation $relation The relation instance.
     * @param mixed $item A Model instance or attributes array.
     * @return bool
     */
    private function saveHasManyItem(Relation $relation, mixed $item): bool
    {
        if ($item instanceof self) {
            $relation->save($item);
            return true;
        }

        if (!is_array($item)) {
            return true;
        }

        $relatedClass = $relation->getRelatedClass();
        /** @var Model $tempModel */
        $tempModel = new $relatedClass();
        [$attributes, $nestedRelations] = $tempModel->separateRelations($item);

        $savedModel = $relation->save($attributes);

        foreach ($nestedRelations as $nestedName => $nestedData) {
            if (!$savedModel->saveRelationData($nestedName, $nestedData)) {
                return false;
            }
        }

        return true;
    }
}
