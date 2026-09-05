<?php

namespace Simsoft\DB\Builder\Aggregations;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Builder;
use Simsoft\DB\Builder\Raw;

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
     * @return $this
     */
    public function distinct(): self
    {
        $this->distinct = true;
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
        $conditionAlias = ($this->condition instanceof ActiveQuery) ? $this->condition->getAlias() : null;
        $tableAlias = $conditionAlias ?? trim($this->table, '`"');
        $this->alias($tableAlias);

        $select = $this->distinct
            ? "SELECT $this->functionName(DISTINCT {$this->queryAttribute($this->attribute)})"
            : ($this->attribute === '*'
                ? "SELECT $this->functionName($this->attribute)"
                : "SELECT $this->functionName({$this->queryAttribute($this->attribute)})");

        $from = "FROM " . $this->quote(trim($this->table, '`"'));
        if ($conditionAlias !== null && $conditionAlias !== trim($this->table, '`"')) {
            $from .= ' ' . $this->quote($conditionAlias);
        }

        $sql = implode(' ', array_filter([
            $select,
            $this->as ? "AS " . $this->quote($this->as) : null,
            $from,
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
        if ($this->condition instanceof ActiveQuery) {
            $condition = implode(' ', array_filter([
                $this->condition->getJoinSQL(),
                $this->condition->getWhereSQL(),
                $this->condition->getGroupSQL(),
                $this->condition->getHavingSQL(),
                $this->condition->getLimitSQL(),
            ]));

            // Only the sections re-emitted above. getBinds() would also hand
            // over the source query's SELECT and UNION values, whose
            // placeholders are not in this statement — the driver was given
            // more values than it had positions for and refused to run it.
            $binds = $this->condition->getConditionBinds();
            if ($binds !== null) {
                $this->appendBinds($binds);
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

        if ($this->condition !== '') {
            return 'WHERE ' . trim($this->condition);
        }

        return null;
    }

    /**
     * Get aggregate value.
     *
     * @return mixed
     */
    public function queryScalar(): mixed
    {
        $data = $this->query($this);
        $row = $data[0] ?? [];

        if ($this->as !== null) {
            return $row[$this->as] ?? 0;
        }

        // No alias was requested, so no AS clause was emitted and the driver
        // names the column after the expression itself ("COUNT(*)"). Looking up
        // a null key would coerce to '' and miss, silently yielding 0 for a
        // perfectly good result. The aggregate is the only selected column, so
        // take it positionally instead.
        $value = reset($row);

        return $value === false || $value === null ? 0 : $value;
    }
}
