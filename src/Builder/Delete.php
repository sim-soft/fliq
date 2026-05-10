<?php

namespace Simsoft\DB\MySQL\Builder;

use Simsoft\DB\MySQL\Traits\Condition;
use Simsoft\DB\MySQL\Traits\Ignore;
use Simsoft\DB\MySQL\Traits\LowPriority;

/**
 * Delete Query Builder Class.
 */
class Delete extends Builder
{
    use LowPriority, Ignore, Condition;

    /** @var bool */
    protected bool $quick = false; // used by delete operation only

    /**
     * Constructor.
     *
     * @param string $table Table name.
     * @param string|ActiveQuery|Raw|null $condition
     */
    public function __construct(protected string $table, string|ActiveQuery|Raw|null $condition = null)
    {
        if ($condition instanceof ActiveQuery) {
            $this->setPlaceHolder($condition->getPlaceHolder());
        }
        $this->condition($condition);
    }

    /**
     * Enable quick delete.
     *
     * @return $this
     */
    public function quick(): self
    {
        $this->quick = true;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $sql = implode(' ', array_filter([
            'DELETE',
            $this->lowPriorityModifier(),
            $this->quick ? 'QUICK' : null,
            $this->ignoreModifier(),
            "FROM `$this->table`",
            $this->getCondition(),
        ]));

        return $this->getQualifiedSQL($sql);
    }
}
