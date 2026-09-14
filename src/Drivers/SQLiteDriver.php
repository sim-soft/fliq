<?php

namespace Simsoft\DB\Drivers;

use PDO;
use PDOException;
use PDOStatement;
use Simsoft\DB\Exceptions\ConnectionException;
use Simsoft\DB\Interfaces\CachesStatements;
use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Interfaces\ReturnsRows;

/**
 * SQLite database driver.
 *
 * Connection implementation using PHP PDO with SQLite driver.
 * Supports both file-based and in-memory databases.
 */
class SQLiteDriver extends Driver implements CachesStatements
{
    /** @var array<int, string> Required configuration keys */
    protected array $required = ['database'];

    /** @var array<string, mixed> Default configuration values */
    protected array $default = [
        'database' => ':memory:',
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
            // Read before connecting, as the other PDO-backed drivers do. This
            // driver declared neither key and read neither, so a config that
            // set them was accepted in full and obeyed in none of it: caching
            // stayed on and the cache grew past the size that was asked for.
            $this->cacheEnabled = (bool)($this->config['statement_cache'] ?? true);
            $this->maxCacheSize = (int)($this->config['statement_cache_size'] ?? 100);

            $dsn = 'sqlite:' . $this->config['database'];

            $this->connection = new PDO($dsn, null, null, $this->mergePdoOptions([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ], $this->config['options'] ?? []));

            // Enable WAL mode for better concurrency (file-based only)
            if ($this->config['database'] !== ':memory:') {
                $this->connection->exec('PRAGMA journal_mode=WAL');
            }

            // Enable foreign keys (off by default in SQLite)
            $this->connection->exec('PRAGMA foreign_keys=ON');

            $this->markActivity();
        } catch (PDOException $exception) {
            $this->addError($exception->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     *
     * SQLite talks to a local file rather than a server, so there is no
     * connection to lose and nothing here should ever run. An in-memory
     * database exists only inside its connection, so reopening one would hand
     * back an empty database rather than the caller's data — better to say so.
     *
     * @throws ConnectionException If the database is in-memory, or the file
     *                             could no longer be opened.
     */
    protected function forceReconnect(): void
    {
        if ($this->config['database'] === ':memory:') {
            throw new ConnectionException(
                'The connection to an in-memory SQLite database was lost. '
                . 'Its contents existed only in that connection and cannot be recovered.'
            );
        }

        // Statements are bound to the connection that prepared them.
        $this->statementCache = [];
        $this->connection = null;
        $this->connect();
        $this->assertReconnected();
    }

    /**
     * {@inheritdoc}
     */
    protected function isConnected(): bool
    {
        return $this->connection !== null;
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

        // SQLite has supported RETURNING since 3.35, and the grammar says so, so
        // the clause was emitted and the rows it produced were left in the
        // statement and thrown away. getReturningResult() answered null for a
        // statement that had returned rows, which is the same answer it gives
        // for one that returned none.
        if ($this->capturesReturning($query)) {
            $stmt = $this->prepareStatement($sql);
            $this->bindTypedValues($stmt, $binds ?? []);
            $result = $stmt->execute();
            $query->setReturningResult($stmt->fetchAll());
            $this->markActivity();

            return $result;
        }

        if ($binds === null) {
            $result = $conn->exec($sql) !== false;
            $this->markActivity();

            return $result;
        }

        $stmt = $this->prepareStatement($sql);
        $this->bindTypedValues($stmt, $binds);
        $result = $stmt->execute();
        $this->markActivity();

        return $result;
    }

    /**
     * Whether this statement carries a RETURNING clause whose rows to keep.
     *
     * The three builders were named here individually, which was the set that
     * declared the methods rather than the set that can carry the clause. Upsert
     * was not among them and so had its rows dropped — leaving its caller with
     * SQLite's connection-scoped last id, which after a skipped upsert names
     * whatever the previous statement inserted.
     *
     * @param Executable $query The query about to run.
     * @return bool
     * @phpstan-assert-if-true ReturnsRows $query
     */
    private function capturesReturning(Executable $query): bool
    {
        return $query instanceof ReturnsRows && $query->hasReturning();
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
            $this->bindTypedValues($stmt, $binds ?? []);
            $stmt->execute();
            $this->markActivity();

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
     * Bind values with proper PDO parameter types.
     *
     * SQLite requires explicit type binding because PDOStatement::execute(array)
     * always binds as PDO::PARAM_STR, causing type comparison failures with
     * json_extract() and numeric HAVING clauses.
     *
     * @param PDOStatement $stmt The prepared statement.
     * @param array<int, mixed> $binds The bind values.
     * @return void
     */
    private function bindTypedValues(PDOStatement $stmt, array $binds): void
    {
        foreach ($binds as $index => $value) {
            $position = $index + 1;
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($position, $value, $type);
        }
    }

    /**
     * Get or create a cached prepared statement.
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
}
