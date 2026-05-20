<?php

namespace Simsoft\DB;

use Countable;
use Generator;
use IteratorAggregate;
use Simsoft\DB\Builder\ActiveQuery;
use Traversable;

/**
 * Collection class.
 *
 * Provides lazy and eager iteration over query results with
 * chunked fetching for memory efficiency.
 *
 * @implements IteratorAggregate<int|string, mixed>
 */
class Collection implements IteratorAggregate, Countable
{
    /** @var int Chunk size for lazy iteration */
    protected int $chunkSize = 100;

    /** @var bool Whether to hydrate results as models */
    protected bool $asArray = false;

    /** @var int|null Cached total count */
    protected ?int $totalCount = null;

    /** @var string|null Attribute to pluck */
    protected ?string $pluckAttribute = null;

    /** @var callable|null Filter callback applied during lazy iteration */
    protected $filterCallback = null;

    /** @var callable|null Map transformation applied during lazy iteration */
    protected $mapCallback = null;

    /**
     * Constructor.
     *
     * @param ActiveQuery $query The query to iterate over.
     */
    public function __construct(protected ActiveQuery $query)
    {
        $this->pluckAttribute = $query->getPluckAttribute();
    }

    /**
     * Set a chunk size for lazy iteration.
     *
     * @param int $size Records per chunk.
     * @return static
     */
    public function chunk(int $size): static
    {
        $this->chunkSize = $size;
        return $this;
    }

    /**
     * Return results as arrays instead of models.
     *
     * @return static
     */
    public function toArray(): static
    {
        $this->asArray = true;
        return $this;
    }

    /**
     * Get total record count (Countable interface).
     *
     * Returns the database COUNT(*). When filter() is applied, this still
     * returns the unfiltered DB count — use \count(iterator_to_array($collection))
     * to get the filtered count.
     *
     * @return int
     */
    public function count(): int
    {
        if ($this->totalCount === null) {
            $this->totalCount = $this->query->count('*');
        }

        return $this->totalCount;
    }

    /**
     * Get total record count for a specific field.
     *
     * @param string $field Field to count.
     * @return int
     */
    public function countBy(string $field): int
    {
        return $this->query->count($field);
    }

    /**
     * Get the iterator (lazy, chunked fetching).
     *
     * @return Traversable<int|string, mixed>
     */
    public function getIterator(): Traversable
    {
        return $this->lazy($this->chunkSize);
    }

    /**
     * Lazily iterate over all results in chunks.
     *
     * Yields one record at a time while fetching in batches
     * for memory efficiency.
     *
     * @param int|null $size Override the chunk size for this iteration.
     * @return Generator
     */
    public function lazy(?int $size = null): Generator
    {
        $size = $size ?? $this->chunkSize;
        $page = 0;

        while (true) {
            $query = clone $this->query;
            $query->page(++$page, $size);

            $results = iterator_to_array(
                $this->asArray ? $query->getArray() : $query->all()
            );

            if (empty($results)) {
                return;
            }

            foreach ($results as $key => $record) {
                $value = $this->resolveValue($record);

                if ($this->filterCallback !== null && !($this->filterCallback)($value, $key)) {
                    continue;
                }

                if ($this->mapCallback !== null) {
                    $value = ($this->mapCallback)($value, $key);
                }

                yield $key => $value;
            }

            if (\count($results) < $size) {
                return;
            }
        }
    }

    /**
     * Iterate in batches (yields arrays of records).
     *
     * Does not mutate the collection's chunk size.
     *
     * @param int $size Batch size.
     * @return Generator
     */
    public function batch(int $size = 100): Generator
    {
        $page = 0;

        while (true) {
            $query = clone $this->query;
            $query->page(++$page, $size);

            $results = iterator_to_array(
                $this->asArray ? $query->getArray() : $query->all()
            );

            if (empty($results)) {
                return;
            }

            yield array_map(fn($record) => $this->resolveValue($record), $results);

            if (\count($results) < $size) {
                return;
            }
        }
    }

    /**
     * Iterate one record at a time with a specific chunk size.
     *
     * Does not mutate the collection's chunk size.
     *
     * @param int $size Chunk size for internal fetching.
     * @return Generator
     */
    public function each(int $size = 100): Generator
    {
        return $this->lazy($size);
    }

