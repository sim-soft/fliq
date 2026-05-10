<?php

namespace Simsoft\DB\MySQL\Traits;

/**
 * LowPriority trait
 */
trait LowPriority
{
    /** @var bool Enable LOW PRIORITY modifier. Default: false. */
    protected bool $lowPriority = false;

    /**
     * Enable LOW PRIORITY modifier.
     */
    public function lowPriority(): self
    {
        $this->lowPriority = true;
        return $this;
    }

    /**
     * Get LOW PRIORITY modifier SQL statement.
     *
     * @return string|null
     */
    protected function lowPriorityModifier(): ?string
    {
        return $this->lowPriority ? 'LOW_PRIORITY' : null;
    }
}
