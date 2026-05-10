<?php

namespace Simsoft\DB\MySQL\Builder\Conditions;

use Simsoft\DB\MySQL\Builder\Clauses\Clause;
use Simsoft\DB\MySQL\Builder\Raw;

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
            $this->appendBinds([$startDate, $endDate]);
            return $this->is
                ? "$attribute >= {$this->getPlaceHolder()} AND $attribute <= {$this->getPlaceHolder()}"
                : "$attribute < {$this->getPlaceHolder()} AND $attribute > {$this->getPlaceHolder()}";
        }

        if ($startDate && $endDate === null) {
            $this->appendBinds($startDate);
            return $this->is
                ? "$attribute >= {$this->getPlaceHolder()}"
                : "$attribute < {$this->getPlaceHolder()}";
        }

        if ($startDate === null && $endDate) {
            $this->appendBinds($endDate);
            return $this->is
                ? "$attribute <= {$this->getPlaceHolder()}"
                : "$attribute > {$this->getPlaceHolder()}";
        }

        return '';
    }
}
