<?php

namespace Simsoft\DB\Builder\Conditions;

use InvalidArgumentException;
use Simsoft\DB\Builder\Clauses\Clause;

/**
 * Class BetweenDateCondition
 *
 */
class BetweenDateCondition extends Clause
{
    /** @var null|int The default interval value. */
    protected ?int $interval = null;

    /**
     * Set interval
     *
     * @param int $value The interval value. Default: 1
     * @return self
     */
    public function interval(int $value): self
    {
        $this->interval = $value;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        return $this->interval === null
            ? $this->getDateSQL()
            : $this->getDateIntervalSQL();
    }

    /**
     * Get date interval SQL.
     *
     * @return string
     */
    protected function getDateIntervalSQL(): string
    {
        $this->appendBinds($this->value);
        $attribute = $this->queryAttribute($this->attribute);

        if ($this->is) {
            return "$attribute >= {$this->getPlaceHolder()}"
                . " AND $attribute < {$this->getPlaceHolder()} + INTERVAL $this->interval DAY";
        }

        // Negating "inside the window" means outside either end, so the two
        // comparisons are joined with OR. Joining them with AND asks for a date
        // both before the window and after it, which no row can satisfy.
        // Parenthesised so a surrounding AND cannot bind to just one half.
        return "($attribute < {$this->getPlaceHolder()}"
            . " OR $attribute >= {$this->getPlaceHolder()} + INTERVAL $this->interval DAY)";
    }

    /**
     * Get normal between date SQL.
     *
     * @return string
     * @throws InvalidArgumentException If neither a start nor an end date is given.
     */
    protected function getDateSQL(): string
    {
        [$startDate, $endDate] = $this->value;
        $attribute = $this->queryAttribute($this->attribute);

        if ($startDate && $endDate) {
            return $this->buildBothDatesSQL($attribute, $startDate, $endDate);
        }

        if ($startDate) {
            return $this->buildStartOnlySQL($attribute, $startDate);
        }

        if ($endDate) {
            return $this->buildEndOnlySQL($attribute, $endDate);
        }

        // Returning '' here left an empty slot in the condition list, which the
        // builder joins with its neighbours: on its own that produced a bare
        // `WHERE`, and next to another condition a dangling operator
        // (`WHERE id = ? AND`). Either way the query died with a syntax error
        // far from the call that caused it, so the omission is reported here.
        throw new InvalidArgumentException(sprintf(
            'betweenDate() on "%s" needs a start date, an end date, or both; got neither.',
            is_scalar($this->attribute) ? (string)$this->attribute : get_debug_type($this->attribute)
        ));
    }

    /**
     * Build SQL when both start and end dates are provided.
     *
     * @param string $attribute The quoted attribute expression.
     * @param string $startDate The start date value.
     * @param string $endDate The end date value.
     * @return string
     */
    private function buildBothDatesSQL(string $attribute, string $startDate, string $endDate): string
    {
        $this->appendBinds([$startDate, $endDate]);

        if ($this->is) {
            return "$attribute >= {$this->getPlaceHolder()} AND $attribute <= {$this->getPlaceHolder()}";
        }

        // A row outside the range is before the start *or* after the end.
        // Using AND asks for both at once, so the condition was unsatisfiable
        // and notBetweenDate() matched nothing at all. Parenthesised so a
        // surrounding AND cannot bind to just one half.
        return "($attribute < {$this->getPlaceHolder()} OR $attribute > {$this->getPlaceHolder()})";
    }

    /**
     * Build SQL when only the start date is provided.
     *
     * @param string $attribute The quoted attribute expression.
     * @param string $startDate The start date value.
     * @return string
     */
    private function buildStartOnlySQL(string $attribute, string $startDate): string
    {
        $this->appendBinds($startDate);
        return $this->is
            ? "$attribute >= {$this->getPlaceHolder()}"
            : "$attribute < {$this->getPlaceHolder()}";
    }

    /**
     * Build SQL when only the end date is provided.
     *
     * @param string $attribute The quoted attribute expression.
     * @param string $endDate The end date value.
     * @return string
     */
    private function buildEndOnlySQL(string $attribute, string $endDate): string
    {
        $this->appendBinds($endDate);
        return $this->is
            ? "$attribute <= {$this->getPlaceHolder()}"
            : "$attribute > {$this->getPlaceHolder()}";
    }
}
