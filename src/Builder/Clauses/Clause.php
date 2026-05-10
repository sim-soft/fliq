<?php

namespace Simsoft\DB\MySQL\Builder\Clauses;

use Simsoft\DB\MySQL\Traits\Binds;
use Simsoft\DB\MySQL\Traits\PlaceHolder;
use Simsoft\DB\MySQL\Traits\Qualifier;

/**
 * Class Clause
 *
 */
abstract class Clause
{
    use PlaceHolder, Qualifier, Binds;

    private ?string $sql = null;

    /**
     * Constructor
     */
    public function __construct(
        protected mixed $attribute,
        protected mixed $value = null,
        protected bool $is = true,
    )
    {
        if (is_numeric($this->value) && !str_starts_with($this->value, '0')) {
            $this->value = str_contains($this->value, '.')
                ? floatval($this->value)
                : intval($this->value);
        }
    }

    /**
     * Generate SQL statement.
     *
     * @return string
     */
    abstract protected function buildSQL(): string;

    /**
     * Get SQL statement.
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
