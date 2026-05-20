<?php

namespace Simsoft\DB\Traits;

use Simsoft\DB\Builder\Aggregations\Avg;
use Simsoft\DB\Builder\Aggregations\Count;
use Simsoft\DB\Builder\Aggregations\Max;
use Simsoft\DB\Builder\Aggregations\Min;
use Simsoft\DB\Builder\Aggregations\Sum;

/**
 * Aggregation trait.
 *
 * Provides aggregate query methods (count, sum, avg, min, max).
 */
trait Aggregation
{
    /**
     * Get AVG() value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function avg(string $attribute, ?string $alias = 'avg'): mixed
    {
        return (new Avg($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->condition($this)
            ->queryScalar();
    }

    /**
     * Get AVG(DISTINCT attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function avgDistinct(string $attribute, ?string $alias = 'avg'): mixed
    {
        return (new Avg($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->distinct()
            ->condition($this)
            ->queryScalar();
    }

    /**
     * Get COUNT() value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return int
     */
    public function count(string $attribute = '*', ?string $alias = 'total'): int
    {
        return (new Count($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get COUNT(DISTINCT attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return int
     */
    public function countDistinct(string $attribute = '*', ?string $alias = 'total'): int
    {
        $count = (new Count($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->condition(clone $this);

        if ($attribute !== '*') {
            $count->distinct();
        }

        return $count->queryScalar();
    }

    /**
     * Get total pages for the current query.
     *
     * @param int $perPage Maximum records per page.
     * @param string $attribute The attribute to count.
     * @return int
     */
    public function getTotalPages(int $perPage, string $attribute = '*'): int
    {
        return (int)ceil($this->count($attribute) / $perPage);
    }

    /**
     * Get MAX(attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function max(string $attribute, ?string $alias = 'max'): mixed
    {
        return (new Max($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get MAX(DISTINCT attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function maxDistinct(string $attribute, ?string $alias = 'max'): mixed
    {
        return (new Max($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->distinct()
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get MIN(attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function min(string $attribute, ?string $alias = 'min'): mixed
    {
        return (new Min($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get MIN(DISTINCT attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function minDistinct(string $attribute, ?string $alias = 'min'): mixed
    {
        return (new Min($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->distinct()
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get SUM(attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function sum(string $attribute, ?string $alias = 'sum'): mixed
    {
        return (new Sum($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->condition(clone $this)
            ->queryScalar();
    }

    /**
     * Get SUM(DISTINCT attribute) value.
     *
     * @param string $attribute The attribute name.
     * @param string|null $alias The alias for the result.
     * @return mixed
     */
    public function sumDistinct(string $attribute, ?string $alias = 'sum'): mixed
    {
        return (new Sum($this->getTable() ?? '', $attribute, $alias))
            ->withConnection($this->connection)
            ->distinct()
            ->condition(clone $this)
            ->queryScalar();
    }
}
