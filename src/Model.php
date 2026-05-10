<?php

namespace Simsoft\DB\MySQL;

use ArrayAccess;
use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Delete;
use Simsoft\DB\MySQL\Builder\Insert;
use Simsoft\DB\MySQL\Builder\Raw;
use Simsoft\DB\MySQL\Builder\Update;
use Simsoft\DB\MySQL\Traits\Error;
use stdClass;
use Throwable;

/**
 * Model class.
 */
abstract class Model implements ArrayAccess
{
    use Error;

    /** @var string|array Primary key fields */
    protected string|array $primaryKey = 'id';

    /** @var string Table name. */
    protected string $table = '';

    /** @var string Connection's name. */
    protected string $connection = 'mysql';

    /** @var array Attributes and its values. */
    protected array $attributes = [];

    /** @var array Create alias for attributes */
    protected array $aliasAttributes = [];

    /** @var array Attributes that cannot be mass assigned. */
    protected array $guarded = [];

    /** @var array Attributes that are mass assignable */
    protected array $fillable = [];

    /** @var array Dirty attributes */
    protected array $dirtyAttributes = [];

    /**
     * @var array Attributes casts. Supported casts' int, bool, float, string, array
     *
     * protected array $casts = [
     *  'attribute1' => 'int',
     *  'attribute2' => 'bool',
     *   ...
     * ];
     */
    protected array $casts = [];

    /** @var array All table fields */
    public array $tableFields = [];

    /** @var bool Indicate current model is a new record. */
    protected bool $exists = false;

    /** @var bool Determine the model is recently created. */
    protected bool $wasRecentlyCreated = false;

    /** @var mixed Previous primary key value. Will be used when $protectPK is false. */
    protected mixed $previousPK = null;

    /** @var Relation[] Relations */
    protected array $relations = [];

