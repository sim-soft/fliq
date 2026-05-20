<?php

namespace Simsoft\DB\Interfaces;

/**
 * Executable interface.
 *
 */
interface Executable
{
    /**
     * Get SQL statement.
     *
     * @return string
     */
    public function getSQL(): string;

    /**
     * Get bind values for the SQL statement.
     *
     * @return array<int, mixed>|null
     */
    public function getBinds(): ?array;
}
