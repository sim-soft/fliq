<?php

namespace Simsoft\DB\Traits;

/**
 * Turns a condition fragment into the clause a statement can be finished with.
 */
trait ConditionClause
{
    /**
     * Clause keywords a condition may legitimately open with.
     *
     * A fragment starting with one of these is already a clause and is left
     * alone. The join keywords must be followed by JOIN, because LEFT and RIGHT
     * are also string functions and `LEFT(username, 1) = ?` is a condition, not
     * a join.
     */
    private const string LEADING_CLAUSE = '/^(?:WHERE|ORDER\s+BY|GROUP\s+BY|HAVING|LIMIT|OFFSET'
        . '|JOIN|STRAIGHT_JOIN|(?:INNER|CROSS|NATURAL|LEFT|RIGHT|FULL)(?:\s+OUTER)?\s+JOIN)\b/i';

    /**
     * Prefix WHERE unless the fragment already opens a clause.
     *
     * Only string conditions used to get the keyword; a Raw and an ActiveQuery
     * were emitted verbatim. That is right for an ActiveQuery, whose sections
     * arrive with their own keywords, and wrong for a Raw — `new Raw('n > ?')`
     * is how every documented Raw condition is written, and it produced
     * `DELETE FROM t n > ?`. Meanwhile a string that did say WHERE got a second
     * one. Both are syntax errors, so both were reported by the server rather
     * than silently, but neither had to happen.
     *
     * @param string $condition The assembled condition fragment.
     * @return string|null The clause, or null when the fragment is empty.
     */
    protected function normalizeConditionClause(string $condition): ?string
    {
        $condition = trim($condition);

        if ($condition === '') {
            return null;
        }

        if (preg_match(self::LEADING_CLAUSE, $condition) === 1) {
            return $condition;
        }

        return 'WHERE ' . $condition;
    }
}
