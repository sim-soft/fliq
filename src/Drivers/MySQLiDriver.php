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
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            $host = $this->config['host'];

            // Persistent connection: prefix host with 'p:'
            if (!empty($this->config['persistent'])) {
                $host = 'p:' . $host;
            }

            $this->connection = new mysqli();

            // Set timeout before connecting
            $this->connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int)$this->config['timeout']);

            // Set init command before connecting (joined with semicolons)
            $initCommands = (array)($this->config['init_command'] ?? []);
            if ($initCommands) {
                $this->connection->options(MYSQLI_INIT_COMMAND, implode('; ', $initCommands));
            }

            // Apply user options before connecting
            foreach ((array)($this->config['options'] ?? []) as $option => $value) {
                $this->connection->options($option, $value);
            }

            $this->connection->real_connect(
                $host,
                $this->config['username'],
                $this->config['password'],
                $this->config['database'],
                $this->config['port']
            );

            $this->connection->set_charset($this->config['charset']);
        } catch (mysqli_sql_exception $exception) {
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
        $conn = $this->getConnection();
        $sql = $query->getSQL();
        $binds = $query->getBinds();

        if ($binds === null) {
            $result = $conn->query($sql);
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
            return $result !== false;
        }

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $conn->error);
        }
        $ok = $stmt->execute($binds);
        $stmt->close();

        return $ok;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(Executable $query): array
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
            $stmt->close();
            throw new RuntimeException('Failed to get result: ' . $conn->error);
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): false|string
    {
        $insertId = $this->getConnection()->insert_id;

        return $insertId > 0 ? (string)$insertId : false;
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(callable $callback): bool
    {
        $conn = $this->getConnection();
        $conn->begin_transaction();

        try {
            if ($callback() === true) {
                return $conn->commit();
            }

            $conn->rollback();
            return false;
        } catch (\Throwable $e) {
            $conn->rollback();
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
            return $this->connection->ping();
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
            $this->connect();
        }
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
