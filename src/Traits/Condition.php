<?php

namespace Simsoft\DB\MySQL\Traits;

use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Raw;

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
     * @param string|ActiveQuery|Raw|null $condition
     * return $this
     */
    public function condition(string|ActiveQuery|Raw|null $condition): self
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
        $condition = null;
        if ($this->condition instanceof ActiveQuery) {
            $condition = implode(' ', array_filter([
                $this->condition->getWhereSQL(),
                $this->condition->getOrderSQL(),
                $this->condition->getLimitSQL(),
            ]));
            if ($this->condition->getBinds()) {
                $this->appendBinds($this->condition->getBinds());
            }
        } elseif ($this->condition instanceof Raw) {
            $condition = $this->condition->getSQL();
            if ($this->condition->getBinds()) {
                $this->appendBinds($this->condition->getBinds());
            }
        } elseif (is_string($this->condition) && $this->condition != '') {
            $condition = 'WHERE ' . trim($this->condition);
        }
        return $condition;
    }
}
