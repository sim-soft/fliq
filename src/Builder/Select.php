<?php

namespace Simsoft\DB\MySQL\Builder;

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
     * @param array $selects The select fields.
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
     * @param string|ActiveQuery|Raw $query
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
        } elseif ($query instanceof Raw) {
            $this->condition = "WHERE $query";
            $this->appendBinds($query->getBinds());
        } else {
            $this->condition = 'WHERE ' . trim($query);
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
