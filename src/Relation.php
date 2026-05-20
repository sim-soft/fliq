<?php

namespace Simsoft\DB;

use Simsoft\DB\Builder\ActiveQuery;

/**
 * Relation class.
 *
 * Represents a model relationship (hasOne / hasMany).
 * Supports junction tables via viaTable() and chained relations via via().
 *
 * @method $this select(mixed ...$attributes)
 * @method $this where(string|array<mixed>|callable $attribute, mixed $operator = '=', mixed $value = null, string $logicalOperator = 'AND')
 * @method $this whereRaw(string $statement, ?array<int, mixed> $binds = null)
 * @method $this not(string $attribute, mixed $value)
 * @method $this isNull(string $attribute)
 * @method $this notNull(string $attribute)
 * @method $this in(string $attribute, array<int, mixed>|ActiveQuery $values)
 * @method $this notIn(string $attribute, array<int, mixed>|ActiveQuery $values)
 * @method $this orderBy(string|array<string, string> $attribute, string $direction = 'ASC')
 * @method $this limit(int $max, ?int $offset = null)
 */
class Relation
{
    /** @var string|null Junction table name (for viaTable) */
    protected ?string $viaTable = null;

    /** @var array<string, string>|null Junction table link keys */
    protected ?array $viaLink = null;

    /** @var string|null Via relation name (for via) */
    protected ?string $viaRelation = null;

    /**
     * Constructor.
     *
     * @param ActiveQuery $query The relation query for the related model.
     * @param string $foreignKey The foreign key attribute on the related table.
     * @param int|string|null $localValue The local key value to match.
     * @param bool $multiple Whether this is a hasMany relation.
     * @param string $localKey The local key attribute name on the parent model.
     * @param string $relatedClass The related model class name.
     */
    public function __construct(
        protected ActiveQuery     $query,
        protected string          $foreignKey,
        protected int|string|null $localValue,
        protected bool            $multiple = false,
        protected string          $localKey = 'id',
        protected string          $relatedClass = ''
    )
    {
    }

    /**
     * Specify a junction table for many-to-many relations.
     *
     * @param string $tableName The junction/pivot table name.
     * @param array<string, string> $link ['junction_fk_to_parent' => 'parent_local_key'] mapping.
     * @return static
     */
    public function viaTable(string $tableName, array $link): static
    {
        $this->viaTable = $tableName;
        $this->viaLink = $link;
        return $this;
    }

    /**
     * Specify an intermediate relation for "through" relations.
     *
     * @todo Implement has-through loading in applyConstraints() and EagerLoader.
     *
     * @param string $relationName The intermediate relation method name.
     * @return static
     */
    public function via(string $relationName): static
    {
        $this->viaRelation = $relationName;
        return $this;
    }

    /**
     * Get the foreign key name on the related table.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key name on the parent model.
     *
     * @return string
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /**
     * Get the related model class name.
     *
     * @return string
     */
    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    /**
     * Determine if this is a hasMany relation.
     *
     * @return bool
     */
    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Get the underlying query.
     *
     * @return ActiveQuery
     */
    public function getQuery(): ActiveQuery
    {
        return $this->query;
    }

    /**
     * Get the junction table name (if set).
     *
     * @return string|null
     */
    public function getViaTable(): ?string
    {
        return $this->viaTable;
    }

    /**
     * Get the junction table link keys (if set).
     *
     * @return array<string, string>|null
     */
    public function getViaLink(): ?array
    {
        return $this->viaLink;
    }

    /**
     * Get the via relation name (if set).
     *
     * @return string|null
     */
    public function getViaRelation(): ?string
    {
        return $this->viaRelation;
    }

    /**
     * Fetch the related model(s).
     *
     * @return mixed
     */
    public function fetch(): mixed
    {
        $this->applyConstraints();

        return $this->multiple ? $this->query->get() : $this->query->first();
    }

    /**
     * Apply the relation constraints to the query.
     *
     * @return void
     */
    protected function applyConstraints(): void
    {
        if ($this->localValue === null) {
            return;
        }

        // Via junction table: JOIN pivot and filter
        if ($this->viaTable !== null && $this->viaLink !== null) {
            $junctionFk = (string)key($this->viaLink);
            $this->query->join($this->viaTable, [$this->foreignKey => $this->localKey]);
            $this->query->where("!{$this->viaTable}.$junctionFk", $this->localValue);
            return;
        }

        // Direct relation: WHERE foreign_key = local_value
        $this->query->where($this->foreignKey, $this->localValue);
    }

