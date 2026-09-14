<?php

namespace Simsoft\DB\Traits;

use Closure;
use ReflectionFunction;

/**
 * Scoping a block of query clauses to a different alias.
 *
 * A query carries one alias, taken from its FROM table, and every unqualified
 * column name is resolved against it. That leaves no way to constrain a joined
 * table without spelling out its prefix on each column. withAlias() swaps the
 * alias for the duration of a callback so the joined table's columns can be
 * named plainly.
 *
 * @see Qualifier for the alias itself and the deferred-placeholder syntax.
 */
trait TemporaryAlias
{
    /**
     * Apply conditions with a temporary alias.
     *
     * Columns named inside the callback are qualified with $alias instead of
     * the query's own alias, so a joined table can be constrained without
     * prefixing every name by hand:
     *
     *     ->join('post p', ['p.user_id' => 'id'])
     *     ->withAlias('p', fn($q) => $q->where('view_count', '>', 0))
     *     ->where('status_code', 1)
     *     // WHERE `p`.`view_count` > ? AND `u`.`status_code` = ?
     *
     * @param string $alias The alias to use temporarily.
     * @param callable $condition The condition callback.
     * @return static
     */
    public function withAlias(string $alias, callable $condition): static
    {
        // The parameter accepts any callable, but only a closure written as a
        // literal can be rebound to $this. Anything else — an invokable object,
        // a [$obj, 'method'] pair — used to fall past the check and be dropped
        // without a word, so the call silently did nothing at all.
        $callable = $this->bindCondition($condition);

        // Simple column names are stored deferred as `{col}` and only resolved
        // in getSQL(), against whatever alias is current *then*. Since the alias
        // is restored before returning, nothing the callback added ever saw the
        // temporary one and it was qualified with the FROM table instead — the
        // opposite of the point, and valid SQL against the wrong table. Marking
        // where each list ended lets the new entries be resolved below, while
        // the alias still holds.
        $marks = [
            'conditions' => count($this->conditions),
            'selects' => count($this->selects),
            'groupBys' => count($this->groupBys),
            'having' => count($this->having),
            'orderBys' => count($this->orderBys),
        ];

        $backup = $this->getAlias();
        $this->alias($alias);

        try {
            $callable($this);
            $this->qualifyAppended($marks);
        } finally {
            // Restored even if the callback throws, so a failure inside the
            // block cannot leave the query aliased for everything after it.
            $this->alias($backup);
        }

        return $this;
    }

    /**
     * Bind a condition callback to this query where the callable allows it.
     *
     * Only a closure written as a literal can be rebound. First-class callable
     * syntax also yields a Closure, but one wrapping a named function: binding
     * that raises a warning and returns null. Those, and every non-closure
     * callable, are returned unchanged and invoked with the query as their
     * argument instead.
     *
     * @param callable $condition The callable to bind.
     * @return callable
     */
    private function bindCondition(callable $condition): callable
    {
        if (!$condition instanceof Closure || !(new ReflectionFunction($condition))->isAnonymous()) {
            return $condition;
        }

        return Closure::bind($condition, $this, static::class) ?? $condition;
    }

    /**
     * Resolve deferred placeholders in entries appended past the given marks.
     *
     * @param array<string, int> $marks Entry counts captured before the append.
     * @return void
     */
    private function qualifyAppended(array $marks): void
    {
        foreach ($marks as $property => $mark) {
            foreach (array_slice(array_keys($this->$property), $mark) as $key) {
                $entry = $this->$property[$key];
                if (is_string($entry)) {
                    $this->$property[$key] = $this->getQualifiedSQL($entry);
                }
            }
        }
    }
}
