<?php

namespace Simsoft\DB\Drivers;

use PDO;
use PDOException;
use PDOStatement;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Exceptions\ConnectionException;
use Simsoft\DB\Interfaces\Executable;

/**
 * SQLite database driver.
 *
 * Connection implementation using PHP PDO with SQLite driver.
 * Supports both file-based and in-memory databases.
 */
class SQLiteDriver extends Driver
{
    /** @var array<int, string> Required configuration keys */
    protected array $required = ['database'];

    /** @var array<string, mixed> Default configuration values */
    protected array $default = [
        'database' => ':memory:',
    ];

    /** @var PDO|null The PDO connection instance */
    protected ?PDO $connection = null;

    /** @var array<string, PDOStatement> Prepared statement cache */
    private array $statementCache = [];

    /** @var int Maximum cached statements */
    private int $maxCacheSize = 100;

    /**
     * {@inheritdoc}
     */
    protected function connect(): void
    {
        // A new connection carries no transaction, whatever the old one had.
        $this->resetTransactionLevel();

        try {
            $dsn = 'sqlite:' . $this->config['database'];

            $this->connection = new PDO($dsn, null, null, array_replace([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ], (array)($this->config['options'] ?? [])));

            // Enable WAL mode for better concurrency (file-based only)
            if ($this->config['database'] !== ':memory:') {
                $this->connection->exec('PRAGMA journal_mode=WAL');
            }

            // Enable foreign keys (off by default in SQLite)
            $this->connection->exec('PRAGMA foreign_keys=ON');
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
     * @throws ConnectionException If the database is in-memory.
     */
    protected function forceReconnect(): void
    {
        if ($this->config['database'] === ':memory:') {
            throw new ConnectionException(
                'The connection to an in-memory SQLite database was lost. '
                . 'Its contents existed only in that connection and cannot be recovered.'
            );
        }

        $this->connection = null;
        $this->connect();
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

            return $result;
        }

        if ($binds === null) {
            return $conn->exec($sql) !== false;
        }

        $stmt = $this->prepareStatement($sql);
        $this->bindTypedValues($stmt, $binds);
        return $stmt->execute();
    }

    /**
     * Whether this statement carries a RETURNING clause whose rows to keep.
     *
     * @param Executable $query The query about to run.
     * @return bool
     * @phpstan-assert-if-true Insert|Update|Delete $query
     */
    private function capturesReturning(Executable $query): bool
    {
        return ($query instanceof Insert || $query instanceof Update || $query instanceof Delete)
            && $query->hasReturning();
    }

    /**
     * {@inheritdoc}
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(Executable $query): array
    {
        $sql = $query->getSQL();
        $binds = $query->getBinds();

        $stmt = $this->prepareStatement($sql);
        $this->bindTypedValues($stmt, $binds ?? []);
        $stmt->execute();

        return $stmt->fetchAll();
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
            $this->connection->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
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
     * Destructor.
     */
    public function __destruct()
    {
        $this->statementCache = [];
        $this->connection = null;
    }
}
