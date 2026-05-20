<?php

namespace Simsoft\DB\Builder\Aggregations;

/**
 * Count Builder Class.
 */
class Count extends Aggregate
{
    /** @var string Aggregate function name */
    protected string $functionName = 'COUNT';
}