    /**
     * Constructor.
     *
     * @param array $attributes Create model with attributes.
     * @param bool $setNew Set newly created model to be a new record.
     */
    final public function __construct(array $attributes = [], bool $setNew = true)
    {
        $this->exists = !$setNew;
        foreach ($attributes as $attribute => $value) {
            $this->$attribute = $value;
        }

        if (is_array($this->primaryKey)) {
            $this->guarded = [...$this->guarded, ...$this->primaryKey];
        } else {
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
     * Get table's name.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get connection's name.
     *
     * @return string
     */
    public function getConnection(): string
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
        if ($this->isNew() && $value !== null) {
            $this->dirtyAttributes[$name] = 1;
        } elseif ($this->exists()) {
            if (empty($this->tableFields[$name])) {
                $this->tableFields[$name] = gettype($value);
            } elseif ($this->{$name} != $value) {
                $this->dirtyAttributes[$name] = 1;
            }
        }

        if (array_key_exists($name, $this->casts)) {
            match ($this->casts[$name]) {
                'int', 'integer' => $this->attributes[$name] = (int)$value,
                'bool', 'boolean' => $this->attributes[$name] = (bool)$value,
                'float', 'double', 'real' => $this->attributes[$name] = (float)$value,
                'string', 'binary' => $this->attributes[$name] = (string)$value,
                'array' => $this->attributes[$name] = (array)$value,
            };
        } else {
            $this->attributes[$name] = $value;
        }
    }

    /**
     * Get attribute's value
     *
     * @param string $name Attribute's name.
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->casts)) {
            $this->attributes[$name] = match ($this->casts[$name]) {
                'int', 'integer' => (int)$this->attributes[$name] ?? 0,
                'bool', 'boolean' => (bool)$this->attributes[$name] ?? false,
                'float', 'double', 'real' => (float)$this->attributes[$name] ?? 0.00,
                'string', 'binary' => (string)$this->attributes[$name] ?? '',
                'array' => (array)$this->attributes[$name] ?? [],
            };
        }

        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name] ?? null;
        }

        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        if (method_exists($this, $name)) {
            return $this->relations[$name] = $this->{$name}()->fetch();
        }

        return null;
    }

    /**
     * Determine is attribute empty.
     *
     * @param string $name The attribute name.
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /**
     * Allow unset attribute.
     *
     * @param string $name The attribute name to be unset.
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
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
     * Array access to get attribute's value.
     *
     * @param mixed $offset Attribute name.
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? $this->__get($offset) : null;
    }

    /**
     * Determine is the current model is new record.
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
     * @return string|array
     */
    public function getPrimaryKeyFields(): string|array
    {
        return $this->primaryKey;
    }

    /**
     * Get model primary key value.
     *
     * @return mixed
     */
    public function getKey(): mixed
    {
        if ($this->exists()) {
            if (is_array($this->primaryKey)) {
                $keys = new stdClass();
                foreach ($this->primaryKey as $attribute) {
                    $keys->{$attribute} = $this->{$this->primaryKey};
                }
                return $keys;
            }
            return $this->{$this->primaryKey};
        }
        return null;
    }

    /**
     * Get query object.
     *
     * @return ActiveQuery
     */
    public static function find(): ActiveQuery
    {
        return new ActiveQuery(get_called_class());
    }

    /**
     * Find by primary keys.
     *
     * @param string|int|array $pk The primary key values.
     * @return static|null
     */
    public static function findByPk(string|int|array $pk): ?static
    {
        return static::find()->findByPk(is_array($pk) ? $pk : [(new static())->getPrimaryKeyFields() => $pk]);
    }

    /**
     * Get model query with its primary keys.
     *
     * @return array
     */
    protected function getPKs(): array
    {
        $keys = [];

        if ($this->exists()) {
            if (is_array($this->primaryKey)) {
                foreach ($this->primaryKey as $attribute) {
                    $keys[] = [$attribute, '=', $this->{$attribute}];
                }
            } else {
                $keys[] = [$this->primaryKey, '=', $this->{$this->primaryKey}];
            }
        }

        return $keys;
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
     * @param array $attributes The array of attribute => value pairs.
     * @return $this
     */
    public function fill(array $attributes): static
    {
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

        foreach ($attributes as $attribute => $value) {
            $this->{$attribute} = $value;
        }
        return $this;
    }

    /**
     * Get all attributes.
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Determine is model is dirty.
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return !empty($this->dirtyAttributes);
    }

    /**
     * Get dirty attributes.
     *
     * @return array
     */
    public function getDirtyAttributes(): array
    {
        return array_keys($this->dirtyAttributes);
    }

    /**
     * Perform transaction.
     *
     * @param callable $callback Callback function to performs insert/ update/ delete operations
     * @return bool
     */
    public static function transaction(callable $callback): bool
    {
        try {
            return Connection::get((new static())->getConnection())->transaction($callback);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
        return false;
    }

    /**
     * Refresh current record.
     *
     * @return bool
     */
    public function refresh(): bool
    {
        if ($this->isNew()) {
            return false;
        }

        foreach (static::find()->where($this->getPKs())->limit(1)->getArray() as $attribute => $value) {
            $this->attributes[$attribute] = $value;
        }
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

        $this->beforeSave();
        $saved = $this->exists() ? $this->update() : $this->insert();
        if ($saved) {
            $this->afterSave();
        }
        return $saved;
    }

    /**
     * Perform insert operation.
     *
     * @return bool
     */
    public function insert(): bool
    {
        $attributes = array_intersect_key($this->attributes, $this->dirtyAttributes);
        if ($attributes) {
            $query = new Insert($this->getTable(), $attributes);
            $status = $query->setConnection($this->getConnection())->execute();
            if ($status) {
                $key = $query->getLastInsertId();
                if (is_string($this->primaryKey) && $key) {
                    $this->{$this->primaryKey} = is_numeric($key) ? (int)$key : $key;
                    $this->exists = true;
                    $this->wasRecentlyCreated = true;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Perform update operation.
     *
     * @param array $attributes Attributes to be updated. Array of Attribute => Value pairs.
     * @return bool
     */
    public function update(array $attributes = []): bool
    {
        if ($this->exists()) {
            $attributes = array_merge(array_intersect_key($this->attributes, $this->dirtyAttributes), $attributes);
            if ($attributes === []) { // nothing to update
                return true;
            }

            return (new Update($this->getTable(), $attributes, static::find()->where($this->getPKs())))
                ->setConnection($this->getConnection())->execute();
        }
        return false;
    }

    /**
     * Update attributes.
     *
     * @param array $attributes Array of Attribute => Value pairs.
     * @return bool
     */
    public function updateAttributes(array $attributes): bool
    {
        if ($this->exists()) {
            return (new Update($this->getTable(), $attributes, static::find()->where($this->getPKs())))
                ->setConnection($this->getConnection())->execute();
        }
        return false;
    }

    /**
     * Update all records.
     *
     * @param array $attributes Array of attribute => new value pairs
     * @param ActiveQuery|null $query
     * @return bool
     */
    public function updateAll(array $attributes, ?ActiveQuery $query = null): bool
    {
        return (new Update($this->getTable(), $attributes, $query))->setConnection($this->getConnection())->execute();
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
                ->setConnection($this->getConnection())->execute();
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
        if ($this->exists()) {
            return (new Delete($this->getTable(), static::find()->where($this->getPKs())))
                ->setConnection($this->getConnection())
                ->execute();
        }
        return false;
    }

    /**
     * Delete all records from table.
     *
     * @param string|ActiveQuery|Raw|null $condition
     * @return bool
     */
    public function deleteAll(string|ActiveQuery|Raw|null $condition = null): bool
    {
        if ($this->exists()) {
            return (new Delete($this->getTable(), $condition))
                ->setConnection($this->getConnection())
                ->execute();
        }
        return false;
    }

    /**
     * Get has one relation.
     *
     * @param string $modelClass
     * @param array $onKeys
     * @return Relation
     */
    public function hasOne(string $modelClass, array $onKeys): Relation
    {
        return new Relation(forward_static_call_array([$modelClass, 'find'], []), key($onKeys), $this->{current($onKeys)});
    }

    /**
     * Get has many relation.
     *
     * @param string $modelClass
     * @param array $onKeys
     * @return Relation
     */
    public function hasMany(string $modelClass, array $onKeys): Relation
    {
        return new Relation(
            forward_static_call_array([$modelClass, 'find'], []),
            key($onKeys),
            $this->{current($onKeys)},
            true
        );
    }
}
