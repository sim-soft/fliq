<?php

namespace Simsoft\DB\Traits;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * Condition trait.
 */
trait Condition
{
    use Binds;

    /** @var string|ActiveQuery|Raw|null Query condition. */
    protected string|ActiveQuery|Raw|null $condition = null;

    /**
     * Set query condition
     *
     * @param string|ActiveQuery|Raw|null $condition The query condition.
     * @return static
     */
    public function condition(string|ActiveQuery|Raw|null $condition): static
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * Get query condition.
     *
     * @return string|null
     */
    public function getCondition(): ?string
    {
        if ($this->condition instanceof ActiveQuery) {
            $condition = implode(' ', array_filter([
                $this->condition->getWhereSQL(),
                $this->condition->getOrderSQL(),
                $this->condition->getLimitSQL(),
            ]));
            if ($this->condition->getBinds()) {
                $this->appendBinds($this->condition->getBinds());
            }
            return $condition;
        }

        if ($this->condition instanceof Raw) {
            $condition = $this->condition->getSQL();
            if ($this->condition->getBinds()) {
                $this->appendBinds($this->condition->getBinds());
            }
            return $condition;
        }

        if (is_string($this->condition) && $this->condition !== '') {
            return 'WHERE ' . trim($this->condition);
        }

        return null;
    }
}
