<?php

namespace Simsoft\DB\MySQL\Interfaces;

/**
 * Deletable trait.
 */
interface Deletable
{
    /**
     * Get WHERE SQL statement, the statement should start with 'WHERE'.
     *
     * @return string|null Return null if no condition.
     */
    public function getWhereSQL(): ?string;

    /**
     * Get ORDER SQL statement, the statement should start with 'ORDER BY'.
     *
     * @return string|null Return null if order by is not defined..
     */
    public function getOrderSQL(): ?string;

    /**
     * Get LIMIT SQL statement, the statement should start with 'LIMIT'.
     *
     * @return string|null Return null if limit is not defined.
     */
    public function getLimitSQL(): ?string;
}
