<?php

namespace Simsoft\DB\MySQL;

use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Delete;
use Simsoft\DB\MySQL\Builder\Insert;
use Simsoft\DB\MySQL\Builder\Raw;
use Simsoft\DB\MySQL\Builder\Update;
use Simsoft\DB\MySQL\Interfaces\Executable;
use Throwable;

/**
 * Class DB
 *
 * The DB class is responsible for basic INSERT, UPDATE and DELETE operations.
 *
 * @package ActiveRecord
 *
 * @author: vzangloo <vzangloo@7mayday.com>
 *
 * @link: https://www.7mayday.com
 * @since 1.0.0
 * @copyright 2022
 */
class DB
{

    /** @var string Default connection */
    protected static string $defaultConnection = 'mysql';

    /** @var bool Return SQL instead of execute the SQL. Default: false; */
    private static bool $sqlOnly = false;

    /** @var array Operation errors. */
    private static array $errors = [];

    /**
     * Set default connection.
     *
     * @param string $connection The default connection name.
     * @return void
     */
    public static function setDefaultConnection(string $connection): void
    {
        self::$defaultConnection = $connection;
    }

    /**
     * Prevent execution and return SQL only.
     *
     * @param bool $enable Enable SQL only.
     * @return void
     */
    public static function sqlOnly(bool $enable = true): void
    {
        self::$sqlOnly = $enable;
    }

    /**
     * Perform query on table.
     *
     * @param string|array|Model $name The table name
     * @param string|null $connection Connection name
     * @return ActiveQuery
     */
    public static function table(string|array|Model $name, ?string $connection = null): ActiveQuery
    {
        return match (true) {
            $name instanceof Model => $name::find(),
            default => (new ActiveQuery())->from($name)->setConnection($connection ?? self::$defaultConnection),
        };
    }

    /**
     * Perform INSERT operation
     *
     * @param string|Model $table Table name.
     * @param array $attributes Attributes => values to be inserted.
     * @param string|null $connection The connection name.
     * @return string|bool|Executable|null
     */
    public static function insert(
        string|Model $table,
        array        $attributes,
        ?string      $connection = null
    ): string|bool|null|Executable
    {
        if ($table instanceof Model) {
            $connection = $table->getConnection();
            $table = $table->getTable();
        }

        $insert = (new Insert($table, $attributes));

        return self::$sqlOnly
            ? $insert
            : $insert->setConnection($connection ?? self::$defaultConnection)->execute();
    }

    /**
     * Perform INSERT or IGNORE operation
     *
     * @param string|Model $table Table name.
     * @param array $attributes Attributes => values to be inserted.
     * @param string|null $connection The connection name.
     * @return mixed
     */
    public static function insertOrIgnore(
        string|Model $table,
        array        $attributes,
        ?string      $connection = null
    ): mixed
    {
        if ($table instanceof Model) {
            $connection = $table->getConnection();
            $table = $table->getTable();
        }

        $insert = (new Insert($table, $attributes))->ignore();

        return self::$sqlOnly
            ? $insert
            : $insert->setConnection($connection ?? self::$defaultConnection)->execute();
    }

