<?php

namespace Simsoft\DB\MySQL\Builder\Aggregations;

/**
 * Sum Builder Class.
 */
class Sum extends Aggregate
{
    /** @var string Aggregate function name */
    protected string $functionName = 'SUM';
}
