<?php

namespace Simsoft\DB;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * CursorPaginator.
 *
 * Represents a cursor-paginated result set.
 * More efficient than offset pagination for large datasets.
 *
 * @implements IteratorAggregate<int, mixed>
 */
class CursorPaginator implements Countable, IteratorAggregate
{
    /**
     * Constructor.
     *
     * @param array<int, mixed> $data The paginated records.
     * @param int $perPage Records per page.
     * @param string|int|null $nextCursor The next cursor value.
     * @param string|int|null $previousCursor The previous cursor value.
     * @param bool $hasMore Whether there are more records.
     */
    public function __construct(
        public readonly array $data,
        public readonly int $perPage,
        public readonly string|int|null $nextCursor,
        public readonly string|int|null $previousCursor,
        public readonly bool $hasMore,
    ) {
    }

    /**
     * Get the number of items in the current page.
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
     * @return array{data: array<int, mixed>, per_page: int, next_cursor: string|int|null, previous_cursor: string|int|null, has_more: bool}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'per_page' => $this->perPage,
            'next_cursor' => $this->nextCursor,
            'previous_cursor' => $this->previousCursor,
            'has_more' => $this->hasMore,
        ];
    }
}
