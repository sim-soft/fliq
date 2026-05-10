<?php

namespace Simsoft\DB\MySQL\Builder\Aggregations;

use Simsoft\DB\MySQL\Builder\ActiveQuery;
use Simsoft\DB\MySQL\Builder\Builder;
use Simsoft\DB\MySQL\Builder\Raw;

/**
 * Aggregate Query Builder class.
 */
abstract class Aggregate extends Builder
{
    /** @var string Aggregate function name */
    protected string $functionName = 'COUNT';

    /** @var array|string[] SQL Aggregation functions */
    protected array $aggregateFunctions = ['AVG', 'COUNT', 'MAX', 'MIN', 'SUM'];

    /** @var bool Enable distinct select. Default: false. */
    protected bool $distinct = false;

    /** @var string|ActiveQuery|Raw Update condition. */
    protected string|ActiveQuery|Raw $condition = '';

    /**
     * Constructor.
     *
     * @param string $table The table name.
     * @param string $attribute The attribute name or value.
     * @param string|null $as
     */
    public function __construct(
        protected string $table,
        protected string $attribute,
        protected ?string $as = null
    )
    {
    }

    /**
     * Enable select distinct.
     *
     * @param bool $enable Enable distinct.
     * @return $this
     */
    public function distinct(bool $enable = true): self
    {
        $this->distinct = $enable;
        return $this;
    }

    /**
     * Set update condition
     *
     * @param string|ActiveQuery|Raw $condition
     * @return $this
     */
    public function condition(string|ActiveQuery|Raw $condition): self
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * {@inheritdoc }
     */
    protected function buildSQL(): string
    {
        $this->alias(trim($this->table, '`'));

        $select = $this->distinct
            ? "SELECT $this->functionName(DISTINCT {$this->queryAttribute($this->attribute)})"
            : ($this->attribute === '*'
                ? "SELECT $this->functionName($this->attribute)"
                : "SELECT $this->functionName({$this->queryAttribute($this->attribute)})");

        $sql = implode(' ', array_filter([
            $select,
            $this->as ? "AS `$this->as`" : null,
            "FROM $this->table",
            $this->getCondition(),
        ]));

        return $this->getQualifiedSQL($sql);
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
                $this->condition->getJoinSQL(),
                $this->condition->getWhereSQL(),
                $this->condition->getGroupSQL(),
                $this->condition->getHavingSQL(),
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

    /**
     * Get aggregate value.
     *
     * @return mixed
     */
    public function queryScalar(): mixed
    {
        $data = $this->query($this);
        return $data[0][$this->as] ?? 0;
    }
}
