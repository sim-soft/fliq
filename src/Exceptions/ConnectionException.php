<?php

namespace Simsoft\DB\Exceptions;

use RuntimeException;

/**
 * ConnectionException class.
 *
 * Thrown when a database connection cannot be established or is not found.
 */
class ConnectionException extends RuntimeException
{
    /**
     * Create for a missing connection.
     *
     * @param string $name The connection name that was not found.
     * @return self
     */
    public static function notFound(string $name): self
    {
        return new self("Database connection '$name' not found.");
    }

    /**
     * Create for a failed connection.
     *
     * @param string $name The connection name.
     * @param string $reason The failure reason.
     * @return self
     */
    public static function failed(string $name, string $reason): self
    {
        return new self("Connection '$name' failed: $reason");
    }
}
