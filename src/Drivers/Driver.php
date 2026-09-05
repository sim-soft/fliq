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
     * How long a connection may sit idle before it is pinged again.
     *
     * Well below the usual server-side idle timeouts (MySQL's `wait_timeout`
     * defaults to 8 hours, PostgreSQL has none by default), but high enough
     * that a busy request never pings.
     */
    private const DEFAULT_PING_IDLE_SECONDS = 30.0;

    /**
     * Message fragments that identify a dropped connection.
     *
     * Covers MySQL (2006/2013), PostgreSQL and the generic PDO wording.
     *
     * @var array<int, string>
     */
    private const LOST_CONNECTION_SIGNATURES = [
        'server has gone away',
        'lost connection',
        'no connection to the server',
        'connection was killed',
        'broken pipe',
        'connection refused',
        'connection reset by peer',
        'connection closed',
        'not connected',
        'terminating connection',
        'server closed the connection unexpectedly',
        'ssl connection has been closed unexpectedly',
    ];

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
     * @var float|null Microtime of the last successful round trip, null if none yet.
     */
    private ?float $lastActivityAt = null;

    /**
     * Record that a round trip to the server just succeeded.
     *
     * @return void
     */
    protected function markActivity(): void
    {
        $this->lastActivityAt = microtime(true);
    }

    /**
     * Forget the recorded activity, forcing the next check to ping.
     *
     * @return void
     */
    protected function clearActivity(): void
    {
        $this->lastActivityAt = null;
    }

    /**
     * Determine whether the connection needs a liveness check.
     *
     * A ping costs a full round trip, which is most of the cost of a small
     * query, so paying it before every statement roughly doubles the work. It
     * only guards against the connection dying *between* our queries, and one
     * that answered a moment ago is still alive for any practical purpose. So
     * the check is skipped while the connection has been used recently, and the
     * ping is paid only after it has sat idle long enough for a server-side
     * timeout or an idle network drop to be plausible.
     *
     * Set `ping_idle_seconds` to 0 to ping before every query.
     *
     * @return bool
     */
    protected function needsLivenessCheck(): bool
    {
        if ($this->lastActivityAt === null) {
            return true;
        }

        $idleThreshold = (float)($this->config['ping_idle_seconds'] ?? self::DEFAULT_PING_IDLE_SECONDS);

        return (microtime(true) - $this->lastActivityAt) >= $idleThreshold;
    }

    /**
     * Run a statement, reconnecting and retrying once if the connection died.
     *
     * Skipping the ping means a connection that dropped while idle is not
     * noticed until the statement itself fails, so that failure is what
     * triggers the reconnect. The retry happens only when the connection is
     * genuinely gone and no transaction is open — inside one, reconnecting
     * would silently discard the statements written so far, so
     * guardReconnectDuringTransaction() throws instead.
     *
     * Retrying is safe because a lost connection takes its uncommitted work
     * with it: the server has already discarded the statement rather than
     * half-applied it.
     *
     * @template T
     * @param callable(): T $statement The statement to run.
     * @return T
     */
    protected function runWithReconnect(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (\Throwable $throwable) {
            if (!$this->isLostConnectionError($throwable)) {
                throw $throwable;
            }

            // Throws if a transaction is open, since a retry cannot restore it.
            $this->guardReconnectDuringTransaction();
            $this->forceReconnect();

            return $statement();
        }
    }

    /**
     * Drop the dead connection and establish a new one.
     *
     * @return void
     */
    abstract protected function forceReconnect(): void;

    /**
     * Determine whether a failure means the connection is gone.
     *
     * Drivers report this as message text rather than a distinct exception
     * type, so the message is what we have to match on.
     *
     * @param \Throwable $throwable The failure to classify.
     * @return bool
     */
    protected function isLostConnectionError(\Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        foreach (self::LOST_CONNECTION_SIGNATURES as $signature) {
            if (str_contains($message, $signature)) {
                return true;
            }
        }

        return false;
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
            } catch (\Throwable $throwable) {
                $this->rethrowUnlessConnectionLost($throwable);
            } finally {
                --$this->transactionLevel;
            }

            return;
        }

        try {
            $this->rollBackTransaction();
        } catch (\Throwable $throwable) {
            $this->rethrowUnlessConnectionLost($throwable);
        } finally {
            $this->transactionLevel = 0;
        }
    }

    /**
     * Suppress a rollback failure caused by the connection being gone.
     *
     * Rolling back needs the connection the transaction lives on, so when that
     * connection is what died the rollback cannot be sent — and does not need
     * to be, since the server discards an interrupted transaction on its own.
     * Letting that failure propagate would replace the real cause of the
     * unwind with a misleading one.
     *
     * @param \Throwable $throwable The rollback failure.
     * @return void
     * @throws \Throwable If the failure was not a lost connection.
     */
    private function rethrowUnlessConnectionLost(\Throwable $throwable): void
    {
        if (!$this->isLostConnectionError($throwable)) {
            throw $throwable;
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
