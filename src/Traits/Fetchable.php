<?php

namespace Simsoft\DB\Traits;

use Generator;
use Iterator;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Collection;
use Simsoft\DB\CursorPaginator;
use Simsoft\DB\EagerLoader;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Model;
use Simsoft\DB\Paginator;

/**
 * Fetchable trait.
 *
 * Provides result fetching, hydration, and eager loading for ActiveQuery.
 */
trait Fetchable
{
    /**
     * Find one record.
     *
     * Applies eager loading if relations are specified via with().
     *
     * @return null|Model|array<string, mixed>
     */
    public function first(): mixed
    {
        // Limiting a clone rather than $this. The limit used to be written onto
        // the receiver, so it outlived the call: after $q->first(), the same $q
        // answered every later all(), getArray(), each() and cursor() with a
        // single row, and reported no error while doing it.
        $query = (clone $this)->limit(1);
        $result = $this->query($query);
        if (!$result) {
            return $this->modelClass ? null : [];
        }

        if (!$this->modelClass) {
            return $result[0];
        }

        $model = $this->getHydrated($result[0]);

        if (!empty($this->eagerLoad)) {
            EagerLoader::loadRelations([$model], $this->eagerLoad, $this->eagerLoadConstraints);
        }

        return $model;
    }

    /**
     * Find by primary key.
     *
     * Takes a scalar for a single-column key, or attribute => value pairs for a
     * composite one — the same two shapes Model::findByPk() takes, because the
     * documented way to combine a primary-key lookup with eager loading is to
     * start from the query: `User::find()->with('posts')->findByPk(1)`. That
     * chain was a TypeError, since only the model-level method accepted the
     * scalar, and every route to eager loading has to go through the query.
     *
     * @param string|int|array<string, mixed> $pk The primary key value, or attribute => value pairs.
     * @return mixed
     * @throws QueryException If a scalar is given for a composite primary key.
     */
    public function findByPk(string|int|array $pk): mixed
    {
        $pk = is_array($pk) ? $pk : [$this->singlePrimaryKeyField() => $pk];

        $keys = [];
        foreach ($pk as $attribute => $key) {
            $keys[] = [$attribute, '=', $key];
        }
        return $this->where($keys)->first();
    }

    /**
     * The name of the model's primary key column, when it has exactly one.
     *
     * @return string
     * @throws QueryException If there is no model, or its key spans several columns.
     */
    private function singlePrimaryKeyField(): string
    {
        if (!$this->modelClass) {
            throw new QueryException('findByPk() needs a model to know which column is the primary key.');
        }

        /** @var Model $model */
        $model = is_string($this->modelClass) ? new $this->modelClass() : $this->modelClass;
        $field = $model->getPrimaryKeyFields();

        // Naming the columns rather than just refusing: a composite key is
        // exactly the case where the caller cannot guess what shape to pass.
        if (!is_string($field)) {
            throw new QueryException(sprintf(
                '%s has a composite primary key (%s), so findByPk() needs an array of attribute => value pairs.',
                $model::class,
                implode(', ', $field)
            ));
        }

        return $field;
    }

