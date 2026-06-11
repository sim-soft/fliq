<?php

namespace Simsoft\DB\Drivers;

use PDO;
use PDOException;
use PDOStatement;
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

        if ($binds === null) {
            return $conn->exec($sql) !== false;
        }

        $stmt = $this->prepareStatement($sql);
        $this->bindTypedValues($stmt, $binds);
        return $stmt->execute();
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
