<?php

namespace Simsoft\DB\MySQL\Traits;

/**
 * Ignore modifier trait.
 */
trait Ignore
{
    /** @var bool Enable IGNORE modifier. Default: false. */
    protected bool $ignore = false;

    /**
     * Enable IGNORE modifier
     */
    public function ignore(): self
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
