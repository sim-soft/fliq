<?php

namespace Simsoft\DB\Traits;

/**
 * Pattern matching with LIKE.
 *
 * Builds the LIKE family, including the case-insensitive variants, which differ
 * by engine: PostgreSQL has ILIKE, while the others lower both sides.
 *
 * These four methods are case-SENSITIVE by default. The whereLike() family on
 * ActiveQuery wraps them with the opposite default, so like() and whereLike()
 * called with the same arguments do not produce the same SQL and, on
 * PostgreSQL, do not return the same rows.
 */
trait Likeable
{
    /**
     * Like condition.
     *
     * Case-sensitive by default (plain LIKE). Set caseSensitive: false for case-insensitive matching.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $is the comparison operator (true = LIKE, false = NOT LIKE)
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param string $logicalOperator The logical operator. Either 'AND' or 'OR'.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: true.
     * @return static
     */
    public function like(
        string       $attribute,
        string|array $value,
        bool         $is = true,
        bool         $matchAll = true,
        string       $logicalOperator = 'AND',
        bool         $caseSensitive = true
    ): static
    {
        // No patterns means nothing to match on. Building the compound form
        // anyway produced an empty group — `WHERE ()` alone, or a dangling
        // `AND ()` beside another condition — which the server rejects, so an
        // empty search box took the whole query down. The condition is skipped
        // instead, as in() already does for an empty value list.
        if ($value === []) {
            return $this;
        }

        [$col, $operator, $useLowerBind] = $this->prepareLikeColumn($attribute, $is, $caseSensitive);

        // Fast path: single string value (most common)
        if (is_string($value)) {
            $sql = $this->buildLikePart($col, $operator, $useLowerBind);
            $this->addConditionSQL($sql, $value, $logicalOperator);
            return $this;
        }

        // Array of values — build compound condition
        $joiner = $matchAll ? ' AND ' : ' OR ';
        $parts = array_fill(0, count($value), $this->buildLikePart($col, $operator, $useLowerBind));
        $binds = array_values($value);

        $sql = '(' . implode($joiner, $parts) . ')';
        $this->addConditionSQL($sql, $binds, $logicalOperator);
        return $this;
    }

    /**
     * Prepare the column expression and operator for LIKE based on case sensitivity.
     *
     * @param string $attribute The attribute name.
     * @param bool $is Whether positive (LIKE) or negated (NOT LIKE).
     * @param bool $caseSensitive Whether the comparison is case-sensitive.
     * @return array{0: string, 1: string, 2: bool} [column, operator, useLowerBind]
     */
    private function prepareLikeColumn(string $attribute, bool $is, bool $caseSensitive): array
    {
        $col = $this->queryAttribute($attribute);
        $operator = $is ? 'LIKE' : 'NOT LIKE';

        if ($caseSensitive) {
            return [$col, $operator, false];
        }

        if ($this->getGrammar()->getDriverName() === 'pgsql') {
            return [$col, $is ? 'ILIKE' : 'NOT ILIKE', false];
        }

        return ["LOWER($col)", $operator, true];
    }

    /**
     * Build a single LIKE expression segment.
     *
     * @param string $col The column expression.
     * @param string $operator The LIKE operator.
     * @param bool $useLowerBind Whether to wrap the placeholder in LOWER().
     * @return string
     */
    private function buildLikePart(string $col, string $operator, bool $useLowerBind): string
    {
        return $useLowerBind ? "$col $operator LOWER(?)" : "$col $operator ?";
    }

    /**
     * Or like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: true.
     * @return static
     */
    public function orLike(string $attribute, string|array $value, bool $matchAll = true, bool $caseSensitive = true): static
    {
        return $this->like($attribute, $value, true, $matchAll, 'OR', $caseSensitive);
    }

    /**
     * Not like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: true.
     * @return static
     */
    public function notLike(string $attribute, string|array $value, bool $matchAll = true, bool $caseSensitive = true): static
    {
        return $this->like($attribute, $value, false, $matchAll, 'AND', $caseSensitive);
    }

    /**
     * Or not like condition.
     *
     * @param string $attribute the attribute name
     * @param string|string[] $value the like's value
     * @param bool $matchAll Whether to match all values in the array. Default: true
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: true.
     * @return static
     */
    public function orNotLike(string $attribute, string|array $value, bool $matchAll = true, bool $caseSensitive = true): static
    {
        return $this->like($attribute, $value, false, $matchAll, 'OR', $caseSensitive);
    }

    /**
     * Where LIKE, case-insensitive by default.
     *
     * Not an alias for like(), despite the shared implementation: this family
     * defaults $caseSensitive to false where like() defaults it to true. The
     * same call through each produces different SQL, and on PostgreSQL — where
     * ILIKE and LIKE genuinely differ rather than deferring to the column
     * collation — different rows. Pass $caseSensitive explicitly if it matters
     * which you get.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return static
     */
    public function whereLike(
        string $attribute,
        string $value,
        bool $caseSensitive = false,
        string $logicalOperator = 'AND'
    ): static {
        return $this->like($attribute, $value, true, true, $logicalOperator, $caseSensitive);
    }

    /**
     * Or where LIKE, case-insensitive by default.
     *
     * Not an alias for orLike() — see whereLike() for why.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @return static
     */
    public function orWhereLike(string $attribute, string $value, bool $caseSensitive = false): static
    {
        return $this->like($attribute, $value, true, true, 'OR', $caseSensitive);
    }

    /**
     * Where NOT LIKE, case-insensitive by default.
     *
     * Not an alias for notLike() — see whereLike() for why.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @param string $logicalOperator The logical operator. Default: 'AND'.
     * @return static
     */
    public function whereNotLike(
        string $attribute,
        string $value,
        bool $caseSensitive = false,
        string $logicalOperator = 'AND'
    ): static {
        return $this->like($attribute, $value, false, true, $logicalOperator, $caseSensitive);
    }

    /**
     * Or where NOT LIKE, case-insensitive by default.
     *
     * Not an alias for orNotLike() — see whereLike() for why.
     *
     * @param string $attribute The attribute name.
     * @param string $value The LIKE pattern.
     * @param bool $caseSensitive Whether the comparison is case-sensitive. Default: false.
     * @return static
     */
    public function orWhereNotLike(string $attribute, string $value, bool $caseSensitive = false): static
    {
        return $this->like($attribute, $value, false, true, 'OR', $caseSensitive);
    }
}
