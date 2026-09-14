<?php

namespace Simsoft\DB\Builder;

use Simsoft\DB\Interfaces\Executable;
use Simsoft\DB\Traits\Binds;
use Simsoft\DB\Traits\Execute;
use Simsoft\DB\Traits\PlaceHolder;
use Simsoft\DB\Traits\Qualifier;

/**
 * Query Class
 *
 */
abstract class Builder implements Executable
{
    use PlaceHolder;
    use Qualifier;
    use Binds {
        Binds::getBinds as private binds;
    }
    use Execute {
        Execute::withConnection as private setConnection;
    }

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
     * {@inheritdoc}
     *
     * The statement is quoted for a particular grammar, so it is no more valid
     * across a connection change than the cached grammar the parent discards.
     */
    public function withConnection(?string $connect = null): static
    {
        $this->invalidateSQL();

        return $this->setConnection($connect);
    }

    /**
     * {@inheritdoc }
     */
    public function getSQL(): string
    {
        if ($this->sql === null) {
            // buildSQL() appends a bind for every placeholder it emits, so it
            // cannot be run twice over the same binds without doubling them.
            // Clearing first makes the build idempotent, which is what lets the
            // cache be dropped and rebuilt at all.
            $this->clearBinds();
            $this->sql = $this->buildSQL();
        }
        return $this->sql;
    }

    /**
     * {@inheritdoc}
     *
     * The values are produced by the same pass that emits the placeholders they
     * fill, so the statement has to have been built before there are any to
     * return. Insert, Update, Delete and Upsert have always worked this way —
     * getBinds() before getSQL() answered null for a statement that plainly had
     * values — while Select collected its condition's binds in condition()
     * instead and so answered before any build. Building here removes the
     * difference: the two reads agree with each other in either order, on every
     * builder.
     *
     * @return array<int, mixed>|null Null when no binds exist.
     */
    public function getBinds(): ?array
    {
        $this->getSQL();

        return $this->binds();
    }

    /**
     * Discard the cached statement so the next read rebuilds it.
     *
     * getSQL() memoised and nothing ever invalidated, so the first read froze
     * the statement: every mutation after it was silently dropped. Reading the
     * SQL is not an unusual thing to do mid-build — dump(), dd(), explain() and
     * __toString() all do it, and DB::sqlOnly() exists to hand back a builder
     * precisely so it can be inspected — so `$b->getSQL(); $b->returning('id');`
     * executed without the RETURNING clause, and the documented
     * inspect-then-run flow ran a statement that was not the one inspected.
     *
     * Every mutator calls this. The rebuild is safe because getSQL() clears the
     * binds before building.
     *
     * @return void
     */
    protected function invalidateSQL(): void
    {
        $this->sql = null;
    }
}
