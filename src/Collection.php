<?php

namespace Simsoft\DB\MySQL;

use Exception;
use Iterator;
use Simsoft\DB\MySQL\Builder\ActiveQuery;

/**
 * Collection class.
 *
 */
class Collection implements Iterator
{
    /** @var int|null Total count of current query. */
    protected ?int $totalCount = null;

    /** @var bool Determine is fetch record for specific page only. */
    protected bool $fetchPage = false;

    /** @var int Current page. */
    protected int $page = 0;

    /** @var int Maximum fetched records per page. */
    protected int $size = 100;

    /** @var int Current record pointer position. */
    protected int $position = 0;

    /** @var bool Each strategy. */
    protected bool $each = true;

    /** @var array|Iterator|null Record batch. */
    protected array|Iterator|null $batch = null;

    /** @var mixed|null Current record value. */
    private mixed $value = null;

    /** @var mixed|null Current record key. */
    private mixed $key = null;

    /** @var bool Output result as array */
    protected bool $toArray = false;

    /** @var int|null Records limit */
    protected ?int $limit = null;

    /** @var int Limit count */
    protected int $limitCount = 0;

    /** @var bool Debug mode. Default: false. */
    protected bool $debugMode = false;

    /**
     * Constructor
     *
     * @param ActiveQuery $query
     */
    public function __construct(protected ActiveQuery $query)
    {

    }

    /**
     * Limit records to be fetched.
     *
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;
        $this->size = $limit;
        return $this;
    }

    /**
     * Set current page.
     *
     * @param int $page
     * @param int|null $limit
     * @return $this
     */
    public function page(int $page = 1, ?int $limit = null): static
    {
        $this->page = $page - 1;
        $this->fetchPage = true;
        if ($limit) {
            $this->limit($limit);
        }
        return $this;
    }

    /**
     * Get total record count.
     *
     * @param string $field Field to be used for count.
     * @return int
     * @throws Exception
     */
    public function count(string $field = '*'): int
    {
        if ($this->totalCount === null) {
            $this->totalCount = $this->query->count($field);
        }
        return $this->totalCount;
    }

    /**
     * Get record count of each page.
     *
     * @throws Exception
     */
    public function getPageCount(): int
    {
        if ($this->batch === null) {
            $this->next();
        }
        return count($this->batch);
    }

    /**
     * Enable debug mode.
     *
     * @return $this
     */
    public function debug(): static
    {
        $this->debugMode = true;
        return $this;
    }

    /**
     * Size of each iteration.
     *
     * @param int $size
     * @return $this
     */
    public function each(int $size = 100): static
    {
        $this->size = $size;
        return $this;
    }

    /**
     * Use batch strategy.
     *
     * @param int $size Size of each batch.
     * @return $this
     */
    public function batch(int $size = 100): static
    {
        $this->size = $size;
        $this->each = false;
        return $this;
    }

    /**
     * Get array attributes.
     *
     * @return $this
     */
    public function toArray(): static
    {
        $this->toArray = true;
        return $this;
    }

    /**
     * Reset
     *
     * @return void
     */
    protected function reset(): void
    {
        $this->page = 0;
        $this->position = 0;
    }

    /**
     * Perform rewind.
     *
     * @throws Exception
     */
    public function rewind(): void
    {
        $this->next();
    }

    /**
     * Get next batch
     *
     * @throws Exception
     */
    public function next(): void
    {
        if ($this->fetchPage && $this->position === $this->size) {
            $this->batch = null;
            return;
        }

        if ($this->batch === null || !$this->each || next($this->batch) === false) {
            $query = clone $this->query->page(++$this->page, $this->size);

            if ($this->debugMode) {
                print 'SQL query: ' . (clone $query)->getFullSQL() . "\n";
            }

            $this->batch = iterator_to_array($this->toArray ? $query->getArray() : $query->all());
            $this->position = 0;
            reset($this->batch);
        }

        if ($this->each) {
            $this->value = current($this->batch);
            if ($this->query->hasIndexBy()) {
                $this->key = key($this->batch);
            } elseif (key($this->batch) !== null) {
                $this->key = $this->key === null ? 0 : $this->key + 1;
            } else {
                $this->key = null;
            }
        } else {
            $this->value = $this->batch;
            $this->key = $this->key === null ? 0 : $this->key + 1;
        }

        ++$this->position;
    }

    /**
     * Determine next record is valid.
     *
     * @return bool
     */
    public function valid(): bool
    {
        if ($this->limit && ++$this->limitCount > $this->limit) {
            return false;
        }

        return !empty($this->batch);
    }

    /**
     * Get the key of current record.
     *
     * @return mixed
     */
    public function key(): mixed
    {
        return $this->key;
    }

    /**
     * Get current record value.
     *
     * @return mixed
     */
    public function current(): mixed
    {
        return $this->query->getPluckAttribute() ? $this->value[$this->query->getPluckAttribute()] : $this->value;
    }
}
