<?php

namespace Simsoft\DB\MySQL\Builder\Aggregations;

/**
 * Max Builder Class.
 */
class Max extends Aggregate
{
    /** @var string Aggregate function name */
    protected string $functionName = 'MAX';
}
