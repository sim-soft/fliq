<?php

namespace Simsoft\DB\Exceptions;

use RuntimeException;
use Simsoft\DB\Interfaces\Executable;

/**
 * QueryException class.
 *
 * Thrown when a database query fails to execute.
 */
class QueryException extends RuntimeException
{
    /**
     * Constructor.
     *
     * @param string $message The error message.
     * @param string $sql The SQL that failed.
     * @param array<int, mixed>|null $binds The bind values.
     * @param int $code The error code.
     * @param \Throwable|null $previous The previous exception (for chaining).
     */
    public function __construct(
        string $message,
        protected string $sql = '',
        protected ?array $binds = null,
        int $code = 0,
        ?\Throwable $previous = null
    )
    {
        $fullMessage = $message;
        if ($sql !== '') {
            $fullMessage .= " [SQL: $sql]";
        }

        parent::__construct($fullMessage, $code, $previous);
    }

    /**
     * Create from an executable query.
     *
     * @param string $message The error message.
     * @param Executable $query The query that failed.
     * @return self
     */
    public static function fromQuery(string $message, Executable $query): self
    {
        return new self($message, $query->getSQL(), $query->getBinds());
    }

    /**
     * Get the SQL that caused the exception.
     *
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get the bind values.
     *
     * @return array<int, mixed>|null
     */
    public function getBinds(): ?array
    {
        return $this->binds;
    }
}
