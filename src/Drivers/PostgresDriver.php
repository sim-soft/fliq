<?php

namespace Simsoft\DB\Drivers;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Simsoft\DB\Interfaces\CachesStatements;
use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Interfaces\ReturnsRows;

/**
 * PostgreSQL database driver.
 *
 * Connection implementation using PHP PDO with pgsql driver.
 */
class PostgresDriver extends Driver implements CachesStatements
{
    /** @var int Longest usable channel name, from the server's NAMEDATALEN - 1. */
    private const MAX_CHANNEL_BYTES = 63;

    /** @var array<int, string> Required configuration keys */
    protected array $required = ['host', 'database', 'username', 'password'];

    /** @var array<string, mixed> Default configuration values */
    protected array $default = [
        'port' => 5432,
        'charset' => 'utf8',
        'schema' => 'public',
        'persistent' => false,
        'timeout' => 5,
        'statement_cache' => true,
        'statement_cache_size' => 100,
    ];

    /** @var PDO|null The PDO connection instance */
    protected ?PDO $connection = null;

    /** @var array<string, PDOStatement> Prepared statement cache */
    private array $statementCache = [];

    /** @var int Maximum cached statements before eviction */
    private int $maxCacheSize = 100;

    /** @var bool Whether statement caching is enabled */
    private bool $cacheEnabled = true;

    /**
     * {@inheritdoc}
     */
    protected function connect(): void
    {
        $this->prepareConnect();

        try {
            $this->cacheEnabled = (bool)($this->config['statement_cache'] ?? true);
            $this->maxCacheSize = (int)($this->config['statement_cache_size'] ?? 100);

            $dsn = 'pgsql:' . implode(';', [
                'host=' . $this->config['host'],
                'port=' . $this->config['port'],
                'dbname=' . $this->config['database'],
            ]);

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => (int)$this->config['timeout'],
            ];

            if (!empty($this->config['persistent'])) {
                $options[PDO::ATTR_PERSISTENT] = true;
            }

            // User options override defaults, bar the error mode.
            $options = $this->mergePdoOptions($options, $this->config['options'] ?? []);

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );

            // Set charset and search path (validated to prevent injection)
            $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$this->config['charset']);
            $schema = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$this->config['schema']);
            $this->connection->exec("SET NAMES '$charset'");
            $this->connection->exec("SET search_path TO '$schema'");

            $this->markActivity();
        } catch (PDOException $exception) {
            $this->addError($exception->getMessage());
        }
    }

    /**
     * Get the active PDO connection, throwing if null.
     *
     * @return PDO
     * @throws \RuntimeException If the connection is not established.
     */
    private function requireConnection(): PDO
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection failed');
        }
        return $this->connection;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(Executable $query): bool
    {
        $this->reconnectIfNeeded();

        return $this->runWithReconnect(fn(): bool => $this->executeOnce($query));
    }

    /**
     * Run one attempt of execute().
     *
     * @param Executable $query The query to run.
     * @return bool
     */
    private function executeOnce(Executable $query): bool
    {
        $conn = $this->requireConnection();

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        // Any write statement carrying a RETURNING clause. This was written as
        // three identical branches naming Insert, Update and Delete, which is
        // the set of builders that happened to declare the methods rather than
        // the set that can carry the clause — Upsert could not, so its rows were
        // never captured and its caller was left with the connection's
        // session-scoped id instead.
        if ($query instanceof ReturnsRows && $query->hasReturning()) {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($binds ?? []);
            $this->markActivity();
            $query->setReturningResult($stmt->fetchAll());
            return true;
        }

        if ($binds === null) {
            $result = $conn->exec($sql) !== false;
            $this->markActivity();

            return $result;
        }

        $stmt = $this->prepareStatement($sql);
        $result = $stmt->execute($binds);
        $this->markActivity();

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(Executable $query): array
    {
        $this->reconnectIfNeeded();

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        return $this->runWithReconnect(function () use ($sql, $binds): array {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($binds);
            $this->markActivity();

            // A statement with no columns has no rows to give, whatever the
            // driver says. PDO's pgsql driver reports one empty row per
            // affected row for an UPDATE or DELETE, so query() on an UPDATE
            // touching one row answered [[]] — a result set of one row with no
            // columns, which every caller reads as "a row was found". The other
            // three drivers answer [] for the same statement.
            if ($stmt->columnCount() === 0) {
                return [];
            }

            return $stmt->fetchAll();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): false|string
    {
        return $this->normalizeInsertId(fn(): string|false => $this->requireConnection()->lastInsertId());
    }

    /**
     * {@inheritdoc}
     */
    protected function beginTransaction(): void
    {
        $this->requireConnection()->beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction(): bool
    {
        return $this->requireConnection()->commit();
    }

    /**
     * {@inheritdoc}
     */
    protected function rollBackTransaction(): void
    {
        $this->requireConnection()->rollBack();
    }

    /**
     * {@inheritdoc}
     */
    protected function executeRawStatement(string $sql): void
    {
        $this->requireConnection()->exec($sql);
    }

    /**
     * {@inheritdoc}
     */
    protected function probeLiveness(): bool
    {
        $stmt = $this->requireConnection()->query('SELECT 1');
        if ($stmt === false) {
            return false;
        }

        $stmt->fetchAll();
        $stmt->closeCursor();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Simsoft\DB\Exceptions\ConnectionException If the server is still unreachable.
     */
    protected function forceReconnect(): void
    {
        // Statements are bound to the connection that prepared them.
        $this->statementCache = [];
        $this->connection = null;
        $this->connect();
        $this->assertReconnected();
    }

    /**
     * Get or create a cached prepared statement.
     *
     * When caching is disabled, always creates a fresh statement.
     *
     * @param string $sql The SQL to prepare.
     * @return PDOStatement
     */
    private function prepareStatement(string $sql): PDOStatement
    {
        $conn = $this->requireConnection();

        if (!$this->cacheEnabled) {
            return $conn->prepare($sql);
        }

        if (isset($this->statementCache[$sql])) {
            return $this->statementCache[$sql];
        }

        if (count($this->statementCache) >= $this->maxCacheSize) {
            array_shift($this->statementCache);
        }

        $stmt = $conn->prepare($sql);
        $this->statementCache[$sql] = $stmt;

        return $stmt;
    }

    /**
     * Get the underlying PDO connection.
     *
     * @return PDO|null
     */
    public function getPdo(): ?PDO
    {
        return $this->connection;
    }

    /**
     * Clear the prepared statement cache.
     *
     * @return void
     */
    public function clearStatementCache(): void
    {
        $this->statementCache = [];
    }

    /**
     * Enable statement caching.
     *
     * @return void
     */
    public function enableStatementCache(): void
    {
        $this->cacheEnabled = true;
    }

    /**
     * Disable statement caching and clear existing cache.
     *
     * @return void
     */
    public function disableStatementCache(): void
    {
        $this->cacheEnabled = false;
        $this->statementCache = [];
    }

    /**
     * Check if statement caching is enabled.
     *
     * @return bool
     */
    public function isStatementCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Set the maximum statement cache size.
     *
     * @param int $size Maximum number of cached statements.
     * @return void
     */
    public function setStatementCacheSize(int $size): void
    {
        $this->maxCacheSize = $size;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->statementCache = [];
        $this->connection = null;
    }

    // ------------------------------------------------------------------
    // ADVISORY LOCKS (PostgreSQL-specific)
    // ------------------------------------------------------------------

    /**
     * Acquire a session-level advisory lock (blocking).
     *
     * The lock is held until explicitly released or the session ends.
     *
     * @param int $key The lock key (bigint).
     * @return bool True if the lock was acquired.
     */
    public function advisoryLock(int $key): bool
    {
        $conn = $this->requireConnection();
        $stmt = $conn->query("SELECT pg_advisory_lock($key)");
        return $stmt !== false;
    }

    /**
     * Try to acquire a session-level advisory lock (non-blocking).
     *
     * Returns immediately with true/false instead of waiting.
     *
     * @param int $key The lock key (bigint).
     * @return bool True if the lock was acquired, false if already held by another session.
     * @phpstan-impure Acquires a server-side lock; the result depends on other sessions.
     */
    public function advisoryLockTry(int $key): bool
    {
        $conn = $this->requireConnection();
        $stmt = $conn->query("SELECT pg_try_advisory_lock($key) AS acquired");
        if ($stmt === false) {
            return false;
        }
        $row = $stmt->fetch();
        return $row !== false && ($row['acquired'] === true || $row['acquired'] === 't');
    }

    /**
     * Release a session-level advisory lock.
     *
     * @param int $key The lock key (bigint).
     * @return bool True if the lock was released (false if it was not held).
     * @phpstan-impure Releases a server-side lock; session locks are counted, so
     *                 repeated calls return different results.
     */
    public function advisoryUnlock(int $key): bool
    {
        $conn = $this->requireConnection();
        $stmt = $conn->query("SELECT pg_advisory_unlock($key) AS released");
        if ($stmt === false) {
            return false;
        }
        $row = $stmt->fetch();
        return $row !== false && ($row['released'] === true || $row['released'] === 't');
    }

    /**
     * Acquire a transaction-level advisory lock (blocking).
     *
     * The lock is automatically released at the end of the current transaction.
     *
     * @param int $key The lock key (bigint).
     * @return bool True if the lock was acquired.
     */
    public function advisoryLockTransaction(int $key): bool
    {
        $conn = $this->requireConnection();
        $stmt = $conn->query("SELECT pg_advisory_xact_lock($key)");
        return $stmt !== false;
    }

    /**
     * Try to acquire a transaction-level advisory lock (non-blocking).
     *
     * @param int $key The lock key (bigint).
     * @return bool True if the lock was acquired.
     * @phpstan-impure Acquires a server-side lock; the result depends on other sessions.
     */
    public function advisoryLockTransactionTry(int $key): bool
    {
        $conn = $this->requireConnection();
        $stmt = $conn->query("SELECT pg_try_advisory_xact_lock($key) AS acquired");
        if ($stmt === false) {
            return false;
        }
        $row = $stmt->fetch();
        return $row !== false && ($row['acquired'] === true || $row['acquired'] === 't');
    }

    // ------------------------------------------------------------------
    // LISTEN / NOTIFY (PostgreSQL-specific)
    // ------------------------------------------------------------------

    /**
     * Subscribe to a notification channel.
     *
     * After calling listen(), use getNotification() to check for messages.
     *
     * @param string $channel The channel name (max 63 bytes).
     * @return bool
     * @throws InvalidArgumentException If the channel name is empty or too long.
     */
    public function listen(string $channel): bool
    {
        $conn = $this->requireConnection();
        return $conn->exec('LISTEN ' . $this->quoteChannel($channel)) !== false;
    }

    /**
     * Unsubscribe from a notification channel.
     *
     * @param string $channel The channel name (max 63 bytes).
     * @return bool
     * @throws InvalidArgumentException If the channel name is empty or too long.
     */
    public function unlisten(string $channel): bool
    {
        $conn = $this->requireConnection();
        return $conn->exec('UNLISTEN ' . $this->quoteChannel($channel)) !== false;
    }

    /**
     * Send a notification to a channel.
     *
     * @param string $channel The channel name (max 63 bytes).
     * @param string $payload The notification payload (max 8000 bytes).
     * @return bool
     * @throws InvalidArgumentException If the channel name is empty or too long.
     */
    public function notify(string $channel, string $payload = ''): bool
    {
        $conn = $this->requireConnection();

        // Validated for its own sake, so an unusable name is rejected the same
        // way whichever of the three methods is called first.
        $this->quoteChannel($channel);

        // pg_notify() takes the channel as a bound string, so it needs no
        // quoting and is matched literally. An empty payload used to take a
        // `NOTIFY $channel` branch instead, where the unquoted identifier was
        // folded to lowercase — so notify('MixedCase') and
        // notify('MixedCase', 'data') published to two different channels.
        $stmt = $conn->prepare('SELECT pg_notify(?, ?)');
        return $stmt->execute([$channel, $payload]);
    }

    /**
     * Quote a channel name for use in LISTEN / UNLISTEN.
     *
     * The channel cannot be bound in these statements, so it is quoted as an
     * identifier, doubling any embedded quote exactly as
     * PostgresGrammar::quoteIdentifier() does. Stripping the unsafe characters
     * instead — the previous behaviour — silently changed which channel was
     * addressed: 'order-created' became 'ordercreated', so a notification the
     * database published under the real name was never delivered, and two
     * distinct names ('user-1' and 'user1') collapsed onto one another.
     *
     * Quoting also makes the name case-sensitive, matching pg_notify() in
     * notify(); an unquoted identifier would be folded to lowercase.
     *
     * The length is checked here rather than left to the server, which
     * truncates a long identifier to NAMEDATALEN - 1 without complaint: a
     * 70-byte name would subscribe to its 63-byte prefix while pg_notify() in
     * notify() rejects the same string outright, so the pair could never round
     * trip and two names differing only past byte 63 would collapse.
     *
     * @param string $channel The channel name.
     * @return string The quoted identifier.
     * @throws InvalidArgumentException If the channel name is empty or too long.
     */
    private function quoteChannel(string $channel): string
    {
        if ($channel === '') {
            throw new InvalidArgumentException('Notification channel name cannot be empty.');
        }

        if (strlen($channel) > self::MAX_CHANNEL_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Notification channel name exceeds %d bytes: %d given.',
                self::MAX_CHANNEL_BYTES,
                strlen($channel)
            ));
        }

        return '"' . str_replace('"', '""', $channel) . '"';
    }

    /**
     * Check for a pending notification (non-blocking).
     *
     * Returns null if no notification is available.
     * Requires a prior listen() call on the channel.
     *
     * @param int $timeoutMs Timeout in milliseconds. 0 = non-blocking poll.
     * @return array{channel: string, payload: string, pid: int}|null
     * @phpstan-impure Consumes the notification queue, so each call sees different data.
     */
    public function getNotification(int $timeoutMs = 0): ?array
    {
        $conn = $this->requireConnection();

        $pgsql = $conn->pgsqlGetNotify(PDO::FETCH_ASSOC, $timeoutMs);

        if ($pgsql === false) {
            return null;
        }

        return [
            'channel' => (string)($pgsql['message'] ?? ''),
            'payload' => (string)($pgsql['payload'] ?? ''),
            'pid' => (int)($pgsql['pid'] ?? 0),
        ];
    }
}
