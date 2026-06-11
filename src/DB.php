<?php

namespace Simsoft\DB;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Interfaces\Executable;

/**
 * Class DB.
 *
 * Static facade for common database operations.
 * Returns builder objects in sqlOnly mode, executes directly otherwise.
 */
class DB
{
    /** @var bool Return SQL instead of executing. Default: false. */
    private static bool $sqlOnly = false;

    /**
     * Enable SQL-only mode (returns builder objects without executing).
     *
     * @return void
     */
    public static function sqlOnly(): void
    {
        self::$sqlOnly = true;
    }

    /**
     * Disable SQL-only mode.
     *
     * @return void
     */
    public static function disableSqlOnly(): void
    {
        self::$sqlOnly = false;
    }

    /**
     * Perform a query on a table.
     *
     * @param string|array<string, string|ActiveQuery|Raw>|Model $name The table name.
     * @param string|null $connection Connection name.
     * @return ActiveQuery
     */
    public static function table(string|array|Model $name, ?string $connection = null): ActiveQuery
    {
        if ($name instanceof Model) {
            return $name::find();
        }

        return (new ActiveQuery())
            ->from($name)
            ->withConnection($connection ?? Connection::getDefaultName());
    }

    /**
     * Create an INSERT builder for a table.
     *
     * In sqlOnly mode, returns the builder. Otherwise, executes immediately.
     *
     * @param string|Model $table Table name or model.
     * @param array<string, mixed> $attributes Column => value pairs.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function insert(
        string|Model $table,
        array        $attributes,
        ?string      $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = new Insert($tableName, $attributes);

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create an INSERT IGNORE builder.
     *
     * @param string|Model $table Table name or model.
     * @param array<string, mixed> $attributes Column => value pairs.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function insertOrIgnore(
        string|Model $table,
        array        $attributes,
        ?string      $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = (new Insert($tableName, $attributes))->ignore();

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create an UPDATE builder.
     *
     * @param string|Model $table Table name or model.
     * @param array<string, mixed> $attributes Column => value pairs to update.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function update(
        string|Model           $table,
        array                  $attributes,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = new Update($tableName, $attributes, $condition);

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create an UPDATE IGNORE builder.
     *
     * @param string|Model $table Table name or model.
     * @param array<string, mixed> $attributes Column => value pairs to update.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function updateIgnore(
        string|Model           $table,
        array                  $attributes,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = (new Update($tableName, $attributes, $condition))->ignore();

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create an UPDATE LOW_PRIORITY IGNORE builder.
     *
     * @param string|Model $table Table name or model.
     * @param array<string, mixed> $attributes Column => value pairs to update.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function updateLowPriorityIgnore(
        string|Model           $table,
        array                  $attributes,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = (new Update($tableName, $attributes, $condition))->lowPriority()->ignore();

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create a DELETE builder.
     *
     * @param string|Model $table Table name or model.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function delete(
        string|Model           $table,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = new Delete($tableName, $condition);

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create a DELETE IGNORE builder.
     *
     * @param string|Model $table Table name or model.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function deleteIgnore(
        string|Model           $table,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = (new Delete($tableName, $condition))->ignore();

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create a DELETE QUICK builder.
     *
     * @param string|Model $table Table name or model.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function deleteQuick(
        string|Model           $table,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = (new Delete($tableName, $condition))->quick();

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create a DELETE QUICK IGNORE builder.
     *
     * @param string|Model $table Table name or model.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function deleteQuickIgnore(
        string|Model           $table,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = (new Delete($tableName, $condition))->quick()->ignore();

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Create a DELETE LOW_PRIORITY QUICK IGNORE builder.
     *
     * @param string|Model $table Table name or model.
     * @param string|ActiveQuery|Raw $condition WHERE condition.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function deleteLowPriorityQuickIgnore(
        string|Model           $table,
        string|ActiveQuery|Raw $condition,
        ?string                $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = (new Delete($tableName, $condition))->lowPriority()->quick()->ignore();

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Execute raw SQL (INSERT, UPDATE, DELETE).
     *
     * @param string $sql The SQL query statement.
     * @param array<int, mixed> $binds The bind values.
     * @param string|null $connection The connection name.
     * @return bool
     * @throws QueryException
     */
    public static function raw(string $sql, array $binds = [], ?string $connection = null): bool
    {
        return (new Raw($sql, $binds))
            ->withConnection($connection ?? Connection::getDefaultName())
            ->execute();
    }

    /**
     * Execute raw SQL and return results (SELECT).
     *
     * @param string $sql The SQL query statement.
     * @param array<int, mixed> $binds The bind values.
     * @param string|null $connection The connection name.
     * @return array<int, array<string, mixed>>
     * @throws QueryException
     */
    public static function query(string $sql, array $binds = [], ?string $connection = null): array
    {
        return (new Raw($sql, $binds))
            ->withConnection($connection ?? Connection::getDefaultName())
            ->fetchAll();
    }

    /**
     * Perform INSERT ... ON DUPLICATE KEY UPDATE (upsert).
     *
     * @param string|Model $table Table name or model.
     * @param array<string, mixed> $attributes Column => value pairs to insert.
     * @param array<int|string, mixed> $updateColumns Columns to update on duplicate. Numeric array uses VALUES(), assoc sets explicit values.
     * @param string|null $connection Connection name override.
     * @return Executable|bool
     */
    public static function upsert(
        string|Model $table,
        array        $attributes,
        array        $updateColumns = [],
        ?string      $connection = null
    ): Executable|bool
    {
        [$tableName, $connectionName] = self::resolveTable($table, $connection);
        $builder = new Upsert($tableName, $attributes, $updateColumns);

        return self::executeOrReturn($builder, $connectionName);
    }

    /**
     * Resolve table name and connection from input.
     *
     * @param string|Model $table The table or model.
     * @param string|null $connection The connection name override.
     * @return array{0: string, 1: string}
     */
    private static function resolveTable(string|Model $table, ?string $connection = null): array
    {
        if ($table instanceof Model) {
            return [$table->getTable(), $connection ?? $table->getConnectionName()];
        }

        return [$table, $connection ?? Connection::getDefaultName()];
    }

    /**
     * Execute a builder or return it in sqlOnly mode.
     *
     * @param Insert|Update|Delete $builder The builder to execute.
     * @param string $connectionName The connection name.
     * @return Executable|bool
     */
    private static function executeOrReturn(Insert|Update|Delete|Upsert $builder, string $connectionName): Executable|bool
    {
        if (self::$sqlOnly) {
            return $builder;
        }

        return $builder->withConnection($connectionName)->execute();
    }

    /**
     * Execute a callback within a database transaction.
     *
     * @param string $connection The connection name.
     * @param callable $callback Callback to execute. Return true to commit, false to roll back.
     * @return bool
     * @throws QueryException
     */
    public static function transaction(string $connection, callable $callback): bool
    {
        try {
            return Connection::get($connection)->transaction($callback);
        } catch (\Throwable $throwable) {
            throw new QueryException($throwable->getMessage(), '', null, 0, $throwable);
        }
    }
}
