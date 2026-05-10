<?php

namespace Simsoft\DB\MySQL\Builder;

use Simsoft\DB\MySQL\Interfaces\Executable;
use Simsoft\DB\MySQL\Traits\Execute;

/**
 * Raw query class
 *
 */
class Raw implements Executable
{
    use Execute;

    /**
     * Constructor.
     *
     * @param string $sql The SQL statement.
     * @param array|null $binds The bind values for the SQL statement.
     */
    public function __construct(
        protected string $sql,
        protected ?array $binds = null
    )
    {
    }

    /**
     * {@inheritdoc }
     */
    public function getSQL(): string
    {
        return trim($this->sql);
    }

    /**
     * {@inheritdoc }
     */
    public function getBinds(): ?array
    {
        return $this->binds === null ? [] : $this->binds;
    }

    /**
     * Get default SQL statement.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getSQL();
    }

    public function __invoke(): bool|array
    {
        return $this->getBinds() === null ? $this->execute($this) : $this->get();
    }

    public function get(): array
    {
        return $this->query($this);
    }

}
