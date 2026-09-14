<?php

declare(strict_types=1);

namespace Simsoft\DB\Interfaces;

/**
 * A write statement that can hand back the rows it wrote.
 *
 * RETURNING is the only statement-scoped answer to "what did this write?".
 * PDO's lastInsertId() is session-scoped on PostgreSQL — it calls lastval(),
 * which reports the last sequence value this connection consumed, whoever
 * consumed it. A conflicting insert still consumes one, so a statement that
 * wrote nothing reported an id belonging to no row it wrote, and on PostgreSQL
 * to no row at all. SQLite has the matching problem from the other side: it
 * leaves the previous statement's id in place, so a skipped insert reports the
 * id of whatever was inserted before it. Only MySQL is right by construction,
 * because LAST_INSERT_ID() is per-statement and answers 0 when nothing was
 * written.
 *
 * Insert, Update and Delete each carried this contract separately, and the
 * drivers and Execute dispatched on the three class names. Upsert did not carry
 * it, which is why it was the one write builder that could not be asked — and
 * being unaskable, it fell through to the session-scoped id every time. Naming
 * the capability rather than listing the classes is what lets a builder be
 * added without also being forgotten in four places.
 */
interface ReturnsRows extends Executable
{
    /**
     * Whether this statement carries a RETURNING clause.
     *
     * True once rows have been captured as well as once the clause has been
     * asked for, so that a result already in hand is never discarded by a
     * caller that only asked whether it was worth reading.
     *
     * @return bool
     */
    public function hasReturning(): bool;

    /**
     * The rows the statement returned, if it was asked for them.
     *
     * An empty array and null are different answers: the first says the
     * statement ran and named no row, the second that it was never asked.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getReturningResult(): ?array;

    /**
     * Record the rows the statement returned.
     *
     * Called by the driver after execution; not part of the caller-facing API.
     *
     * @param array<int, array<string, mixed>> $result The returned rows.
     * @return void
     */
    public function setReturningResult(array $result): void;
}
