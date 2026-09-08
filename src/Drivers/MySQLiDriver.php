<?php

namespace Simsoft\DB\Drivers;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use Simsoft\DB\Interfaces\Executable;

/**
 * MySQLi database driver.
 */
class MySQLiDriver extends Driver
{
    /** @var array<int, string> Required configuration keys */
    protected array $required = ['host', 'database', 'username', 'password'];

    /** @var array<string, mixed> Default configuration values */
    protected array $default = [
        'port' => 3306,
        'charset' => 'utf8mb4',
        'persistent' => false,
        'timeout' => 5,
    ];

    /** @var mysqli|null The MySQLi connection instance */
    protected ?mysqli $connection = null;

    /**
     * {@inheritdoc}
     */
    protected function connect(): void
    {
        $this->prepareConnect();

        // Built locally and only published once real_connect() has returned.
        // Assigning the bare mysqli() first left a handle behind when the
        // connect failed, and that handle satisfied isConnected() — so the
        // driver called itself connected, and the next statement died with
        // Error('mysqli object is not fully initialized') rather than saying
        // the server was unreachable. A property access on it was worse still:
        // Error('Property access is not allowed yet'), thrown from
        // lastInsertId(), which is typed to answer false.
        $connection = null;

        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            $host = $this->config['host'];

            // Persistent connection: prefix host with 'p:'
            if (!empty($this->config['persistent'])) {
                $host = 'p:' . $host;
            }

            $connection = new mysqli();

            // Set timeout before connecting
            $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int)$this->config['timeout']);

            // Set init command before connecting (joined with semicolons)
            $initCommands = (array)($this->config['init_command'] ?? []);
            if ($initCommands) {
                $connection->options(MYSQLI_INIT_COMMAND, implode('; ', $initCommands));
            }

            // Apply user options before connecting
            foreach ((array)($this->config['options'] ?? []) as $option => $value) {
                $connection->options($option, $value);
            }

            $connection->real_connect(
                $host,
                $this->config['username'],
                $this->config['password'],
                $this->config['database'],
                $this->config['port']
            );

            $connection->set_charset($this->config['charset']);

            $this->connection = $connection;
            $this->markActivity();
        } catch (mysqli_sql_exception $exception) {
            $this->connection = null;
            $this->addError($exception->getMessage());
        }
    }

    /**
     * Ensure the connection is established.
     *
     * @return mysqli
     * @throws RuntimeException If the connection is not established.
     */
    private function getConnection(): mysqli
    {
        if ($this->connection === null) {
            throw new RuntimeException('MySQLi connection not established');
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
        $conn = $this->getConnection();
        $sql = $query->getSQL();
        $binds = $query->getBinds();

        if ($binds === null) {
            $result = $conn->query($sql);
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
            $this->markActivity();

            return $result !== false;
        }

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $conn->error);
        }
        $ok = $stmt->execute($binds);
        $stmt->close();
        $this->markActivity();

        return $ok;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(Executable $query): array
    {
        $this->reconnectIfNeeded();

        return $this->runWithReconnect(fn(): array => $this->queryOnce($query));
    }

    /**
     * Run one attempt of query().
     *
     * @param Executable $query The query to run.
     * @return array<int, array<string, mixed>>
     */
    private function queryOnce(Executable $query): array
    {
        $conn = $this->getConnection();
        $stmt = $conn->prepare($query->getSQL());
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $conn->error);
        }
        $binds = $query->getBinds();

        $stmt->execute($binds ?? []);
        $result = $stmt->get_result();
        if ($result === false) {
            // get_result() answers false for two unrelated things: a statement
            // that failed, and a statement that ran fine and has no result set
            // to give — an UPDATE, a DELETE, a SET. Treating both as failure
            // made `query('SET @x = 1')` throw "Failed to get result: " with an
            // empty reason, because there was no error to name. The other three
            // drivers return [] for the same statement. field_count is what
            // distinguishes them: zero columns means there was never a result
            // set, and errno is what a real failure sets.
            $failed = $stmt->errno !== 0 || $stmt->field_count > 0;
            $error = $stmt->error !== '' ? $stmt->error : $conn->error;
            $stmt->close();

            if ($failed) {
                throw new RuntimeException('Failed to get result: ' . $error);
            }

            $this->markActivity();

            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        $this->markActivity();

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): false|string
    {
        // "No id to report" is what an absent connection has, and the method is
        // typed to say so. This alone threw instead — RuntimeException from a
        // null handle, and Error('Property access is not allowed yet') from a
        // handle whose connect had failed — while the three PDO-backed drivers
        // all answer false through normalizeInsertId(). Execute::getLastInsertId()
        // maps false to null and does not catch, so the same "nothing was
        // inserted" fact escaped as an exception from a method typed ?string.
        return $this->normalizeInsertId(function (): string|false {
            $insertId = $this->getConnection()->insert_id;

            return $insertId > 0 ? (string)$insertId : false;
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function beginTransaction(): void
    {
        $this->getConnection()->begin_transaction();
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction(): bool
    {
        return $this->getConnection()->commit();
    }

    /**
     * {@inheritdoc}
     */
    protected function rollBackTransaction(): void
    {
        $this->getConnection()->rollback();
    }

    /**
     * {@inheritdoc}
     */
    protected function executeRawStatement(string $sql): void
    {
        $this->getConnection()->query($sql);
    }

    /**
     * {@inheritdoc}
     */
    protected function probeLiveness(): bool
    {
        // mysqli::ping() is deprecated as of PHP 8.4 — a trivial query checks
        // liveness the same way, matching the other drivers.
        $result = $this->getConnection()->query('SELECT 1');
        if ($result === false) {
            return false;
        }

        if ($result instanceof \mysqli_result) {
            $result->free();
        }

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
        $this->connection = null;
        $this->connect();
        $this->assertReconnected();
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }
}
