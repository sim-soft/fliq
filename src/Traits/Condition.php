<?php

namespace Simsoft\DB\Traits;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * Condition trait.
 */
trait Condition
{
    // Binds is deliberately not imported here. Every user of this trait is a
    // Builder, which already provides it — and which overrides getBinds() to
    // build the statement first, since the values are produced by the same pass
    // that emits the placeholders they fill. Importing it again would flatten
    // the plain accessor into Update and Delete, where it takes precedence over
    // the parent's override: reading the binds before the SQL then answered
    // null for a statement that plainly had them.
    use ConditionClause;

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
        $this->invalidateSQL();
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
            $this->absorbBinds($this->condition->getBinds());

            // An ActiveQuery's sections arrive with their own keywords, so this
            // only has emptiness left to decide.
            return $this->normalizeConditionClause($condition);
        }

        if ($this->condition instanceof Raw) {
            $condition = $this->condition->getSQL();
            $this->absorbBinds($this->condition->getBinds());
            return $this->normalizeConditionClause($condition);
        }

        if (is_string($this->condition)) {
            return $this->normalizeConditionClause($this->condition);
        }

        return null;
    }
}
