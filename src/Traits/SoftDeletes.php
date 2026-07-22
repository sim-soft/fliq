<?php

namespace Simsoft\DB\Traits;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Update;

/**
 * SoftDeletes trait.
 *
 * Adds soft delete functionality to a Model. Instead of permanently removing
 * records, marks them with a timestamp in the `deleted_at` column.
 *
 * Usage:
 *   class User extends Model {
 *       use SoftDeletes;
 *   }
 *
 * The soft delete scope is applied automatically by Model::find().
 * Works with custom query classes (scope approach) without any extra configuration.
 */
trait SoftDeletes
{
    /**
     * Get the soft delete column name.
     *
     * Override this method to use a different column name.
     *
     * @return string
     */
    public function getDeletedAtColumn(): string
    {
        return 'deleted_at';
    }

    /**
     * Determine if the model has been softly deleted.
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return $this->{$this->getDeletedAtColumn()} !== null;
    }

    /**
     * Apply the soft delete scope to a query.
     *
     * Adds WHERE deleted_at IS NULL condition.
     *
     * @param ActiveQuery $query The query to scope.
     * @return ActiveQuery
     */
    public function softDeleteScope(ActiveQuery $query): ActiveQuery
    {
        return $query->isNull($this->getDeletedAtColumn());
    }

    /**
     * Soft delete the record (set deleted_at timestamp).
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

        $column = $this->getDeletedAtColumn();
        $timestamp = date('Y-m-d H:i:s');

        $result = (new Update(
            $this->getTable(),
            [$column => $timestamp],
            static::withTrashed()->where($this->getPKs())
        ))
            ->withConnection($this->getConnectionName())
            ->execute();

        if ($result) {
            $this->{$column} = $timestamp;
            $this->fireEvent('deleted');
        }

        return $result;
    }

    /**
     * Permanently delete the record from the database.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        return (new Delete(
            $this->getTable(),
            static::withTrashed()->where($this->getPKs())
        ))
            ->withConnection($this->getConnectionName())
            ->execute();
    }

    /**
     * Restore a soft-deleted record.
     *
     * @return bool
     */
    public function restore(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $column = $this->getDeletedAtColumn();

        $result = (new Update(
            $this->getTable(),
            [$column => null],
            static::withTrashed()->where($this->getPKs())
        ))
            ->withConnection($this->getConnectionName())
            ->execute();

        if ($result) {
            $this->{$column} = null;
        }

        return $result;
    }

    /**
     * Get a query that includes soft-deleted records.
     *
     * @return ActiveQuery
     */
    public static function withTrashed(): ActiveQuery
    {
        return new ActiveQuery(static::class, withScopes: false);
    }

    /**
     * Get a query that returns only soft-deleted records.
     *
     * @return ActiveQuery
     */
    public static function onlyTrashed(): ActiveQuery
    {
        $model = new static();
        $query = new ActiveQuery(static::class, withScopes: false);
        $query->notNull($model->getDeletedAtColumn());
        return $query;
    }
}
