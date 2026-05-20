<?php

namespace Simsoft\DB\Drivers;

use InvalidArgumentException;
use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Traits\Error;

/**
 * Abstract database driver.
 *
 * Base class for all database driver implementations.
 */
abstract class Driver
{
    use Error;

    /**
     * @var array<int, string> Required config keys
     */
    protected array $required = [];

    /**
     * @var array<string, mixed> Default config keys and values
     */
    protected array $default = [];

    /**
     * Connect to database.
     *
     * @return void
     */
    abstract protected function connect(): void;

    /**
     * Execute SQL statement. Return bool only indicates whether the operation is a success.
     *
     * @param Executable $query The executable object.
     * @return bool
     */
    abstract public function execute(Executable $query): bool;

    /**
     * Execute SQL statement to get query result.
     *
     * @param Executable $query The executable object.
     * @return array<int, array<string, mixed>>
     */
    abstract public function query(Executable $query): array;

    /**
     * Perform query transaction.
     *
     * If callback MUST return a bool value.
     * If callback returned TRUE, the transaction will be committed, else will be rollback.
     *
     * @param callable $callback The callback to be executed.
     * @return bool
     */
    abstract public function transaction(callable $callback): bool;

    /**
     * Get last insert id.
     *
     * @return false|string
     */
    abstract public function lastInsertId(): false|string;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Database config used to establish connection.
     * @throws InvalidArgumentException If required config keys are missing.
     */
    public function __construct(
        protected array $config
    )
    {
        $this->config = [...$this->default, ...$this->config];
        $this->validate();
        $this->connect();
    }

    /**
     * Validate config.
     *
     * @return void
     * @throws InvalidArgumentException If required config keys are missing.
     */
    private function validate(): void
    {
        $missing = [];
        foreach ($this->required as $key) {
            if (!isset($this->config[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            throw new InvalidArgumentException(
                "Database: Missing required config keys: " . implode(', ', $missing)
            );
        }
    }

    /**
     * Reconnect on wakeup (deserialization).
     *
     * @return void
     */
    public function __wakeup(): void
    {
        $this->connect();
    }
}
