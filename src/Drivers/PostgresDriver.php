<?php

namespace Simsoft\DB\Drivers;

use PDO;
use PDOException;
use PDOStatement;
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
