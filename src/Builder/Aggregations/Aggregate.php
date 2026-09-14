<?php

namespace Simsoft\DB\Builder\Aggregations;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Builder;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Traits\ConditionClause;

/**
 * Aggregate Query Builder class.
 */
abstract class Aggregate extends Builder
{
    use ConditionClause;

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
        $this->invalidateSQL();
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
        $this->invalidateSQL();
        return $this;
    }

    /**
     * {@inheritdoc }
     */
    protected function buildSQL(): string
    {
        $conditionAlias = ($this->condition instanceof ActiveQuery) ? $this->condition->getAlias() : null;
        $tableAlias = $conditionAlias ?? $this->table;
        $this->alias($tableAlias);

        $select = $this->distinct
            ? "SELECT $this->functionName(DISTINCT {$this->queryAttribute($this->attribute)})"
            : ($this->attribute === '*'
                ? "SELECT $this->functionName($this->attribute)"
                : "SELECT $this->functionName({$this->queryAttribute($this->attribute)})");

        $sql = implode(' ', array_filter([
            $select,
            $this->as ? "AS " . $this->quote($this->as) : null,
            $this->buildFromSQL($conditionAlias),
            $this->getCondition(),
        ]));

        return $this->getQualifiedSQL($sql);
    }

    /**
     * Build the FROM clause the aggregate counts over.
     *
     * @param string|null $conditionAlias The alias the source query qualifies its columns with.
     * @return string
     */
    private function buildFromSQL(?string $conditionAlias): string
    {
        // A query selecting from a sub-query has no table name, so the
        // sub-query itself is re-emitted along with the values its
        // placeholders take. Those come first: FROM precedes WHERE, and the
        // condition's own binds are appended after this by getCondition().
        $subQuery = ($this->condition instanceof ActiveQuery) ? $this->condition->getFromSubQuery() : null;
        if ($subQuery !== null) {
            $this->appendBinds($this->condition instanceof ActiveQuery ? $this->condition->getFromBinds() ?? [] : []);
            return "FROM ($subQuery) " . $this->quote((string)$conditionAlias);
        }

        // The table arrives unquoted, so it is quoted for whichever grammar the
        // aggregate's own connection names. It used to be handed over already
        // quoted and stripped back with trim($t, '`"'), which left the interior
        // quotes of "`user` `u`" in place and asked for a table by that name.
        $from = 'FROM ' . $this->quote($this->table);

        if ($conditionAlias !== null && $conditionAlias !== $this->table) {
            $from .= ' ' . $this->quote($conditionAlias);
        }

        return $from;
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
            $this->absorbBinds($this->condition->getConditionBinds());

            return $this->normalizeConditionClause($condition);
        }

        if ($this->condition instanceof Raw) {
            $condition = $this->condition->getSQL();
            $this->absorbBinds($this->condition->getBinds());
            return $this->normalizeConditionClause($condition);
        }

        return $this->normalizeConditionClause($this->condition);
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