    /**
     * Proxy method calls to the underlying ActiveQuery.
     *
     * @param string $name Method name.
     * @param array<int, mixed> $arguments Method arguments.
     * @return static
     */
    public function __call(string $name, array $arguments): static
    {
        if (method_exists($this->query, $name)) {
            $this->query->{$name}(...$arguments);
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // Write operations
    // ------------------------------------------------------------------

    /**
     * Save a related model (sets foreign key automatically).
     *
     * Accepts a Model instance or an array of attributes.
     * If the array contains the primary key, the existing record is loaded and updated.
     * If no primary key, a new record is created.
     *
     * @param Model|array<string, mixed> $model The related model or attributes array.
     * @return Model The saved model.
     */
    public function save(Model|array $model): Model
    {
        if (is_array($model)) {
            $model = $this->resolveModelFromArray($model);
        }

        $model->{$this->foreignKey} = $this->localValue;
        $model->save();

        return $model;
    }

    /**
     * Resolve a Model from an array — loads existing if PK is present, creates new otherwise.
     *
     * @param array<string, mixed> $attributes The attributes array.
     * @return Model
     */
    private function resolveModelFromArray(array $attributes): Model
    {
        /** @var Model $relatedModel */
        $relatedModel = new $this->relatedClass();
        $pk = $relatedModel->getPrimaryKeyFields();

        // If array contains the primary key, load and update existing record
        if (is_string($pk) && isset($attributes[$pk])) {
            /** @var Model|null $existing */
            $existing = $this->relatedClass::findByPk($attributes[$pk]);
            if ($existing !== null) {
                unset($attributes[$pk]);
                $existing->fill($attributes);
                return $existing;
            }
        }

        // No PK or record not found → create new
        /** @var Model $instance */
        $instance = new $this->relatedClass();
        $instance->fill($attributes);
        return $instance;
    }

    /**
     * Save multiple related models.
     *
     * Each item can be a Model instance or an array of attributes.
     *
     * @param array<int, Model|array<string, mixed>> $models The models or attribute arrays.
     * @return array<int, Model> The saved models.
     */
    public function saveMany(array $models): array
    {
        $saved = [];
        foreach ($models as $model) {
            $saved[] = $this->save($model);
        }
        return $saved;
    }

    /**
     * Attach IDs to the pivot table (M:N relations only).
     *
     * @param array<int, int|string> $ids The related model IDs to attach.
     * @return bool
     * @throws \RuntimeException If not a viaTable relation.
     */
    public function attach(array $ids): bool
    {
        $this->assertViaTable('attach');

        if (empty($ids)) {
            return true;
        }

        /** @var array<string, string> $viaLink */
        $viaLink = $this->viaLink;
        $junctionFk = (string)key($viaLink);
        $grammar = Connection::grammar($this->query->getConnectionName());
        $table = $grammar->quoteIdentifier((string)$this->viaTable);
        $col1 = $grammar->quoteIdentifier($junctionFk);
        $col2 = $grammar->quoteIdentifier($this->foreignKey);

        $placeholders = [];
        $binds = [];
        foreach ($ids as $id) {
            $placeholders[] = '(?, ?)';
            $binds[] = $this->localValue;
            $binds[] = $id;
        }

        $sql = "INSERT INTO $table ($col1, $col2) VALUES " . implode(', ', $placeholders);
        $raw = new Builder\Raw($sql, $binds);
        $raw->withConnection($this->query->getConnectionName());

        return $raw->execute();
    }

    /**
     * Detach IDs from the pivot table (M:N relations only).
     *
     * @param array<int, int|string>|null $ids IDs to detach. Null detaches all.
     * @return bool
     * @throws \RuntimeException If not a viaTable relation.
     */
    public function detach(?array $ids = null): bool
    {
        $this->assertViaTable('detach');

        /** @var array<string, string> $viaLink */
        $viaLink = $this->viaLink;
        $junctionFk = (string)key($viaLink);
        $grammar = Connection::grammar($this->query->getConnectionName());
        $table = $grammar->quoteIdentifier((string)$this->viaTable);
        $col1 = $grammar->quoteIdentifier($junctionFk);

        if ($ids === null) {
            $sql = "DELETE FROM $table WHERE $col1 = ?";
            $raw = new Builder\Raw($sql, [$this->localValue]);
            $raw->withConnection($this->query->getConnectionName());
            return $raw->execute();
        }

        if (empty($ids)) {
            return true;
        }

        $col2 = $grammar->quoteIdentifier($this->foreignKey);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM $table WHERE $col1 = ? AND $col2 IN ($placeholders)";
        $binds = [$this->localValue, ...$ids];

        $raw = new Builder\Raw($sql, $binds);
        $raw->withConnection($this->query->getConnectionName());

        return $raw->execute();
    }

    /**
     * Sync the pivot table to match exactly the given IDs (M:N relations only).
     *
     * Adds missing IDs, removes extra IDs.
     *
     * @param array<int, int|string> $ids The desired set of related IDs.
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>}
     * @throws \RuntimeException If not a viaTable relation.
     */
    public function sync(array $ids): array
    {
        $this->assertViaTable('sync');

        // Get current attached IDs
        /** @var array<string, string> $viaLink */
        $viaLink = $this->viaLink;
        $junctionFk = (string)key($viaLink);
        $grammar = Connection::grammar($this->query->getConnectionName());
        $table = $grammar->quoteIdentifier((string)$this->viaTable);
        $col1 = $grammar->quoteIdentifier($junctionFk);
        $col2 = $grammar->quoteIdentifier($this->foreignKey);

        $sql = "SELECT $col2 FROM $table WHERE $col1 = ?";
        $raw = new Builder\Raw($sql, [$this->localValue]);
        $raw->withConnection($this->query->getConnectionName());
        $rows = $raw->fetchAll();

        $currentIds = array_map(fn(array $row): int|string => $row[$this->foreignKey], $rows);

        $toAttach = array_values(array_diff($ids, $currentIds));
        $toDetach = array_values(array_diff($currentIds, $ids));

        if (!empty($toDetach)) {
            $this->detach($toDetach);
        }

        if (!empty($toAttach)) {
            $this->attach($toAttach);
        }

        return ['attached' => $toAttach, 'detached' => $toDetach];
    }

    /**
     * Assert that this relation uses a viaTable (M:N).
     *
     * @param string $method The method name for the error message.
     * @return void
     * @throws \RuntimeException If not a viaTable relation.
     */
    private function assertViaTable(string $method): void
    {
        if ($this->viaTable === null || $this->viaLink === null) {
            throw new \RuntimeException("$method() can only be used on viaTable (M:N) relations.");
        }
    }
}
