<?php

namespace Simsoft\DB\MySQL\Interfaces;

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
     * @return array|null
     */
    public function getBinds(): ?array;
}
