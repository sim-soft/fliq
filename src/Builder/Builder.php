<?php

namespace Simsoft\DB\MySQL\Builder;

use Simsoft\DB\MySQL\Interfaces\Executable;
use Simsoft\DB\MySQL\Traits\Binds;
use Simsoft\DB\MySQL\Traits\Execute;
use Simsoft\DB\MySQL\Traits\PlaceHolder;
use Simsoft\DB\MySQL\Traits\Qualifier;

/**
 * Query Class
 *
 */
abstract class Builder implements Executable
{
    use PlaceHolder, Binds, Qualifier, Execute;

    /** @var string|null SQL statement. */
    private ?string $sql = null;

    /**
     * Build SQL statement.
     *
     * @return string
     */
    abstract protected function buildSQL(): string;

    /**
     * Get SQL statement.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getSQL();
    }

    /**
     * {@inheritdoc }
     */
    public function getSQL(): string
    {
        if ($this->sql === null) {
            $this->sql = $this->buildSQL();
        }
        return $this->sql;
    }
}
