<?php

namespace Simsoft\DB\Traits;

/**
 * Binds trait.
 *
 * Manages parameter bind values for prepared statements.
 */
trait Binds
{
    /** @var array<int, mixed> Bind values */
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
            foreach ($value as $val) {
                $this->binds[] = $val;
            }
            return;
        }

        $this->binds[] = $value;
    }

    /**
     * Get bound values.
     *
     * @return array<int, mixed>|null Null when no binds exist.
     */
    public function getBinds(): ?array
    {
        return $this->binds !== [] ? $this->binds : null;
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
