<?php

namespace Simsoft\DB\MySQL\Traits;

use Exception;
use Simsoft\DB\MySQL\Connection;
use Simsoft\DB\MySQL\Drivers\Driver;
use Simsoft\DB\MySQL\Interfaces\Executable;
use Throwable;

/**
 * Execute trait
 */
trait Execute
{
    /** @var null|string The database name */
    protected ?string $connection = null;

    /**
     * Set database connection
     *
     * @param string|null $connect The database connection name
     */
    public function setConnection(?string $connect = null): self
    {
        $this->connection = $connect;
        return $this;
    }

    /**
     * Rendering full SQL statement if it is debug mode.
     *
     * @param string $error Query error.
     * @param Executable $query Executable object.
     * @return void
     */
    protected function renderSQLDebug(string $error, Executable $query): void
    {
        debug_print_backtrace();
        echo "$error\n";
        echo get_called_class() . ":'{$query->getSQL()}' \n";
        echo '<== Bind values: ';
        var_dump($query->getBinds());
    }

    /**
     * Get connection's driver.
     *
     * @return Driver|null
     */
    protected function getConnection(): ?Driver
    {
        if (is_string($this->connection)) {
            return Connection::get($this->connection);
        }
        return null;
    }

    /**
     * Execute SQL statement.
     *
     * @param Executable|null $query The SQL query object.
     * @return array|bool|null
     */
    public function execute(?Executable $query = null): array|bool|null
    {
        try {
            return $this->getConnection()->execute($query ? clone $query : $this);
        } catch (Throwable $exception) {
            $this->renderSQLDebug($exception->getMessage(), $query ?? $this);
        }

        return false;
    }

    /**
     * Get last insert Id.
     *
     * @return string|null
     */
    public function getLastInsertId(): ?string
    {
        $id = $this->getConnection()->lastInsertId();
        return $id === false ? null : $id;
    }

    /**
     * Execute SQL statement.
     *
     * @param Executable|null $query The SQL query object.
     * @return array
     */
    public function query(?Executable $query = null): array
    {
        try {
            return (array)$this->getConnection()?->query($query ? clone $query : $this);
        } catch (Throwable $exception) {
            $this->renderSQLDebug($exception->getMessage(), $query ?? $this);
        }
        return [];
    }
}
