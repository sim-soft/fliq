<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Traits\Execute;

/**
 * Raw query class.
 *
 * Wraps a raw SQL expression with optional parameter bindings.
 *
 * ---------------------------------------------------------------------------
 * SECURITY: this is the one place the query builder stops protecting you.
 * ---------------------------------------------------------------------------
 *
 * Everywhere else, values are bound and identifiers are validated. The `$sql`
 * given here is passed to the database as written, so anything interpolated
 * into it is executed as SQL. **Never build it from request data, and never
 * from anything derived from a user** — including values read back out of the
 * database that a user put there.
 *
 * Pass values through `$binds`, never by interpolation:
 *
 * ```php
 * // Safe: the value is bound, so it can only ever be a value
 * new Raw('SELECT * FROM user WHERE score > ?', [$_GET['min']]);
 *
 * // Unsafe: the request controls the SQL itself
 * new Raw("SELECT * FROM user WHERE score > {$_GET['min']}");
 * ```
 *
 * Identifiers (table and column names) cannot be bound, so when one has to be
 * dynamic, resolve it against a list you control rather than escaping it:
 *
 * ```php
 * $sortable = ['score' => 'score', 'joined' => 'created'];
 * $column = $sortable[$_GET['sort']] ?? 'id';
 * new Raw("SELECT * FROM user ORDER BY $column DESC");
 * ```
 *
 * Prefer the query builder where it can express what you need — `where()`,
 * `orderBy()` and friends handle binding and validation for you. Reach for
 * `Raw` for engine-specific syntax the builder does not cover, and keep the
 * dynamic parts of it in bindings.
 */
class Raw implements Executable
{
    use Execute;

    /**
     * Constructor.
     *
     * @param string $sql The SQL statement, used verbatim. Must not be built
     *                    from user input — see the class docblock.
     * @param array<int, mixed>|null $binds The bind values for the SQL statement.
     *                    Put every user-supplied value here.
     */
    public function __construct(
        protected string $sql,
        protected ?array $binds = null
    ) {
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
