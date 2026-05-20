<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Traits\Execute;

/**
 * Raw query class.
 *
 * Wraps a raw SQL expression with optional parameter bindings.
 */
class Raw implements Executable
{
    use Execute;

    /**
     * Constructor.
     *
     * @param string $sql The SQL statement.
     * @param array<int, mixed>|null $binds The bind values for the SQL statement.
     */
    public function __construct(
        protected string $sql,
        protected ?array $binds = null
    )
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getSQL(): string
    {
        return trim($this->sql);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<int, mixed>|null
     */
    public function getBinds(): ?array
    {
        return $this->binds;
    }

    /**
     * Get SQL statement as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getSQL();
    }

    /**
     * Execute the raw query and return results.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array
    {
        return $this->query($this);
    }
}
