<?php

namespace Simsoft\DB\Drivers;

use InvalidArgumentException;
use Simsoft\DB\Exceptions\ConnectionException;
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
     * @var int Current transaction nesting depth. 0 means no active transaction.
     */
    private int $transactionLevel = 0;

    /**
     * Begin a real database transaction.
     *
     * @return void
     */
    abstract protected function beginTransaction(): void;

    /**
     * Commit the real database transaction.
     *
     * @return bool
     */
    abstract protected function commitTransaction(): bool;

    /**
     * Roll back the real database transaction.
     *
     * @return void
     */
    abstract protected function rollBackTransaction(): void;

    /**
     * Execute a bare SQL statement with no bindings.
     *
     * Used for savepoint control statements, whose names are generated
     * internally and never derived from user input.
     *
     * @param string $sql The statement to run.
     * @return void
     */
    abstract protected function executeRawStatement(string $sql): void;

    /**
     * Perform query transaction.
     *
     * The callback MUST return a bool value. Returning TRUE commits the
     * transaction; anything else rolls it back. A thrown exception rolls back
     * and is re-thrown.
     *
     * Nested calls are supported: only the outermost call opens a real
     * transaction, while inner calls use savepoints. An inner rollback undoes
     * just its own work, and an outer rollback still undoes everything —
     * including work an inner call committed.
     *
     * @param callable $callback The callback to be executed.
     * @return bool
     */
    public function transaction(callable $callback): bool
    {
        $this->enterTransaction();

        try {
            if ($callback() === true) {
                return $this->leaveTransactionWithCommit();
            }
        } catch (\Throwable $throwable) {
            $this->leaveTransactionWithRollBack();
            throw $throwable;
        }

        $this->leaveTransactionWithRollBack();
        return false;
    }

    /**
     * Get the current transaction nesting depth.
     *
     * @return int Zero when no transaction is active.
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Forget any tracked transaction state.
     *
     * A dropped connection discards its transaction, so drivers call this from
     * connect() to avoid carrying a stale depth across a reconnect.
     *
     * @return void
     */
    protected function resetTransactionLevel(): void
    {
        $this->transactionLevel = 0;
    }

    /**
     * Determine whether a lost connection may be silently re-established.
     *
     * Reconnecting inside a transaction would discard every statement written
     * so far and let the remaining ones commit on their own, turning an atomic
     * block into a partial write. Failing loudly is the only safe option.
     *
     * @return void
     * @throws ConnectionException If a transaction is currently open.
     */
    protected function guardReconnectDuringTransaction(): void
    {
        if ($this->transactionLevel > 0) {
            $this->resetTransactionLevel();

            throw new ConnectionException(
                'The database connection was lost during a transaction. '
                . 'Any work in that transaction has been rolled back by the server.'
            );
        }
    }

    /**
     * Open a transaction, or a savepoint when one is already open.
     *
     * @return void
     */
    private function enterTransaction(): void
    {
        if ($this->transactionLevel > 0) {
            $this->executeRawStatement('SAVEPOINT ' . $this->savepointName($this->transactionLevel + 1));
            ++$this->transactionLevel;

            return;
        }

        $this->beginTransaction();
        $this->transactionLevel = 1;
    }

    /**
     * Commit the transaction, or release the savepoint when nested.
     *
     * @return bool
     */
    private function leaveTransactionWithCommit(): bool
    {
        if ($this->transactionLevel > 1) {
            try {
                $this->executeRawStatement('RELEASE SAVEPOINT ' . $this->savepointName($this->transactionLevel));
            } finally {
                --$this->transactionLevel;
            }

            return true;
        }

        try {
            return $this->commitTransaction();
        } finally {
            $this->transactionLevel = 0;
        }
    }

    /**
     * Roll back the transaction, or to the savepoint when nested.
     *
     * @return void
     */
    private function leaveTransactionWithRollBack(): void
    {
        if ($this->transactionLevel > 1) {
            try {
                $this->executeRawStatement('ROLLBACK TO SAVEPOINT ' . $this->savepointName($this->transactionLevel));
            } finally {
                --$this->transactionLevel;
            }

            return;
        }

        try {
            $this->rollBackTransaction();
        } finally {
            $this->transactionLevel = 0;
        }
    }

    /**
     * Build the savepoint name for a nesting depth.
     *
     * @param int $level The nesting depth.
     * @return string
     */
    private function savepointName(int $level): string
    {
        return 'fliq_sp_' . $level;
    }

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
