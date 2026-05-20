<?php

namespace Simsoft\DB\Traits;

/**
 * Timestamps trait.
 *
 * Automatically manages created_at and updated_at columns on insert/update.
 *
 * Usage:
 *   class User extends Model {
 *       use Timestamps;
 *   }
 *
 * Override getCreatedAtColumn() or getUpdatedAtColumn() to customize column names.
 * Return null from either to disable that timestamp.
 */
trait Timestamps
{
    /**
     * Get the created_at column name.
     *
     * Return null to disable automatic created_at.
     *
     * @return string|null
     */
    public function getCreatedAtColumn(): ?string
    {
        return 'created_at';
    }

    /**
     * Get the updated_at column name.
     *
     * Return null to disable automatic updated_at.
     *
     * @return string|null
     */
    public function getUpdatedAtColumn(): ?string
    {
        return 'updated_at';
    }

    /**
     * Get the current timestamp value.
     *
     * Override to use a different format or source.
     *
     * @return string
     */
    protected function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Hook into beforeSave to set timestamps.
     *
     * @return void
     */
    protected function beforeSave(): void
    {
        $now = $this->freshTimestamp();

        if ($this->isNew()) {
            $createdAt = $this->getCreatedAtColumn();
            if ($createdAt !== null && !isset($this->dirtyAttributes[$createdAt])) {
                $this->{$createdAt} = $now;
            }
        }

        $updatedAt = $this->getUpdatedAtColumn();
        if ($updatedAt !== null && !isset($this->dirtyAttributes[$updatedAt])) {
            $this->{$updatedAt} = $now;
        }
    }
}
