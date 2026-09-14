<?php

namespace Simsoft\DB\Exceptions;

use RuntimeException;
use Simsoft\DB\Interfaces\Executable;

/**
 * QueryException class.
 *
 * Thrown when a database query fails to execute.
 *
 * The failing SQL is carried on the exception but kept out of `getMessage()`,
 * because that message is what ends up in logs, error pages and third-party
 * error trackers. SQL text names your tables and columns and often embeds
 * literals, which is a map of the schema handed to whoever reads it. Call
 * {@see getSql()} and {@see getBinds()} to inspect the query deliberately.
 *
 * Note the driver's own error text is passed through as-is, and it may well
 * name the table or column it failed on ("Table 'app.user' doesn't exist").
 * Withholding the statement narrows what leaks; it does not make an exception
 * message safe to show a user. Log it, show a generic error.
 *
 * During development, `QueryException::enableDebug()` appends the SQL to the
 * message so it shows up in stack traces. Leave it off in production.
 */
class QueryException extends RuntimeException
{
    /** @var bool Whether to append the failing SQL to the exception message */
    private static bool $debug = false;

    /**
     * Include the failing SQL in exception messages.
     *
     * Intended for local development. Enabling this puts SQL wherever the
     * message is written, including logs and any error tracker you use.
     *
     * @return void
     */
    public static function enableDebug(): void
    {
        self::$debug = true;
    }

    /**
     * Keep the failing SQL out of exception messages.
     *
     * The default, and what production should run with.
     *
     * @return void
     */
    public static function disableDebug(): void
    {
        self::$debug = false;
    }

    /**
     * Check whether SQL is currently included in exception messages.
     *
     * @return bool
     */
    public static function isDebug(): bool
    {
        return self::$debug;
    }

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
    ) {
        $fullMessage = $message;
        if ($sql !== '' && self::$debug) {
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
     * Bind values are the data that was being written or matched, so treat
     * them as you would the rows themselves — they can hold credentials,
     * tokens or personal data. Avoid logging them wholesale.
     *
     * @return array<int, mixed>|null
     */
    public function getBinds(): ?array
    {
        return $this->binds;
    }
}
