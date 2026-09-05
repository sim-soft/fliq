<?php

namespace Simsoft\DB;

use BadMethodCallException;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * Query Class.
 *
 * Static proxy for ActiveQuery. Use DB::table() instead.
 *
 * @deprecated Use DB::table() for query building.
 *
 * @method static ActiveQuery from(string|array<string, string|ActiveQuery|Raw>|Model $table)
 * @method static ActiveQuery where(string|array<mixed>|callable|Raw $attribute, mixed $operator = '=', mixed $value = null, string $logicalOperator = 'AND')
 * @method static ActiveQuery distinct()
 */
class Query
{
    /**
     * Proxy static calls to a new ActiveQuery instance.
     *
     * An unknown method used to return the bare query instead of failing, so a
     * misspelled call quietly dropped its conditions — `Query::wheer('id', 5)`
     * returned a query matching the whole table, and a subsequent delete or
     * update would have applied to all of it.
     *
     * @param string $name ActiveQuery method name.
     * @param array<int, mixed> $arguments Method arguments.
     * @return mixed
     * @throws BadMethodCallException If ActiveQuery has no such method.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        $query = new ActiveQuery();

        if (!method_exists($query, $name)) {
            throw new BadMethodCallException(
                sprintf('Call to undefined method %s::%s().', ActiveQuery::class, $name)
            );
        }

        return $query->{$name}(...$arguments);
    }
}
