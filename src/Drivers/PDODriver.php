<?php

namespace Simsoft\DB\Drivers;

use PDO;
use PDOException;
use PDOStatement;
use Simsoft\DB\Interfaces\Executable;

/**
 * PDO database driver.
 *
 * MySQL connection implementation using PHP PDO extension.
 * Features: prepared statement caching, persistent connections.
 */
class PDODriver extends Driver
{
    /** @var array<int, string> Required configuration keys */
    protected array $required = ['host', 'database', 'username', 'password'];

    /** @var array<string, mixed> Default configuration values */
    protected array $default = [
        'port' => 3306,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
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

            $dsn = 'mysql:' . implode(';', [
                'host=' . $this->config['host'],
                'port=' . $this->config['port'],
                'dbname=' . $this->config['database'],
                'charset=' . $this->config['charset'],
            ]);

            $defaults = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => (int)$this->config['timeout'],
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$this->config['charset']}' COLLATE '{$this->config['collation']}'",
            ];

            if (!empty($this->config['persistent'])) {
                $defaults[PDO::ATTR_PERSISTENT] = true;
            }

            // User options override defaults
            $options = array_replace($defaults, (array)($this->config['options'] ?? []));

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );
        } catch (PDOException $exception) {
            $this->addError($exception->getMessage());
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
        $stmt->execute($binds ?? []);

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
     * Get the underlying PDO connection.
     *
     * @return PDO|null
     */
    public function getPdo(): ?PDO
    {
        return $this->connection;
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
