<?php

namespace Simsoft\DB\Drivers;

use PDO;
use PDOException;
use PDOStatement;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Interfaces\Executable;

/**
 * PostgreSQL database driver.
 *
 * Connection implementation using PHP PDO with pgsql driver.
 */
class PostgresDriver extends Driver
{
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

            // User options override defaults
            $options = array_replace($options, (array)($this->config['options'] ?? []));

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );

            // Set charset and search path
            $this->connection->exec("SET NAMES '{$this->config['charset']}'");
            $this->connection->exec("SET search_path TO '{$this->config['schema']}'");
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
        $conn = $this->requireConnection();

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        // Handle INSERT/UPDATE/DELETE with RETURNING clause
        if ($query instanceof Insert && $query->hasReturning()) {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($binds ?? []);
            $query->setReturningResult($stmt->fetchAll());
            return true;
        }

        if ($query instanceof Update && $query->hasReturning()) {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($binds ?? []);
            $query->setReturningResult($stmt->fetchAll());
            return true;
        }

        if ($query instanceof Delete && $query->hasReturning()) {
            $stmt = $this->prepareStatement($sql);
            $stmt->execute($binds ?? []);
            $query->setReturningResult($stmt->fetchAll());
            return true;
        }

        if ($binds === null) {
            return $conn->exec($sql) !== false;
        }

        $stmt = $this->prepareStatement($sql);
        return $stmt->execute($binds);
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

        $stmt = $this->prepareStatement($sql);
        $stmt->execute($binds);

        return $stmt->fetchAll();
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): false|string
    {
        return $this->requireConnection()->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(callable $callback): bool
    {
        $conn = $this->requireConnection();
        $conn->beginTransaction();

        try {
            if ($callback() === true) {
                return $conn->commit();
            }

            $conn->rollBack();
            return false;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Check if the connection is still alive.
     *
     * @return bool
     */
    public function ping(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            $stmt = $this->connection->query('SELECT 1');
            return $stmt !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reconnect if the connection has been lost.
     *
     * @return void
     */
    public function reconnectIfNeeded(): void
    {
        if ($this->connection === null || !$this->ping()) {
            $this->statementCache = [];
            $this->connect();
        }
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
     * @param string $channel The channel name.
     * @return bool
     */
    public function listen(string $channel): bool
    {
        $conn = $this->requireConnection();
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $channel);
        return $conn->exec("LISTEN $identifier") !== false;
    }

    /**
     * Unsubscribe from a notification channel.
     *
     * @param string $channel The channel name.
     * @return bool
     */
    public function unlisten(string $channel): bool
    {
        $conn = $this->requireConnection();
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $channel);
        return $conn->exec("UNLISTEN $identifier") !== false;
    }

    /**
     * Send a notification to a channel.
     *
     * @param string $channel The channel name.
     * @param string $payload The notification payload (max 8000 bytes).
     * @return bool
     */
    public function notify(string $channel, string $payload = ''): bool
    {
        $conn = $this->requireConnection();
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $channel);

        if ($payload === '') {
            return $conn->exec("NOTIFY $identifier") !== false;
        }

        $escaped = str_replace("'", "''", $payload);
        return $conn->exec("NOTIFY $identifier, '$escaped'") !== false;
    }

    /**
     * Check for a pending notification (non-blocking).
     *
     * Returns null if no notification is available.
     * Requires a prior listen() call on the channel.
     *
     * @param int $timeoutMs Timeout in milliseconds. 0 = non-blocking poll.
     * @return array{channel: string, payload: string, pid: int}|null
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
