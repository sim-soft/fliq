<?php

namespace Simsoft\DB\Builder\Conditions;

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
        return $this->is
            ? "$attribute >= {$this->getPlaceHolder()} AND $attribute < {$this->getPlaceHolder()} + INTERVAL $this->interval DAY"
            : "$attribute < {$this->getPlaceHolder()} AND $attribute >= {$this->getPlaceHolder()} + INTERVAL $this->interval DAY";
    }

    /**
     * Get normal between date SQL.
     *
     * @return string
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

        return '';
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
        return $this->is
            ? "$attribute >= {$this->getPlaceHolder()} AND $attribute <= {$this->getPlaceHolder()}"
            : "$attribute < {$this->getPlaceHolder()} AND $attribute > {$this->getPlaceHolder()}";
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
