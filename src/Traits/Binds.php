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
     * A null here is a value, and binds one SQL NULL — which is how
     * `update(['token' => null])` clears a column. To absorb the binds of
     * another expression, where null means it has none, use
     * {@see absorbBinds()} instead.
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
     * Absorb the binds of a nested expression.
     *
     * getBinds() answers "none" with null, but appendBinds() reads a null as a
     * value and binds one SQL NULL for it. Passing one straight to the other
     * therefore turned a bindless subquery into a statement holding one bind
     * and no placeholder to put it in, and `IN (SELECT user_id FROM post)` —
     * about as ordinary as a subquery gets — could not execute at all,
     * failing with "Invalid parameter number" from the driver rather than
     * anything naming the cause. Every call site absorbing another
     * expression's binds had to remember to guard, and the ones that forgot
     * were broken; this gives the two meanings separate names so there is
     * nothing left to remember.
     *
     * @param array<int, mixed>|null $binds The nested expression's binds, or null when it has none.
     * @return void
     */
    public function absorbBinds(?array $binds): void
    {
        if ($binds === null) {
            return;
        }

        foreach ($binds as $bind) {
            $this->binds[] = $bind;
        }
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
