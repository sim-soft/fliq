<?php

namespace Simsoft\DB\Traits;

/**
 * Ignore modifier trait.
 */
trait Ignore
{
    /** @var bool Enable IGNORE modifier. Default: false. */
    protected bool $ignore = false;

    /**
     * Enable IGNORE modifier
     *
     * @return static
     */
    public function ignore(): static
    {
        $this->ignore = true;
        return $this;
    }

    /**
     * Get IGNORE modifier SQL statement.
     *
     * @return string|null
     */
    protected function ignoreModifier(): ?string
    {
        return $this->ignore ? 'IGNORE' : null;
    }
}
