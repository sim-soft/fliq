<?php

namespace Simsoft\DB\MySQL;

use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Raw;

/**
 * Query Class.
 *
 * @method static distinct()
 * @method static where(string|array|callable|Raw $attribute, mixed $operator = '=', mixed $value = null, string $logicalOperator = 'AND')
 */
class Query
{
    /**
     * @param string $name ActiveQuery's method name.
     * @param array $arguments Arguments to be used by the ActiveQuery's method.
     * @return mixed|ActiveQuery
     */
    public static function __callStatic(string $name, array $arguments)
    {
        $query = new ActiveQuery();
        if (method_exists($query, $name)) {
            return call_user_func_array([$query, $name], $arguments);
        }
        return $query;
    }
}
