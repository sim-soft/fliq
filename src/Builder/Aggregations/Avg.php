<?php

namespace Simsoft\DB\MySQL\Builder\Aggregations;

/**
 * AVG Builder Class.
 */
class Avg extends Aggregate
{
    /** @var string Aggregate function name */
    protected string $functionName = 'AVG';
}
