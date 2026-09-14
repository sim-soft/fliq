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
     * Merge user PDO options over the driver's defaults, keeping error mode.
     *
     * `options` is documented as overriding the defaults, and every default may
     * be overridden except one. PDO::ATTR_ERRMODE governs whether a failure is
     * raised or swallowed, and all three PDO-backed drivers are written for the
     * exception form: prepare() is typed to return a statement, execute()'s
     * bool means "the statement ran", and Execute::execute() turns a Throwable
     * into QueryException. Setting PDO::ERRMODE_SILENT does not make the
     * library quieter, it makes it wrong — prepare() starts returning false,
     * which surfaced as `TypeError: prepareStatement(): Return value must be of
     * type PDOStatement, false returned` from a config the documentation
     * invited. A silent driver cannot report anything, so this one attribute is
     * pinned.
     *
     * @param array<int, mixed> $defaults The driver's own options.
     * @param mixed $userOptions The `options` config value, whatever its shape.
     * @return array<int, mixed>
     */
    protected function mergePdoOptions(array $defaults, mixed $userOptions): array
    {
        $options = array_replace($defaults, (array)$userOptions);
        $options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;

        return $options;
    }

    /**
     * Reset the state a new connection does not inherit.
     *
     * Called by every connect() before it opens anything. Clearing the errors
     * is what makes a recovered driver able to say it recovered: they only ever
     * accumulated, so a driver that failed once and then succeeded still
     * reported the stale failure through hasError() forever after.
     *
     * @return void
     */
    protected function prepareConnect(): void
    {
        // A new connection carries no transaction, whatever the old one had.
        $this->resetTransactionLevel();

        // Until the new connection is established there is nothing to vouch for.
        $this->clearActivity();

        // Whatever went wrong last time is not this connection's failure.
        $this->clearErrors();
    }

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
     * Check if the connection is still alive.
     *
     * Four copies of this had drifted apart in three different ways: one forgot
     * to record the activity and so re-pinged forever, and one left the probe's
     * result set unread, which on MySQL keeps the connection busy and makes the
     * next prepared statement fail — the liveness check breaking the connection
     * it had just vouched for. Only the probe itself differs per driver.
     *
     * @return bool
     */
    public function ping(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        try {
            if (!$this->probeLiveness()) {
                return false;
            }

            $this->markActivity();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Send a trivial statement and consume its result.
     *
     * Implementations must read the result set to completion: an unread one
     * leaves the connection unusable for the statement that follows.
     *
     * @return bool False if the probe reported failure without throwing.
     */
    abstract protected function probeLiveness(): bool;

    /**
     * Re-establish the connection if it has been lost.
     *
     * Checks liveness only once the connection has sat idle long enough for
     * needsLivenessCheck() to say so, then reconnects if the ping fails.
     *
     * Three drivers carried a byte-identical copy of this and the fourth had
     * none, which is why it lived below transaction() rather than above it: a
     * method defined on the subclasses cannot be called from the base class
     * that needs it. Hoisting it is what lets enterTransaction() ask.
     *
     * @return void
     * @throws ConnectionException If the connection died inside a transaction.
     */
    public function reconnectIfNeeded(): void
    {
        if ($this->isConnected() && !$this->needsLivenessCheck()) {
            return;
        }

        $this->reconnectIfDead();
    }

    /**
     * Check liveness now, whatever the idle window says, and reconnect if gone.
     *
     * @return void
     * @throws ConnectionException If the connection died inside a transaction.
     */
    private function reconnectIfDead(): void
    {
        if (!$this->isConnected() || !$this->ping()) {
            $this->guardReconnectDuringTransaction();
            $this->forceReconnect();
        }
    }

    /**
     * Whether a connection handle is currently held.
     *
     * The handle itself is typed per driver — mysqli for one, PDO for three —
     * so the base class asks rather than reads.
     *
     * @return bool
     */
    abstract protected function isConnected(): bool;

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
     * @throws ConnectionException If the new connection could not be opened.
     */
    abstract protected function forceReconnect(): void;

    /**
     * Fail loudly when a reconnect attempt did not produce a connection.
     *
     * connect() records its failure as an error string rather than throwing,
     * because the constructor path wants to collect it — Connection::get()
     * reads hasError() and raises ConnectionException itself. Nothing read it
     * on the reconnect path, so a failed reconnect returned normally and the
     * caller went on to use a driver that had no connection. What they got
     * depended on the driver: PDO threw RuntimeException('Database connection
     * failed') from deep inside the next statement, while MySQLi left a
     * half-built handle behind and threw Error('mysqli object is not fully
     * initialized') — a PHP Error, which callers catching Exception do not
     * catch at all. Both are two frames removed from the real cause, which is
     * that the server is unreachable.
     *
     * Drivers call this at the end of forceReconnect() so the failure surfaces
     * where it happened, as the ConnectionException the documentation already
     * tells callers to expect from a lost connection.
     *
     * @return void
     * @throws ConnectionException If no connection was established.
     */
    protected function assertReconnected(): void
    {
        if ($this->isConnected() && $this->noError()) {
            return;
        }

        $errors = $this->getErrors();
        $reason = $errors === [] ? 'the connection could not be re-established' : end($errors);

        throw new ConnectionException(
            'The database connection was lost and could not be re-established: ' . $reason
        );
    }

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

        // execute() and query() get both recovery routes — the idle ping and
        // the statement-level retry — and opening a transaction got neither, so
        // a worker whose connection had dropped recovered when its next
        // statement was a query and failed when it was a transaction, which is
        // the case where losing the write matters most.
        //
        // The ping is unconditional here rather than subject to the idle
        // window, because neither route works on its own for this statement.
        // mysqli::begin_transaction() does not round trip, so it cannot fail
        // and there is nothing for a retry to catch: the drop is discovered by
        // the first statement inside the block, by which time the level is 1
        // and guardReconnectDuringTransaction() is obliged to refuse. Only
        // asking before opening finds it while reconnecting is still allowed.
        //
        // A wasted round trip per transaction is the cost, against a block
        // whose statements will each pay one anyway.
        $this->reconnectIfDead();

        // PDO's begin_transaction does round trip, and can therefore still fail
        // on a connection that died between the ping and this line. Retrying is
        // safe while the level is 0 — that is reconnecting to open a
        // transaction rather than inside one.
        $this->runWithReconnect(function (): void {
            $this->beginTransaction();
        });

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
     * Answers false when the connection has no insert id to report, either
     * because nothing has been inserted on it or because the statement that
     * ran generated no key.
     *
     * @return false|string
     */
    abstract public function lastInsertId(): false|string;

    /**
     * Normalise a PDO insert id to the contract above.
     *
     * PDO::lastInsertId() reports "no id" as the string "0" — for MySQL and
     * SQLite directly, and for PostgreSQL by throwing, since it calls lastval()
     * and the server raises "lastval is not yet defined in this session" until
     * some sequence has been used. Neither is an id: no auto-increment column
     * or sequence produces 0, and a PostgreSQL insert into a table without a
     * sequence (a composite-key join table, say) never defines lastval at all.
     *
     * The three PDO-backed drivers passed both straight through, so the same
     * "nothing was inserted" fact reached callers three different ways: '0'
     * from MySQL and SQLite, an uncaught PDOException from PostgreSQL, and
     * false from MySQLi. Execute::getLastInsertId() maps only false to null, so
     * '0' surfaced as the string id "0" and the PostgreSQL case escaped as an
     * exception from a method typed ?string.
     *
     * @param callable(): (string|false) $read Reads the driver's raw insert id.
     * @return false|string
     */
    protected function normalizeInsertId(callable $read): false|string
    {
        try {
            $id = $read();
        } catch (\Throwable) {
            return false;
        }

        return ($id === false || $id === '' || $id === '0') ? false : $id;
    }

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Database config used to establish connection.
     * @throws InvalidArgumentException If required config keys are missing.
     */
    public function __construct(
        protected array $config
    ) {
        $this->config = [...$this->default, ...$this->config];
        $this->validate();
        $this->connect();
    }

    /**
     * Validate config.
     *
     * A required key set to null is rejected as well as an absent one, and it
     * has to be: a null `host` connects to localhost and a null `database`
     * selects none, both without complaint, so a config typo that nulls one
     * reaches a server — just not the intended one. What the driver cannot do
     * is call such a key missing. It is present, and saying otherwise sends
     * the reader looking for a line that is already in front of them.
     *
     * @return void
     * @throws InvalidArgumentException If required config keys are missing or null.
     */
    private function validate(): void
    {
        $missing = [];
        $null = [];
        foreach ($this->required as $key) {
            if (!array_key_exists($key, $this->config)) {
                $missing[] = $key;
            } elseif ($this->config[$key] === null) {
                $null[] = $key;
            }
        }

        $problems = array_filter([
            $missing === [] ? '' : 'missing required config keys: ' . implode(', ', $missing),
            $null === [] ? '' : 'required config keys set to null: ' . implode(', ', $null),
        ]);

        if ($problems) {
            throw new InvalidArgumentException('Database: ' . implode('; ', $problems) . '.');
        }
    }

    /**
     * Refuse to be serialized.
     *
     * There was a __wakeup() here that reconnected on deserialization, and it
     * could only ever run for one driver in four: PDO refuses to be serialized,
     * so the three PDO-backed drivers died with `Serialization of 'PDO' is not
     * allowed` — an exception naming a class the caller had not mentioned,
     * thrown from a driver that advertised the opposite. Only MySQLi round
     * tripped, and what it wrote out was the config: host, username and
     * password, in plaintext, into whatever the payload was being stored in.
     *
     * A driver is a live connection and the credentials to open it. Neither
     * survives a serialize/unserialize boundary usefully, and the credentials
     * should not cross one at all. Refusing uniformly, and saying why, is more
     * use than a feature that works for a quarter of callers and leaks for
     * them. Register the connection again on the other side instead — that is
     * what Connection::add() is for, and it reads the password from wherever
     * you keep it rather than from a cache entry.
     *
     * @return array<string, mixed>
     * @throws \LogicException Always.
     */
    public function __serialize(): array
    {
        throw new \LogicException(
            static::class . ' cannot be serialized: it holds a live connection and the credentials '
            . 'that opened it. Register the connection with Connection::add() where it is needed.'
        );
    }
}
