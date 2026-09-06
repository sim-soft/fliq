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

    /** @var string Pipeline stage that drops records. */
    private const STAGE_FILTER = 'filter';

    /** @var string Pipeline stage that transforms records. */
    private const STAGE_MAP = 'map';

    /** @var string|null Attribute to pluck */
    protected ?string $pluckAttribute = null;

    /**
     * Filter and map callbacks, in the order they were added.
     *
     * One ordered list rather than a slot each: with a slot per kind, a second
     * filter() or map() overwrote the first instead of composing with it, and
     * filters always ran on the raw record whatever the call order. So
     * ->filter(a)->filter(b) applied only b, ->map(a)->map(b) handed b the
     * unmapped record, and both did so silently.
     *
     * @var array<int, array{0: string, 1: callable}>
     */
    protected array $stages = [];

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
            $this->totalCount = $this->query->count();
        }

        return $this->totalCount;
    }

    /**
     * Get a total record count for a specific field.
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
        // An explicit limit is fetched as a single query, and its keys are its
        // own, so there is nothing to renumber.
        if ($this->query->hasLimit()) {
            yield from $this->yieldResults($this->fetchRows($this->query));
            return;
        }

        // Each page is fetched as its own query, so without indexBy() every page
        // is keyed from zero again and the keys repeat across pages. Iterating
        // with foreach hid that, but any caller that materialised the generator
        // — iterator_to_array(), and so all() — saw each page overwrite the one
        // before it and got back a single page's worth of records with no error.
        // A running position keeps the keys unique across the whole iteration.
        $position = 0;

        foreach ($this->rawPages(max(1, $size ?? $this->chunkSize)) as $results) {
            yield from $this->yieldResults($results, $position);
        }
    }

    /**
     * Yield resolved, filtered, and mapped results.
     *
     * @param array<int|string, mixed> $results Raw query results.
     * @param int|null $position Running position across pages, advanced in place.
     *                           Null keeps each record's own key, which is what
     *                           indexBy() asked for.
     * @return Generator
     */
    private function yieldResults(array $results, ?int &$position = null): Generator
    {
        // Keys chosen by indexBy() carry meaning and are already unique across
        // pages, so they are kept; positional ones are renumbered.
        $keepKeys = $position === null || $this->query->hasIndexBy();

        foreach ($results as $key => $record) {
            $value = $this->resolveValue($record);

            if (!$this->applyStages($value, $key)) {
                continue;
            }

            if ($keepKeys) {
                yield $key => $value;
                continue;
            }

            yield $position++ => $value;
        }
    }

    /**
     * Run the filter/map stages over one value, in the order they were added.
     *
     * Each stage sees what the stage before it produced, so a map feeds the
     * filter after it and vice versa.
     *
     * @param mixed $value The resolved record, transformed in place by maps.
     * @param int|string $key The record's key, passed to every callback.
     * @return bool False if a filter rejected the record.
     */
    private function applyStages(mixed &$value, int|string $key): bool
    {
        foreach ($this->stages as [$kind, $callback]) {
            if ($kind === self::STAGE_MAP) {
                $value = $callback($value, $key);
                continue;
            }

            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copy this collection with one more pipeline stage on the end.
     *
     * @param string $kind Either STAGE_FILTER or STAGE_MAP.
     * @param callable $callback The stage callback.
     * @return static
     */
    private function withStage(string $kind, callable $callback): static
    {
        $copy = clone $this;
        $copy->query = clone $this->query;
        $copy->stages = [...$this->stages, [$kind, $callback]];
        $copy->totalCount = null; // invalidate cached count

        return $copy;
    }

    /**
     * Iterate in batches (yields arrays of records).
     *
     * Does not mutate the collection's chunk size.
     *
     * Applies filter() and map() like every other read does. It used to skip
     * them, so batching a filtered collection yielded the raw, unfiltered rows
     * while iterating the same collection yielded the filtered ones. A batch
     * can therefore be shorter than $size, since $size is how many rows are
     * fetched per query, not how many survive.
     *
     * @param int $size Batch size.
     * @return Generator
     */
    public function batch(int $size = 100): Generator
    {
        foreach ($this->rawPages(max(1, $size)) as $results) {
            $batch = $this->resolveBatch($results);

            // A page whose records were all filtered out is not the end of the
            // data, so it is skipped rather than yielded as an empty array.
            if ($batch !== []) {
                yield $batch;
            }
        }
    }

    /**
     * Yield raw, unresolved pages of rows until the data runs out.
     *
     * @param int<1, max> $size Rows to fetch per query.
     * @return Generator<int, array<int|string, mixed>>
     */
    private function rawPages(int $size): Generator
    {
        // An explicit limit is the caller's own bound, so it is fetched as one
        // query and split locally rather than paged over.
        if ($this->query->hasLimit()) {
            yield from array_chunk($this->fetchRows($this->query), $size, true);
            return;
        }

        $page = 0;

        while (true) {
            $results = $this->fetchRows((clone $this->query)->page(++$page, $size));

            if ($results !== []) {
                yield $results;
            }

            // Only the raw page size decides whether to ask for another page.
            if (\count($results) < $size) {
                return;
            }
        }
    }

    /**
     * Run a query and collect its rows.
     *
     * @param ActiveQuery $query The query to run.
     * @return array<int|string, mixed>
     */
    private function fetchRows(ActiveQuery $query): array
    {
        return iterator_to_array($this->asArray ? $query->getArray() : $query->all());
    }

    /**
     * Resolve one page of raw rows into the records a batch should carry.
     *
     * @param array<int|string, mixed> $results Raw query results.
     * @return array<int, mixed> Surviving records, renumbered from zero.
     */
    private function resolveBatch(array $results): array
    {
        $batch = [];

        foreach ($results as $key => $record) {
            $value = $this->resolveValue($record);

            if ($this->applyStages($value, $key)) {
                $batch[] = $value;
            }
        }

        return $batch;
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
        $results = $this->fetchRows((clone $this->query)->page($page, $perPage));

        return iterator_to_array($this->yieldResults($results));
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
     * Composes with any filter or map already applied, rather than replacing
     * it: ->filter($a)->filter($b) keeps only records passing both.
     *
     * @param callable $callback The filter callback.
     * @return static
     */
    public function filter(callable $callback): static
    {
        return $this->withStage(self::STAGE_FILTER, $callback);
    }

    /**
     * Apply a transformation to each record during lazy iteration.
     *
     * The callback receives (value, key) and returns the transformed value.
     *
     * Composes with any map or filter already applied, rather than replacing
     * it: ->map($a)->map($b) passes $a's output into $b.
     *
     * @param callable $callback The map callback.
     * @return static
     */
    public function map(callable $callback): static
    {
        return $this->withStage(self::STAGE_MAP, $callback);
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
        if ($this->hasFilter()) {
            // With filter, must iterate to determine emptiness
            return $this->first() === null;
        }

        return $this->count() === 0;
    }

    /**
     * Whether any stage can drop records.
     *
     * A map never changes how many records come out, so only filters matter
     * to emptiness.
     *
     * @return bool
     */
    private function hasFilter(): bool
    {
        foreach ($this->stages as [$kind]) {
            if ($kind === self::STAGE_FILTER) {
                return true;
            }
        }

        return false;
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
