<?php

namespace Simsoft\DB\Builder\Clauses;

use Simsoft\DB\Traits\Binds;
use Simsoft\DB\Traits\PlaceHolder;
use Simsoft\DB\Traits\Qualifier;

/**
 * Class Clause.
 *
 * Base class for all SQL clause/condition value objects.
 */
abstract class Clause
{
    use PlaceHolder, Qualifier, Binds;

    /** @var string|null Cached SQL output */
    private ?string $sql = null;

    /**
     * Constructor.
     *
     * @param mixed $attribute The attribute name or array of conditions.
     * @param mixed $value The condition value.
     * @param bool $is Whether the condition is positive (true) or negated (false).
     */
    public function __construct(
        protected mixed $attribute,
        protected mixed $value = null,
        protected bool $is = true,
    )
    {
    }

    /**
     * Generate SQL statement.
     *
     * @return string
     */
    abstract protected function buildSQL(): string;

    /**
     * Get SQL statement (cached after first build).
     *
     * @return string
     */
    public function getSQL(): string
    {
        if ($this->sql === null) {
            $this->sql = $this->buildSQL();
        }
        return $this->sql;
    }

    /**
     * Get generated SQL statement.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getSQL();
    }
}
