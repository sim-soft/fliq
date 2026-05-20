<?php

namespace Simsoft\DB\Builder;

/**
 * Select Query Builder Class.
 */
class Select extends Builder
{
    /** @var bool Enable distinct select. Default: false. */
    protected bool $distinct = false;

    /** @var string Update condition. */
    protected string $condition = '';

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
        if ($query instanceof ActiveQuery) {
            $this->condition = implode(' ', array_filter([
                $query->getJoinSQL(),
                $query->getWhereSQL(),
                $query->getGroupSQL(),
                $query->getHavingSQL(),
                $query->getOrderSQL(),
                $query->getLimitSQL(),
            ]));
            $this->appendBinds($query->getBinds());
            return $this;
        }

        if ($query instanceof Raw) {
            $this->condition = "WHERE $query";
            $this->appendBinds($query->getBinds());
            return $this;
        }

        $trimmed = trim($query);
        if ($trimmed !== '') {
            $this->condition = str_starts_with(strtoupper($trimmed), 'WHERE ')
                ? $trimmed
                : 'WHERE ' . $trimmed;
        }

        return $this;
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
            $this->condition,
        ]));

        return $this->getQualifiedSQL($sql);
    }
}
