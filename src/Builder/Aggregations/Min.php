<?php

namespace Simsoft\DB\MySQL\Builder\Aggregations;

/**
 * Min Builder Class.
 */
class Min extends Aggregate
{
    /** @var string Aggregate function name */
    protected string $functionName = 'MIN';
}
