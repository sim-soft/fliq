<?php

namespace Simsoft\DB\Traits;

/**
 * Debug Trait.
 */
trait Debug
{
    /** @var bool Enable debug mode. default: false */
    protected bool $debugMode = false;

    /**
     * Enable debug mode.
     *
     * @return static
     */
    public function enableDebug(): static
    {
        $this->debugMode = true;
        return $this;
    }

    /**
     * Disable debug mode.
     *
     * @return static
     */
    public function disableDebug(): static
    {
        $this->debugMode = false;
        return $this;
    }

    /**
     * Determine if debug mode is enabled.
     *
     * @return bool
     */
    public function isDebugEnabled(): bool
    {
        return $this->debugMode;
    }
}
