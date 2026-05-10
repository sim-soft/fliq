<?php

namespace Simsoft\DB\MySQL\Iterators;

use Iterator;
use Simsoft\DB\MySQL\Builder\ActiveQuery;

/**
 * ModelIterator
 */
class ModelCollection implements Iterator
{
    protected int $maxPerBatch = 500;
    protected int $page = 1;
    protected array $data = [];
    protected ?int $count = null;
    protected ?ActiveQuery $query = null;

    protected function getQuery(): ActiveQuery
    {
        return $this->query;
    }

    public function batch(int $total): self
    {
        $this->maxPerBatch = $total;
        return $this;
    }

    public function count(): int
    {
        if ($this->count === null) {
            $this->count = $this->getQuery()->count();
        }
        return $this->count;
    }

    protected function fetchData(): void
    {
        $this->data = $this->getQuery()->page($this->page++, $this->maxPerBatch)->get();
    }

    public function current(): mixed
    {
        return current($this->data) ?? null;
    }

    public function next(): void
    {
        next($this->data);
    }

    public function key(): mixed
    {
        return current($this->data)?->getKey();
    }

    public function valid(): bool
    {
        if (key($this->data) === null) {
            $this->fetchData();
        }

        return key($this->data) !== null;
    }

    public function rewind(): void
    {
        $this->page = 1;
        $this->fetchData();
    }
}