    /**
     * Paginate query results with metadata.
     *
     * Returns a Paginator containing the current page's data along with
     * total count, per-page size, current page, and last page information.
     *
     * @param int $perPage Number of records per page (minimum 1). Default: 15.
     * @param int|null $page Current page number (minimum 1). Default: 1.
     * @return Paginator
     */
    public function paginate(int $perPage = 15, ?int $page = null): Paginator
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page ?? 1);
        $total = (clone $this)->count();
        $lastPage = max(1, (int)ceil($total / $perPage));

        // Clamp page to lastPage if it's beyond the available range
        $page = min($page, $lastPage);

        $query = clone $this;
        $query->page($page, $perPage);
        $data = iterator_to_array($this->modelClass ? $query->all() : $query->getArray());

        return new Paginator(array_values($data), $total, $perPage, $page, $lastPage);
    }

    /**
     * Paginate using cursor-based pagination.
     *
     * More efficient than offset pagination for large datasets.
     * Uses the value of a column (default: primary key) as the cursor.
     *
     * @param int $perPage Records per page.
     * @param string|int|null $cursor The cursor value (last seen value of the cursor column).
     * @param string $cursorColumn The column to use as cursor. Default: 'id'.
     * @param string $direction 'asc' or 'desc'. Default: 'asc'.
     * @return CursorPaginator
     */
    public function cursorPaginate(int $perPage = 15, string|int|null $cursor = null, string $cursorColumn = 'id', string $direction = 'asc'): CursorPaginator
    {
        $perPage = max(1, $perPage);
        $query = clone $this;

        if ($cursor !== null) {
            $operator = strtolower($direction) === 'desc' ? '<' : '>';
            $query->where($cursorColumn, $operator, $cursor);
        }

        $query->orderBy($cursorColumn, $direction);
        $query->limit($perPage + 1);

        $results = $query->query($query);
        $hasMore = count($results) > $perPage;

        if ($hasMore) {
            array_pop($results);
        }

        $data = $this->modelClass
            ? array_map(fn(array $row) => $this->getHydrated($row), $results)
            : $results;

        $nextCursor = $this->extractNextCursor($hasMore, $data, $results, $cursorColumn);

        return new CursorPaginator($data, $perPage, $nextCursor, $cursor, $hasMore);
    }

    /**
     * Extract the next cursor value from the last record.
     *
     * @param bool $hasMore Whether there are more records.
     * @param array<int, mixed> $data The hydrated data.
     * @param array<int, array<string, mixed>> $results The raw results.
     * @param string $cursorColumn The cursor column name.
     * @return string|int|null
     */
    private function extractNextCursor(bool $hasMore, array $data, array $results, string $cursorColumn): string|int|null
    {
        if (!$hasMore || empty($data)) {
            return null;
        }

        $last = end($data);
        if ($last instanceof Model) {
            return $last->{$cursorColumn};
        }

        $lastRow = end($results);
        return $lastRow[$cursorColumn] ?? null;
    }

    /**
     * Hydrate a model from raw data.
     *
     * Uses the fast hydration path that bypasses constructor overhead.
     *
     * @param array<string, mixed> $data The raw row data.
     * @return Model
     */
    public function getHydrated(array $data): Model
    {
        /** @var class-string<Model> $class */
        $class = $this->modelClass;
        return $class::hydrate($data);
    }

    /**
     * Iterate all records as arrays.
     *
     * @return Iterator
     */
    public function getArray(): Iterator
    {
        if ($this->indexBy === null) {
            yield from $this->query($this);
            return;
        }

        if (is_string($this->indexBy)) {
            foreach ($this->query($this) as $row) {
                yield $row[$this->indexBy] => $row;
            }
            return;
        }

        foreach ($this->query($this) as $row) {
            yield ($this->indexBy)($row) => $row;
        }
    }

    /**
     * Iterate all records as models.
     *
     * Applies eager loading if relations are specified via with().
     *
     * @return Iterator
     */
    public function all(): Iterator
    {
        if (!$this->modelClass) {
            yield from $this->getArray();
            return;
        }

        // When eager loading is requested, collect all models first
        if (!empty($this->eagerLoad)) {
            $models = [];
            foreach ($this->getArray() as $index => $data) {
                $models[$index] = $this->getHydrated($data);
            }

            EagerLoader::loadRelations($models, $this->eagerLoad, $this->eagerLoadConstraints);

            foreach ($models as $index => $model) {
                yield $index => $model;
            }
            return;
        }

        foreach ($this->getArray() as $index => $data) {
            yield $index => $this->getHydrated($data);
        }
    }

    /**
     * Get a Collection for lazy iteration.
     *
     * @return Collection
     */
    public function get(): Collection
    {
        return new Collection($this);
    }

    /**
     * Iterate records one at a time with chunked fetching.
     *
     * @param int $size Chunk size.
     * @return Collection
     */
    public function each(int $size = 100): Collection
    {
        return (new Collection($this))->chunk($size);
    }

    /**
     * Iterate in batches (yields arrays of records).
     *
     * @param int $size Batch size.
     * @return Generator
     */
    public function batch(int $size = 20): Generator
    {
        return (new Collection($this))->batch($size);
    }

    /**
     * Retrieve a list of single column values.
     *
     * @param string $attribute The attribute to pluck.
     * @param string|null $indexBy Optional index key.
     * @return Collection
     */
    public function pluck(string $attribute, string|null $indexBy = null): Collection
    {
        $this->pluckAttribute = $attribute;

        if ($indexBy !== null) {
            $this->indexBy($indexBy);
        }

        return (new Collection($this))->toArray();
    }

    /**
     * Update all records matching the current conditions.
     *
     * @param array<string, mixed> $attributes Column => value pairs to update.
     * @return bool
     */
    public function updateAll(array $attributes = []): bool
    {
        $table = $this->getTable();

        // A query selecting from a sub-query has no table to write back to.
        // The sub-query SQL used to be passed to Update as though it were one,
        // producing an UPDATE against a table named after a whole SELECT.
        if ($table === null) {
            throw new QueryException('Cannot update a query that selects from a sub-query.', '');
        }

        $update = new Update($table, $attributes, $this);
        $update->withConnection($this->connection);
        return (bool)$update->execute();
    }

    /**
     * Check if any records exist matching the current conditions.
     *
     * Fetches at most one row, so the cost does not grow with the match count.
     *
     * Named hasRecords() rather than exists(): ActiveQuery declares its own
     * exists(ActiveQuery|Raw $query) for the SQL EXISTS sub-query condition,
     * and a class method silently wins over a trait method of the same name.
     * The trait's no-argument version was therefore unreachable — calling it
     * as documented raised ArgumentCountError, not a false.
     *
     * @return bool
     */
    public function hasRecords(): bool
    {
        // Limiting a clone, so a caller can keep using the query afterwards.
        $query = (clone $this)->limit(1);

        return !empty($this->query($query));
    }

    /**
     * Process records in chunks with a callback.
     *
     * @param int $size Records per chunk.
     * @param callable $callback Receives an array of models/rows. Return false to stop.
     * @return void
     */
    public function chunkById(int $size, callable $callback): void
    {
        $page = 0;

        while (true) {
            $query = clone $this;
            $query->page(++$page, $size);

            $results = iterator_to_array($this->modelClass ? $query->all() : $query->getArray());

            if (empty($results)) {
                return;
            }

            if ($callback($results) === false) {
                return;
            }

            if (count($results) < $size) {
                return;
            }
        }
    }

    /**
     * Get results as an unbuffered cursor for memory-efficient iteration.
     *
     * Fetches one row at a time without buffering the full result set.
     * Works with PDO-based drivers (MySQL, PostgreSQL, SQLite).
     *
     * @return Generator
     */
    public function cursor(): Generator
    {
        $driver = $this->getDriver('read');

        if (!method_exists($driver, 'getPdo')) {
            yield from $this->all();
            return;
        }

        $pdo = $driver->getPdo();
        $sql = $this->getSQL();
        $binds = $this->getBinds();

        // Use unbuffered queries for MySQL; other drivers are unbuffered by default
        $options = [];
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $options[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }

        $stmt = $pdo->prepare($sql, $options);
        $stmt->execute($binds);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($this->modelClass) {
                yield $this->getHydrated($row);
                continue;
            }
            yield $row;
        }

        $stmt->closeCursor();
    }
}
