<?php

namespace Simsoft\DB\Traits;

/**
 * Ignore modifier trait.
 *
 * Used only by the write builders, which take supportsModifiers() from
 * {@see Qualifier} via Builder.
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
     * IGNORE is a MySQL keyword. On PostgreSQL "UPDATE IGNORE \"user\"" is not
     * rejected — it parses as an update of a table named ignore aliased "user",
     * so where such a table exists the statement runs and writes to the wrong
     * one. Omitting the keyword loses only the tolerance of errors it asks for,
     * against an engine that was never going to provide it.
     *
     * @return string|null
     */
    protected function ignoreModifier(): ?string
    {
        return $this->ignore && $this->supportsModifiers() ? 'IGNORE' : null;
    }
}
