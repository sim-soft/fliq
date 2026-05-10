<?php

namespace Simsoft\DB\MySQL\Traits;

/**
 * Binds trait
 */
trait Binds
{
    /** @var array Bind values */
    private array $binds = [];

    /**
     * Append values to binds.
     *
     * @param mixed $value The values to be appended.
     * @return void
     */
    public function appendBinds(mixed $value): void
    {
        if (is_array($value)) {
            $this->binds = [...$this->binds, ...$value];
        } else {
            $this->binds[] = $value;
        }
    }

    /**
     * Get bound values
     *
     * @return array|null
     */
    public function getBinds(): ?array
    {
        return $this->binds ?: null;
    }

    /**
     * Clear binds.
     *
     * @return void
     */
    public function clearBinds(): void
    {
        $this->binds = [];
    }
}