    /**
     * Perform UPDATE operation
     *
     * @param string|array|Model $table The table name.
     * @param array $attributes Attributes => values to be update.
     * @param string|ActiveQuery|Raw $condition The condition to be updated.
     * @return Executable|bool|null
     */
    public static function update(
        string|array|Model     $table,
        array                  $attributes,
        string|ActiveQuery|Raw $condition
    ): Executable|bool|null
    {
        try {
            if ($table instanceof Model) {
                $connection = $table->getConnection();
                $table = $table->getTable();
            }

            $update = new Update($table, $attributes, $condition);

            return self::$sqlOnly
                ? $update
                : $update->setConnection($connection ?? self::$defaultConnection)->execute();
        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Perform UPDATE IGNORE operation
     *
     * @param string|array|Model $table The table name.
     * @param array $attributes Attributes => values to be update.
     * @param string|ActiveQuery|Raw $condition The condition to be updated.
     * @return Executable|bool|null
     */
    public static function updateIgnore(
        string|array|Model     $table,
        array                  $attributes,
        string|ActiveQuery|Raw $condition
    ): Executable|bool|null
    {
        try {
            if ($table instanceof Model) {
                $connection = $table->getConnection();
                $table = $table->getTable();
            }

            $update = (new Update($table, $attributes, $condition))->ignore();

            return self::$sqlOnly
                ? $update
                : $update->setConnection($connection ?? self::$defaultConnection)->execute();
        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Perform UPDATE LOW_PRIORITY IGNORE operation
     *
     * @param string|array|Model $table The table name.
     * @param array $attributes Attributes => values to be update.
     * @param string|ActiveQuery|Raw $condition The condition to be updated.
     * @return Executable|bool|null
     */
    public static function updateLowPriorityIgnore(
        string|array|Model     $table,
        array                  $attributes,
        string|ActiveQuery|Raw $condition
    ): Executable|bool|null
    {
        try {
            if ($table instanceof Model) {
                $connection = $table->getConnection();
                $table = $table->getTable();
            }

            $update = (new Update($table, $attributes, $condition))->lowPriority()->ignore();

            return self::$sqlOnly
                ? $update
                : $update->setConnection($connection ?? self::$defaultConnection)->execute();
        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Perform DELETE operation
     *
     * @param string|Model $table
     * @param string|ActiveQuery|Raw $condition
     * @return mixed
     */
    public static function delete(
        string|Model           $table,
        string|ActiveQuery|Raw $condition
    ): mixed
    {
        try {
            if ($table instanceof Model) {
                $table = $table->getTable();
            }

            $delete = new Delete($table, $condition);

            return self::$sqlOnly
                ? $delete
                : $delete->setConnection($connection ?? self::$defaultConnection)->execute();

        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Perform DELETE IGNORE operation
     *
     * @param string|Model $table
     * @param string|ActiveQuery|Raw $condition
     * @return mixed
     */
    public static function deleteIgnore(
        string|Model           $table,
        string|ActiveQuery|Raw $condition
    ): mixed
    {
        try {
            if ($table instanceof Model) {
                $table = $table->getTable();
            }

            $delete = (new Delete($table, $condition))->ignore();

            return self::$sqlOnly
                ? $delete
                : $delete->setConnection($connection ?? self::$defaultConnection)->execute();

        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Perform DELETE QUICK operation
     *
     * @param string|Model $table
     * @param string|ActiveQuery|Raw $condition
     * @return mixed
     */
    public static function deleteQuick(
        string|Model           $table,
        string|ActiveQuery|Raw $condition
    ): mixed
    {
        try {
            if ($table instanceof Model) {
                $table = $table->getTable();
            }

            $delete = (new Delete($table, $condition))->quick();

            return self::$sqlOnly
                ? $delete
                : $delete->setConnection($connection ?? self::$defaultConnection)->execute();

        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Perform DELETE QUICK IGNORE operation
     *
     * @param string|Model $table
     * @param string|ActiveQuery|Raw $condition
     * @return mixed
     */
    public static function deleteQuickIgnore(
        string|Model           $table,
        string|ActiveQuery|Raw $condition
    ): mixed
    {
        try {
            if ($table instanceof Model) {
                $table = $table->getTable();
            }

            $delete = (new Delete($table, $condition))->quick()->ignore();

            return self::$sqlOnly
                ? $delete
                : $delete->setConnection($connection ?? self::$defaultConnection)->execute();

        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Perform DELETE LOW_PRIORITY QUICK IGNORE operation
     *
     * @param string|Model $table
     * @param string|ActiveQuery|Raw $condition
     * @return mixed
     */
    public static function deleteLowPriorityQuickIgnore(
        string|Model           $table,
        string|ActiveQuery|Raw $condition
    ): mixed
    {
        try {
            if ($table instanceof Model) {
                $table = $table->getTable();
            }

            $delete = (new Delete($table, $condition))->lowPriority()->quick()->ignore();

            return self::$sqlOnly
                ? $delete
                : $delete->setConnection($connection ?? self::$defaultConnection)->execute();

        } catch (Throwable $exception) {
            self::$errors[] = $exception->getMessage();
        }
        return false;
    }

    /**
     * Execute raw.
     *
     * @param string $sql The SQL query statement.
     * @param array $binds The bind values for the SQL query statement.
     * @param string|null $connection The connection to be used.
     * @return bool|null
     */
    public static function raw(string $sql, array $binds = [], ?string $connection = null): ?bool
    {
        return (new Raw($sql, $binds))->setConnection($connection ?? self::$defaultConnection)->execute();
    }
}
