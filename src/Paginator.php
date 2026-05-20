<?php

namespace Simsoft\DB;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Paginator.
 *
 * Represents a paginated result set with metadata.
 *
 * @implements IteratorAggregate<int, mixed>
 */
class Paginator implements Countable, IteratorAggregate
{
    /**
     * Constructor.
     *
     * @param array<int, mixed> $data The paginated records.
     * @param int $total Total number of records.
     * @param int $perPage Records per page.
     * @param int $currentPage Current page number.
     * @param int $lastPage Last page number.
     */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
    ) {
    }

    /**
     * Determine if there are more pages after the current page.
     *
     * @return bool
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Determine if this is the first page.
     *
     * @return bool
     */
    public function isFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    /**
     * Determine if this is the last page.
     *
     * @return bool
     */
    public function isLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage;
    }

    /**
     * Get the next page number, or null if on the last page.
     *
     * @return int|null
     */
    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    /**
     * Get the previous page number, or null if on the first page.
     *
     * @return int|null
     */
    public function previousPage(): ?int
    {
        return $this->isFirstPage() ? null : $this->currentPage - 1;
    }

    /**
     * Get the index of the first item on this page (1-based).
     *
     * Returns null if the page is empty.
     *
     * @return int|null
     */
    public function from(): ?int
    {
        if ($this->isEmpty()) {
            return null;
        }
        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    /**
     * Get the index of the last item on this page (1-based).
     *
     * Returns null if the page is empty.
     *
     * @return int|null
     */
    public function to(): ?int
    {
        if ($this->isEmpty()) {
            return null;
        }
        return ($this->currentPage - 1) * $this->perPage + $this->count();
    }

    /**
     * Get the number of items on the current page.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Determine if the paginator has any items.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * Get the iterator over the page's records.
     *
     * @return Traversable<int, mixed>
     */
    public function getIterator(): Traversable
    {
        yield from $this->data;
    }

    /**
     * Convert the paginator to an array.
     *
     * @return array{data: array<int, mixed>, total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null, has_more_pages: bool}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'from' => $this->from(),
            'to' => $this->to(),
            'has_more_pages' => $this->hasMorePages(),
        ];
    }
}
