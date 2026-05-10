<?php

namespace Simsoft\DB\MySQL\Drivers;

use PDO;
use PDOException;
use Simsoft\DB\MySQL\Interfaces\Executable;

/**
 * @references https://phpdelusions.net/pdo_examples/connect_to_mysql
 */
class PDODriver extends Driver
{
    protected array $required = ['host', 'database', 'username', 'password'];
    protected array $default = ['port' => 3306, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
    protected ?PDO $connection = null;

    /** {@inheritdoc } */
    protected function connect(): void
    {
        try {
            $this->connection = new PDO(
                'mysql:' .
                implode(';', [
                    'host=' . $this->config['host'],
                    'port=' . $this->config['port'],
                    'dbname=' . $this->config['database'],
                    'charset=' . $this->config['charset'],
                ]),
                $this->config['username'],
                $this->config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$this->config['charset']}' COLLATE '{$this->config['collation']}'",
                ]
            );

        } catch (PDOException $exception) {
            $this->addError($exception->getMessage());
        }
    }

    /** {@inheritdoc } */
    public function execute(Executable $query): bool
    {
        $sql = $query->getSQL();
        if ($query->getBinds() === null) {
            $stmt = $this->connection->query($sql);
            if ($stmt === false) {
                return false;
            }

            return $stmt->fetchAll();
        }

        $stmt = $this->connection->prepare($sql);
        return $stmt->execute($query->getBinds());
    }

    /** {@inheritdoc } */
    public function query(Executable $query): array
    {
        $stmt = $this->connection->prepare($query->getSQL());
        if (!$stmt->execute($query->getBinds())) {
            $this->addError($stmt->errorCode() . ': ' . json_encode($stmt->errorInfo()));
        }
        return $stmt->fetchAll();
    }

    /** {@inheritdoc } */
    public function lastInsertId(): false|string
    {
        return $this->connection->lastInsertId();
    }

    /** {@inheritdoc } */
    public function transaction(callable $callback): bool
    {
        if ($this->connection->beginTransaction()) {
            if ($callback() === true) {
                return $this->connection->commit();
            }
            $this->connection->rollBack();
        }
        return false;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->connection = null;
    }
}
