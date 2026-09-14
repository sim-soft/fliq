<?php

namespace Simsoft\DB\Builder;

/**
 * Select Query Builder Class.
 */
class Select extends Builder
{
    /** @var bool Enable distinct select. Default: false. */
    protected bool $distinct = false;

    /**
     * @var string|ActiveQuery|Raw The condition source, rendered at build time.
     *
     * This used to be the rendered string, with the source's binds absorbed by
     * condition() as it rendered. Calling condition() a second time replaced the
     * string but kept the binds the first one had contributed, so the statement
     * carried values for placeholders it no longer had and the driver refused
     * it. Holding the source and rendering once, during the build, means the
     * binds are produced by the same pass that produces the placeholders.
     */
    protected string|ActiveQuery|Raw $condition = '';

    /**
     * Constructor.
     *
     * @param string $table The table name.
     * @param array<int, string> $selects The select fields.
     * @param string|ActiveQuery|Raw $condition
     */
    public function __construct(
        protected string $table,
        protected array $selects = [],
        string|ActiveQuery|Raw $condition = ''
    )
    {
        $this->condition($condition);
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
     * Set query condition.
     *
     * @param string|ActiveQuery|Raw $query The condition source.
     * @return $this
     */
    public function condition(string|ActiveQuery|Raw $query): self
    {
        // An empty string means "no condition given" and must not displace one
        // already set, which is what the constructor's default relies on.
        if (is_string($query) && trim($query) === '') {
            return $this;
        }

        $this->condition = $query;
        $this->invalidateSQL();

        return $this;
    }

    /**
     * Render the condition, collecting the values its placeholders take.
     *
     * @return string
     */
    private function buildConditionSQL(): string
    {
        $condition = $this->condition;

        if ($condition instanceof ActiveQuery) {
            $sql = implode(' ', array_filter([
                $condition->getJoinSQL(),
                $condition->getWhereSQL(),
                $condition->getGroupSQL(),
                $condition->getHavingSQL(),
                $condition->getOrderSQL(),
                $condition->getLimitSQL(),
            ]));

            // Only the sections re-emitted above, in the order they are emitted.
            // getBinds() would also hand over the source query's SELECT, FROM
            // and UNION values, whose placeholders are not in this statement:
            // borrowing the conditions of a query that selected a Raw column
            // left the driver holding more values than it had positions for,
            // and it refused to run the statement at all.
            $this->absorbBinds($condition->getConditionBinds());
            $this->absorbBinds($condition->getOrderBinds());

            return $sql;
        }

        if ($condition instanceof Raw) {
            $this->absorbBinds($condition->getBinds());

            return 'WHERE ' . $condition->getSQL();
        }

        $trimmed = trim($condition);
        if ($trimmed === '') {
            return '';
        }

        return str_starts_with(strtoupper($trimmed), 'WHERE ')
            ? $trimmed
            : 'WHERE ' . $trimmed;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        $selectSQL = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '');
        $selectSQL .= empty($this->selects)
            ? $this->queryAttribute('*')
            : implode(', ', $this->selects);

        $sql = implode(' ', array_filter([
            $selectSQL,
            "FROM $this->table",
            $this->buildConditionSQL(),
        ]));

        return $this->getQualifiedSQL($sql);
    }
}
