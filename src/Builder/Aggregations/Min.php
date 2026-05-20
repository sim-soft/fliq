<?php

namespace Simsoft\DB\Builder\Aggregations;

/**
 * Min Builder Class.
 */
class Min extends Aggregate
{
    /** @var string Aggregate function name */
    protected string $functionName = 'MIN';
}
