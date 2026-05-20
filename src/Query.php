<?php

namespace Simsoft\DB;

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
     * @param string $name ActiveQuery method name.
     * @param array<int, mixed> $arguments Method arguments.
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        $query = new ActiveQuery();
        if (method_exists($query, $name)) {
            return $query->{$name}(...$arguments);
        }
        return $query;
    }
}
