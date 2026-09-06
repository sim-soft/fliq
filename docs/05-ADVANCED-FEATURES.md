# Advanced Features

## Table of Contents

- [Query Result Caching](#query-result-caching)
- [Pagination](#pagination)
- [Global Scopes](#global-scopes)
- [Batch Insert](#batch-insert)
- [Batch Update](#batch-update)
- [Cursor Pagination](#cursor-pagination)
- [Cursor (Unbuffered Iteration)](#cursor-unbuffered-iteration)
- [Chunk By ID](#chunk-by-id)
- [Index Advisor](#index-advisor)
- [Read/Write Connection Splitting](#readwrite-connection-splitting)
- [Model Replication](#model-replication)
- [Find All with Conditions](#find-all-with-conditions)
- [Row-Level Locking](#row-level-locking)
- [Query Execution Plans (EXPLAIN)](#query-execution-plans-explain)
- [Code Generators](#code-generators)

---

## Query Result Caching

Cache query results to avoid repeated database hits for identical queries.

### Setup

```php
use Simsoft\DB\Cache\ArrayCache;
use Simsoft\DB\Cache\QueryCache;

/* Set a cache driver (use ArrayCache for testing or implement CacheInterface for Redis/Memcached) */
QueryCache::setDriver(new ArrayCache());
```

### Usage

```php
/* Cache results for 60 seconds (default) */
$users = User::find()->where('status', 1)->cache()->all();

/* Cache for 300 seconds */
$posts = Post::find()->where('published', true)->cache(300)->all();

// Works with first() too
$user = User::find()->where('email', 'john@example.com')->cache(120)->first();
```

### What makes a cache entry unique

The cache key is built from the **connection name**, the SQL, and the bound
values. Including the connection matters when you run the same query against
more than one database — for example a tenant per connection. Two tenants
issuing an identical query get separate cache entries, so one never sees the
other's rows.

```php
/* Different connections, same SQL — cached separately */
User::find()->where('status', 1)->on('tenant_a')->cache(60)->all();
User::find()->where('status', 1)->on('tenant_b')->cache(60)->all();
```

### Custom Cache Driver

Implement `Simsoft\DB\Cache\CacheInterface`:

```php
use Simsoft\DB\Cache\CacheInterface;

class RedisCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed { /* ... */ }
    public function set(string $key, mixed $value, int $ttl = 0): bool { /* ... */ }
    public function delete(string $key): bool { /* ... */ }
    public function has(string $key): bool { /* ... */ }
}

QueryCache::setDriver(new RedisCache($redis));
```

---

## Pagination

Get paginated results with metadata.

### Usage

```php
use Simsoft\DB\Paginator;

// Paginate with 15 items per page, page 1
$paginator = User::find()->where('status', 1)->paginate(15, 1);

// Access data
$paginator->data;         // array of models
$paginator->total;        // total record count
$paginator->perPage;      // items per page
$paginator->currentPage;  // current page number
$paginator->lastPage;     // last page number

// Helper methods
$paginator->hasMorePages(); // bool
$paginator->isFirstPage();  // bool
$paginator->isLastPage();   // bool
$paginator->count();        // items on the current page
$paginator->isEmpty();      // bool
$paginator->nextPage();     // int|null — next page number
$paginator->previousPage(); // int|null — previous page number
$paginator->from();         // int|null — index of the first item (e.g., 11 for page 2 of 10/page)
$paginator->to();           // int|null — index of the last item (e.g., 20 for page 2 of 10/page)
```

### Iteration

The paginator is iterable and `Countable`:

```php
foreach ($paginator as $user) {
    echo $user->name;
}

count($paginator); // number of items on the current page
```

### Convert to Array (for JSON APIs)

```php
$paginator->toArray();
/* Returns: */
// [
//   'data' => [...],
//   'total' => 100,
//   'per_page' => 15,
//   'current_page' => 1,
//   'last_page' => 7,
/* 'from' => 1, */
//   'to' => 15,
//   'has_more_pages' => true,
// ]
```

### Display Range

Useful for UI like "Showing 11–20 of 100":

```php
echo "Showing {$paginator->from()}-{$paginator->to()} of {$paginator->total}";
```

### Edge Cases

- `paginate(0)` is coerced to `paginate(1)` to avoid empty results
- `paginate(15, -5)` is coerced to page 1
- `paginate(15, 99999)` (beyond `lastPage`) is clamped to `lastPage`

---

## Global Scopes

Automatically apply conditions to every query for a model.

### Register a Scope

```php
/* In a service provider or bootstrap file */
User::addGlobalScope('active', function (ActiveQuery $query): void {
    $query->where('status', 'active');
});

/* Now every User::find() automatically includes WHERE status = 'active' */
$users = User::find()->all(); // includes active scope
```

### Remove a Scope

```php
User::removeGlobalScope('active');
```

### Query Without Scopes

```php
// Without all global scopes
$allUsers = User::withoutGlobalScopes()->all();

// Without a specific scope
$users = User::withoutGlobalScope('active')->all();
```

### Works with SoftDeletes

Global scopes are applied alongside the soft delete scope:

```php
/* Model with SoftDeletes trait */
Post::addGlobalScope('published', function (ActiveQuery $query): void {
    $query->where('published', true);
});

/* find() applies both: WHERE deleted_at IS NULL AND published = true */
$posts = Post::find()->all();
```

---

## Batch Insert

Insert many records efficiently using chunked multi-row INSERT statements.

### Usage

```php
$records = [
    ['username' => 'Alice', 'email' => 'alice@example.com', 'status_code' => 1],
    ['username' => 'Bob', 'email' => 'bob@example.com', 'status_code' => 1],
    ['username' => 'Charlie', 'email' => 'charlie@example.com', 'status_code' => 0],
];

/* Insert all records (default chunk size: 500) */
$inserted = User::insertBatch($records);
/* Returns: 3 */

/* Custom chunk size for very large datasets */
$inserted = User::insertBatch($largeDataset, 1000);
```

### Column structure

Every record is written against the first record's keys, which is what makes one
statement per chunk possible. The two ways a later record can differ are not
treated alike:

```php
/* Fine — an omitted column is inserted as NULL, so published_at must be nullable */
Post::insertBatch([
    ['user_id' => 1, 'title' => 'First', 'slug' => 'first', 'body' => '...',
     'published_at' => '2026-01-01 09:00:00'],
    ['user_id' => 1, 'title' => 'Draft', 'slug' => 'draft', 'body' => '...'],
]);

/* InvalidArgumentException — 'view_count' has no place in the statement */
Post::insertBatch([
    ['user_id' => 1, 'title' => 'First', 'slug' => 'first', 'body' => '...'],
    ['user_id' => 1, 'title' => 'Second', 'slug' => 'second', 'body' => '...',
     'view_count' => 10],
]);
```

A missing value is a value the caller did not give, and it is sent as NULL — so
a column the first record names must be nullable, or accept the NULL some other
way. An extra one is a value the caller did give and the database would never
have seen, so it is refused rather than dropped. Key order does not matter —
each value binds to the column it names.

---

## Batch Update

Update multiple records with different values in a single query using `CASE WHEN`.

### Usage

```php
/* Update scores for multiple users in one query */
User::updateBatch([
    ['id' => 1, 'score' => 100, 'status' => 1],
    ['id' => 2, 'score' => 85, 'status' => 1],
    ['id' => 3, 'score' => 0, 'status' => 0],
]);
/* Generates: UPDATE user SET score = CASE id WHEN 1 THEN 100 WHEN 2 THEN 85 WHEN 3 THEN 0 END, ...
   WHERE id IN (1, 2, 3) */

// Custom key column (default is 'id')
OrderItem::updateBatch([
    ['sku' => 'ABC-001', 'stock' => 50],
    ['sku' => 'ABC-002', 'stock' => 0],
], 'sku');
```

---

## Cursor Pagination

More efficient than offset pagination for large datasets. Uses a column value as the cursor instead of OFFSET.

### Usage

```php
// First page
$page1 = User::find()
    ->where('status', 1)
    ->cursorPaginate(perPage: 25);

$page1->data;           // array of models
$page1->perPage;        // 25
$page1->hasMore;        // true if more records exist
$page1->nextCursor;     // value to pass for next page
$page1->previousCursor; // null on the first page

// Next page — pass the cursor
$page2 = User::find()
    ->where('status', 1)
    ->cursorPaginate(perPage: 25, cursor: $page1->nextCursor);

/* Custom cursor column and direction */
$page = Post::find()
    ->cursorPaginate(perPage: 10, cursor: $lastId, cursorColumn: 'created_at', direction: 'desc');
```

### Convert to Array (for JSON APIs)

```php
$result = $page1->toArray();
// ['data' => [...], 'per_page' => 25, 'next_cursor' => 42, 'previous_cursor' => null, 'has_more' => true]
```

### Iteration

CursorPaginator is iterable and `Countable`:

```php
foreach ($page1 as $user) {
    echo $user->name;
}

count($page1); // number of items in current page
```

---

## Cursor (Unbuffered Iteration)

Fetch one row at a time without buffering the full result set. Ideal for processing millions of rows with constant memory.

```php
/* Iterates without loading all rows into memory */
foreach (User::find()->where('status', 1)->cursor() as $user) {
    processUser($user);
}

/* Works with conditions, ordering, etc. */
foreach (Order::find()->where('total', '>', 100)->orderBy('id')->cursor() as $order) {
    exportOrder($order);
}
```

Works with PDO-based drivers (MySQL, PostgreSQL, SQLite). Falls back to buffered iteration for MySQLi.

---

## Chunk By ID

Process large result sets in chunks without offset drift:

```php
User::find()->where('status', 1)->chunkById(100, function (array $users) {
    foreach ($users as $user) {
        // process
    }
    // return false to stop early
});
```

---

## Index Advisor

Analyzes logged queries and suggests missing indexes based on WHERE, JOIN, and ORDER BY patterns.

### Usage

```php
use Simsoft\DB\IndexAdvisor;
use Simsoft\DB\QueryLogger;

// Enable logging first
QueryLogger::enable();

// Run your application queries...
$users = User::find()->where('status', 1)->where('role', 'admin')->get();
$posts = Post::find()->join('user', ['id' => 'post.user_id'])->get();

// Get suggestions
$suggestions = IndexAdvisor::suggest();
// [
/* ['table' => 'user', 'columns' => ['status', 'role'], 'reason' => 'Used in WHERE clause'],
   ['table' => 'post', 'columns' => ['user_id'], 'reason' => 'Used in JOIN condition'], */
// ]

// Get as CREATE INDEX SQL statements
$statements = IndexAdvisor::suggestSQL();
/* ['CREATE INDEX `idx_user_status_role` ON `user` (`status`, `role`); -- Used in WHERE clause'] */

/* For PostgreSQL quoting */
$statements = IndexAdvisor::suggestSQL('pgsql_connection');
/* ['CREATE INDEX "idx_user_status_role" ON "user" ("status", "role"); -- Used in WHERE clause'] */
```

---

## Read/Write Connection Splitting

See [Getting Started — Read/Write Connection Splitting](01-GETTING-STARTED.md#readwrite-connection-splitting).

---

## Model Replication

Create a copy of a model as a new unsaved instance (strips primary key):

```php
$user = User::findByPk(1);
$clone = $user->replicate();

$clone->isNew();  // true
$clone->id;       // null (PK stripped)
$clone->name;     // 'Alice' (copied)
$clone->email;    // 'alice@example.com' (copied)

/* Exclude specific attributes from the copy */
$clone = $user->replicate(['email', 'api_token']);
$clone->email; // null

$clone->email = 'new@example.com';
$clone->save(); // INSERT (new record)
```

---

## Find All with Conditions

Shorthand for common find patterns:

```php
// Find all by attribute conditions
$activeAdmins = User::findAll(['status' => 1, 'role' => 'admin']);

/* Array values auto-use IN() */
$users = User::findAll(['id' => [1, 2, 3]]);

/* Null values auto-use IS NULL */
$users = User::findAll(['deleted_at' => null]);
```

---

## Row-Level Locking

Acquire row-level locks within transactions for safe concurrent access:

```php
$driver = Connection::get('mysql');

$driver->transaction(function () {
    /* Exclusive lock — no other transaction can read or modify these rows */
    $job = Job::find()
        ->where('status', 'pending')
        ->orderBy('created_at')
        ->limit(1)
        ->forUpdate()
        ->first();

    $job->update(['status' => 'processing']);
    return true;
});
```

| Method                  | SQL Generated            | Use Case                                 |
|-------------------------|--------------------------|------------------------------------------|
| `forUpdate()`           | `FOR UPDATE`             | Exclusive lock for writes                |
| `forShare()`            | `FOR SHARE`              | Shared lock (read without modification)  |
| `forUpdateNoWait()`     | `FOR UPDATE NOWAIT`      | Fail immediately if locked (PG/MySQL 8+) |
| `forUpdateSkipLocked()` | `FOR UPDATE SKIP LOCKED` | Skip locked rows (job queues)            |

---

## Query Execution Plans (EXPLAIN)

Inspect how the database will execute a query — useful for finding missing
indexes and slow scans:

```php
/* Basically explain */
$plan = User::find()
    ->where('status_code', 1)
    ->orderBy('score', 'DESC')
    ->explain();

/* EXPLAIN ANALYZE — actually runs the query and shows real timings */
$plan = User::find()->where('role', 'admin')->explain(analyze: true);

/* JSON format for programmatic analysis (MySQL and PostgreSQL) */
$plan = Post::find()
    ->whereFulltext(['title', 'body'], 'optimization')
    ->explain(format: 'json');
```

Works on all drivers, each with its own formats. `$format` is validated against
the driver the query runs on, so an unsupported combination raises an
`InvalidArgumentException` instead of returning a plan in the wrong shape:

- **MySQL**: `EXPLAIN` / `EXPLAIN FORMAT=JSON|TREE` / `EXPLAIN ANALYZE`.
  `ANALYZE` always reports the tree format and cannot be combined with
  `FORMAT=JSON`.
- **PostgreSQL**: `EXPLAIN` / `EXPLAIN (FORMAT JSON|YAML|XML)` /
  `EXPLAIN (ANALYZE, FORMAT ...)` — every option goes in one parenthesised list.
- **SQLite**: `EXPLAIN QUERY PLAN`. There is no `EXPLAIN ANALYZE`; the plan is
  described without executing the statement.

---

## Code Generators

Generate Model and Observer files from your database without writing boilerplate
by hand.

### Model Generator

```bash
/* Generate from a single table */
vendor/bin/fliq make:model User --config=config/db.php

/* Generate all models at once */
vendor/bin/fliq make:model --all --config=config/db.php

/* Exclude system tables */
vendor/bin/fliq make:model --all --exclude=migrations,sessions

/* Preview without writing */
vendor/bin/fliq make:model User --preview
```

Auto-detects: `$connection`, `$table`, `$fillable`, `$guarded`, `$casts`,
`$primaryKey` (composite supported), traits (`SoftDeletes`, `Timestamps`),
relations from `*_id` columns, enum value comments.

### Observer Generator

```bash
/* Generate observer with all lifecycle events */
vendor/bin/fliq make:observer User

/* Only specific events */
vendor/bin/fliq make:observer Order --events=creating,updating,deleting

/* Generate for all models */
vendor/bin/fliq make:observer --all
```

See [Model Generator Guide](11-MODEL-GENERATOR.md)
and [Observer Generator Guide](12-OBSERVER-GENERATOR.md) for full tutorials.
