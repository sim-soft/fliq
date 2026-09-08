<?php

namespace Simsoft\DB\Traits;

/**
 * LowPriority trait
 *
 * Used only by the write builders, which take supportsModifiers() from
 * {@see Qualifier} via Builder.
 */
trait LowPriority
{
    /** @var bool Enable LOW PRIORITY modifier. Default: false. */
    protected bool $lowPriority = false;

    /**
     * Enable LOW PRIORITY modifier.
     *
     * @return static
     */
    public function lowPriority(): static
    {
        $this->lowPriority = true;
        $this->invalidateSQL();
        return $this;
    }

    /**
     * Get LOW PRIORITY modifier SQL statement.
     *
     * LOW_PRIORITY is a MySQL keyword. It is a scheduling hint, so dropping it
     * elsewhere changes nothing about what the statement does — where emitting
     * it made PostgreSQL read LOW_PRIORITY as the table being updated.
     *
     * @return string|null
     */
    protected function lowPriorityModifier(): ?string
    {
        return $this->lowPriority && $this->supportsModifiers() ? 'LOW_PRIORITY' : null;
    }
}