    /**
     * Get a specific page of results.
     *
     * Applies filter/map callbacks if set.
     *
     * @param int $page Page number (1-based).
     * @param int $perPage Records per page.
     * @return array<int|string, mixed>
     */
    public function page(int $page, int $perPage = 50): array
    {
        $query = clone $this->query;
        $query->page($page, $perPage);

        $results = iterator_to_array(
            $this->asArray ? $query->getArray() : $query->all()
        );

        $output = [];
        foreach ($results as $key => $record) {
            $value = $this->resolveValue($record);

            if ($this->filterCallback !== null && !($this->filterCallback)($value, $key)) {
                continue;
            }

            if ($this->mapCallback !== null) {
                $value = ($this->mapCallback)($value, $key);
            }

            $output[$key] = $value;
        }

        return $output;
    }

    /**
     * Collect all results into an array (eager load).
     *
     * Use with caution on large datasets.
     *
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return iterator_to_array($this->lazy());
    }

    /**
     * Get the first record or null.
     *
     * @return mixed
     */
    public function first(): mixed
    {
        foreach ($this->lazy() as $record) {
            return $record;
        }

        return null;
    }

    /**
     * Apply a callback to filter records during lazy iteration.
     *
     * The callback receives (value, key) and should return true to keep the record.
     *
     * @param callable $callback The filter callback.
     * @return static
     */
    public function filter(callable $callback): static
    {
        $filtered = clone $this;
        $filtered->query = clone $this->query;
        $filtered->filterCallback = $callback;
        $filtered->totalCount = null; // invalidate cached count
        return $filtered;
    }

    /**
     * Apply a transformation to each record during lazy iteration.
     *
     * The callback receives (value, key) and returns the transformed value.
     *
     * @param callable $callback The map callback.
     * @return static
     */
    public function map(callable $callback): static
    {
        $mapped = clone $this;
        $mapped->query = clone $this->query;
        $mapped->mapCallback = $callback;
        return $mapped;
    }

    /**
     * Reduce the collection to a single value.
     *
     * @param callable $callback The reducer function. Signature: (mixed $carry, mixed $value, mixed $key): mixed
     * @param mixed $initial The initial value.
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $carry = $initial;
        foreach ($this->lazy() as $key => $value) {
            $carry = $callback($carry, $value, $key);
        }
        return $carry;
    }

    /**
     * Index the collection by a record attribute or callback.
     *
     * @param string|callable $key Attribute name or callback returning the key.
     * @return array<int|string, mixed>
     */
    public function indexBy(string|callable $key): array
    {
        $output = [];
        $resolver = is_callable($key)
            ? $key
            : fn($record) => \is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);

        foreach ($this->lazy() as $value) {
            $output[$resolver($value)] = $value;
        }
        return $output;
    }

    /**
     * Group the collection by a record attribute or callback.
     *
     * @param string|callable $key Attribute name or callback returning the group key.
     * @return array<int|string, array<int, mixed>>
     */
    public function groupBy(string|callable $key): array
    {
        $output = [];
        $resolver = is_callable($key)
            ? $key
            : fn($record) => \is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);

        foreach ($this->lazy() as $value) {
            $groupKey = $resolver($value);
            $output[$groupKey][] = $value;
        }
        return $output;
    }

    /**
     * Pluck a single attribute from each record.
     *
     * @param string $attribute The attribute to pluck.
     * @return static
     */
    public function pluck(string $attribute): static
    {
        $plucked = clone $this;
        $plucked->query = clone $this->query;
        $plucked->pluckAttribute = $attribute;
        return $plucked;
    }

    /**
     * Check if the collection is empty.
     *
     * Uses an efficient existence check rather than counting all records.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        if ($this->filterCallback !== null) {
            // With filter, must iterate to determine emptiness
            return $this->first() === null;
        }

        return $this->count() === 0;
    }

    /**
     * Check if the collection is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Resolve the output value for a record.
     *
     * @param mixed $record The raw record.
     * @return mixed
     */
    protected function resolveValue(mixed $record): mixed
    {
        if ($this->pluckAttribute === null) {
            return $record;
        }

        if (\is_array($record)) {
            return $record[$this->pluckAttribute] ?? null;
        }

        return $record->{$this->pluckAttribute} ?? null;
    }
}
