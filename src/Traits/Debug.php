<?php

namespace Simsoft\DB\MySQL\Traits;

/**
 * Debug Trait
 */
trait Debug
{
    /** @var bool Enable debug mode. default: false */
    protected bool $debugMode = false;

    /**
     * Enable debug mode.
     *
     * @param bool $enabled Enable debug mode. Default: true.
     * @return $this
     */
    public function debug(bool $enabled = true): self
    {
        $this->debugMode = $enabled;

        return $this;
    }
}
