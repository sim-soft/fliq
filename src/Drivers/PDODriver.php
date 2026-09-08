<?php

namespace Simsoft\DB\Drivers;

use PDO;
use PDOException;
use PDOStatement;
use Simsoft\DB\Interfaces\CachesStatements;
use Simsoft\DB\Interfaces\Executable;

/**
 * PDO database driver.
 *
 * MySQL connection implementation using PHP PDO extension.
 * Features: prepared statement caching, persistent connections.
 */
class PDODriver extends Driver implements CachesStatements
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
        $this->prepareConnect();

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
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '"
                    . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$this->config['charset'])
                    . "' COLLATE '"
                    . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$this->config['collation'])
                    . "'",
            ];

            if (!empty($this->config['persistent'])) {
                $defaults[PDO::ATTR_PERSISTENT] = true;
            }

            // User options override defaults, bar the error mode.
            $options = $this->mergePdoOptions($defaults, $this->config['options'] ?? []);

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );

            $this->markActivity();
        } catch (PDOException $exception) {
            $this->addError($exception->getMessage());
        }
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

        // MySQL does not buffer by default, so a result set left unread keeps
        // the connection busy and the next prepared statement fails with "2014
        // Cannot execute queries while other unbuffered queries are active".
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

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        return $this->runWithReconnect(function () use ($sql, $binds): bool {
            $stmt = $this->prepareStatement($sql);
            $result = $stmt->execute($binds ?? []);

            // A statement that returned rows holds them until they are read or
            // the cursor is closed, and MySQL refuses the next one meanwhile.
            // The unbound branch used PDO::exec(), which gives back no handle
            // to close, so `execute(new Raw('SELECT 1'))` left the connection
            // unusable and the failure surfaced on whichever innocent statement
            // came next. The three other drivers all free their result here.
            $stmt->closeCursor();
            $this->markActivity();

            return $result;
        });
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
            $stmt->execute($binds ?? []);
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
