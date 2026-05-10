<?php

namespace Simsoft\DB\MySQL\Builder\Aggregations;

/**
 * Count Builder Class.
 */
class Count extends Aggregate
{
    /** @var string Aggregate function name */
    protected string $functionName = 'COUNT';
}
