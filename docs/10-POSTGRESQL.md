# PostgreSQL Guide

## Table of Contents

- [Connection Setup](#connection-setup)
- [Schema-Qualified Tables](#schema-qualified-tables)
- [RETURNING Clause](#returning-clause)
- [INSERT ON CONFLICT](#insert-on-conflict)
- [Row-Level Locking](#row-level-locking)
- [Full-Text Search](#full-text-search)
- [JSON / JSONB Queries](#json--jsonb-queries)
- [Array Columns](#array-columns)
- [Boolean Columns](#boolean-columns)
- [ILIKE (Case-Insensitive)](#ilike-case-insensitive)
- [Advisory Locks](#advisory-locks)
- [LISTEN / NOTIFY](#listen--notify)
- [EXPLAIN / Query Plans](#explain--query-plans)
- [PgBouncer Compatibility](#pgbouncer-compatibility)
- [Production Checklist](#production-checklist)

---

## Connection Setup

```php
use Simsoft\DB\Connection;

Connection::add('pgsql', [
    'driver'               => 'pgsql',
    'host'                 => '127.0.0.1',
    'port'                 => 5432,
    'database'             => 'my_app',
    'username'             => 'app_user',
    'password'             => 'secret',
    'charset'              => 'utf8',
    'schema'               => 'public',
    'persistent'           => false,
    'timeout'              => 5,
    'statement_cache'      => true,
    'statement_cache_size' => 100,
    'options'              => [],  /* PDO options override, bar ATTR_ERRMODE */
]);
```

| Config Key             | Default  | Description                                  |
|------------------------|----------|----------------------------------------------|
| `driver`               | —        | Must be `pgsql`, `postgres`, or `postgresql` |
| `schema`               | `public` | Sets `search_path` on connect                |
| `statement_cache`      | `true`   | Enable prepared statement caching            |
| `statement_cache_size` | `100`    | Max cached statements before LRU eviction    |
| `persistent`           | `false`  | Use persistent PDO connections               |
| `timeout`              | `5`      | Connection timeout in seconds                |

### Read/Write Splitting

```php
Connection::add('pgsql', [
    'driver'   => 'pgsql',
    'host'     => 'primary.db',
    'database' => 'my_app',
    'username' => 'app_user',
    'password' => 'secret',
    'charset'  => 'utf8',
    'schema'   => 'public',
    'read' => [
        'host' => 'replica.db',
    ],
]);
```

---

## Schema-Qualified Tables

Cross-schema queries use dot notation:

```php
/* SELECT "reporting"."monthly_stats".* FROM "reporting"."monthly_stats" ... */
$query = (new ActiveQuery())
    ->from('reporting.monthly_stats')
    ->where('month', '2025-06')
    ->get();
```

Models can reference schema-qualified tables:

```php
class MonthlyStat extends Model
{
    protected string $table = 'reporting.monthly_stats';
}
```

---

## RETURNING Clause

PostgreSQL supports `RETURNING` on INSERT, UPDATE, and DELETE to retrieve
affected rows without a second query.

### INSERT with RETURNING

Model `save()` automatically uses `RETURNING "id"` to retrieve the inserted
primary key — no sequence name configuration required.

```php
$user = new User(['name' => 'Alice', 'email' => 'alice@example.com']);
$user->save();
/* INSERT INTO "user" ("name", "email") VALUES (?, ?) RETURNING "id" */

echo $user->id; /* Populated via RETURNING */
```

### UPDATE with RETURNING

```php
use Simsoft\DB\Builder\Update;

$update = new Update('user', ['status' => 'active']);
$update->withConnection('pgsql');
$update->condition("role = 'pending'");
$update->returning('id', 'email');
$update->execute();

$affectedRows = $update->getReturningResult();
/*
  [
    ['id' => 5, 'email' => 'new@example.com'],
    ['id' => 8, 'email' => 'other@example.com'],
  ]
*/
```

### DELETE with RETURNING

```php
use Simsoft\DB\Builder\Delete;

$delete = new Delete('session');
$delete->withConnection('pgsql');
$delete->condition("expired_at < NOW()");
$delete->returning('id', 'user_id');
$delete->execute();

$purged = $delete->getReturningResult();
```

### Returning every column

Calling `returning()` with no arguments asks for `RETURNING *`, which is useful
when you want the row as it stands after the write rather than a chosen few
columns:

```php
$update = new Update('user', ['status' => 'active']);
$update->withConnection('pgsql');
$update->condition("role = 'pending'");
$update->returning();
$update->execute();

$rows = $update->getReturningResult();
/* [['id' => 5, 'name' => 'Alice', 'email' => '...', 'role' => 'pending', 'status' => 'active'], ...] */
```

`getReturningResult()` distinguishes the two ways a statement can produce no
rows: it answers `[]` when the clause ran and matched nothing, and `null` when no
`RETURNING` was asked for at all.

On MySQL, which has no `RETURNING`, the clause is omitted and the statement runs
without it — `getReturningResult()` stays `null`.

---

## INSERT ON CONFLICT

### Insert or Ignore (DO NOTHING)

```php
use Simsoft\DB\DB;

/* INSERT INTO "user" ("email", "name") VALUES (?, ?) ON CONFLICT DO NOTHING */
DB::insertOrIgnore('user', [
    'email' => 'alice@example.com',
    'name' => 'Alice',
]);

/* Several rows at once — one statement, each row skipped independently */
/* INSERT INTO "user" ("email", "name") VALUES (?,?),(?,?) ON CONFLICT DO NOTHING */
DB::insertOrIgnore('user', [
    ['email' => 'alice@example.com', 'name' => 'Alice'],
    ['email' => 'bob@example.com', 'name' => 'Bob'],
]);
```

### Knowing whether the row was inserted

`ON CONFLICT DO NOTHING` reports success whether it wrote a row or skipped one,
so ask for the row back:

```php
use Simsoft\DB\Builder\Insert;

$insert = (new Insert('user', ['email' => 'alice@example.com', 'name' => 'Alice']))
    ->ignore()
    ->returning('id')
    ->withConnection('pgsql');
$insert->execute();

$insert->getReturningResult(); /* [['id' => 42]] inserted, [] skipped */
$insert->getLastInsertId();    /* '42' inserted, null skipped */
```

`getLastInsertId()` is null rather than a sequence value when the insert was
skipped: the conflicting attempt still advances the sequence, so that number
names no row.

### Upsert (DO UPDATE)

```php
use Simsoft\DB\Builder\Upsert;

/*
  INSERT INTO "setting" ("group", "key", "value")
  VALUES (?, ?, ?)
  ON CONFLICT ("group", "key") DO UPDATE SET "value" = EXCLUDED."value"
*/
$upsert = new Upsert(
    'setting',
    ['group' => 'app', 'key' => 'theme', 'value' => 'dark'],
    ['value'],              /* columns to update */
    ['group', 'key']        /* conflict target */
);
$upsert->withConnection('pgsql')->execute();
```

### Knowing which row the upsert touched

Ask for it back, the same way `ignore()` does above. The clause is emitted after
the conflict action:

```php
/*
  INSERT INTO "setting" ("group", "key", "value")
  VALUES (?, ?, ?)
  ON CONFLICT ("group", "key") DO UPDATE SET "value" = EXCLUDED."value"
  RETURNING "id"
*/
$upsert = (new Upsert('setting', $attributes, ['value'], ['group', 'key']))
    ->returning('id')
    ->withConnection('pgsql');
$upsert->execute();

$upsert->getReturningResult(); /* [['id' => 42]] — inserted or updated */
$upsert->getLastInsertId();    /* '42', the row this statement wrote */
```

Without the clause, `getLastInsertId()` falls back to the driver, whose answer on
PostgreSQL is `lastval()` — the last sequence value this *session* consumed, by
whatever statement. An upsert that took the `DO UPDATE` branch still consumed one
on its way there, so the id it reported named no row at all. Ask for the columns
back and the statement answers for itself.

`returning()` with no arguments asks for every column:

```php
$upsert->returning();  /* ... DO UPDATE SET "value" = EXCLUDED."value" RETURNING * */
```

MySQL has no `RETURNING` and needs none: `LAST_INSERT_ID()` is scoped to the
statement there, and `ON DUPLICATE KEY UPDATE` sets it to the id of the row it
touched. Calling `returning()` on a MySQL connection is accepted and emits
nothing, so the same builder code runs on all three engines.

---

## Row-Level Locking

Use inside transactions for safe concurrent access:

```php
use Simsoft\DB\Connection;

$driver = Connection::get('pgsql');

$driver->transaction(function () {
    /* SELECT ... FOR UPDATE */
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

| Method                  | SQL Generated            |
|-------------------------|--------------------------|
| `forUpdate()`           | `FOR UPDATE`             |
| `forShare()`            | `FOR SHARE`              |
| `forUpdateNoWait()`     | `FOR UPDATE NOWAIT`      |
| `forUpdateSkipLocked()` | `FOR UPDATE SKIP LOCKED` |

### Job Queue Pattern (SKIP LOCKED)

```php
$driver->transaction(function () {
    /* Skip rows locked by other workers */
    $job = Job::find()
        ->where('status', 'pending')
        ->limit(1)
        ->forUpdateSkipLocked()
        ->first();

    if ($job) {
        $job->update(['status' => 'processing', 'worker_id' => getmypid()]);
    }
    return true;
});
```

---

## Full-Text Search

PostgreSQL full-text uses `to_tsvector` / `to_tsquery` via the `whereFulltext()`
method:

```php
/* to_tsvector('english', "post"."title") || to_tsvector('english', "post"."body")
   @@ plainto_tsquery('english', ?) */
$results = Post::find()
    ->whereFulltext(['title', 'body'], 'database optimization')
    ->get();
```

### Search Modes

| Mode              | Function Used          | Use Case                                 |
|-------------------|------------------------|------------------------------------------|
| `plain` (default) | `plainto_tsquery`      | Simple word matching                     |
| `phrase`          | `phraseto_tsquery`     | Exact phrase order                       |
| `websearch`       | `websearch_to_tsquery` | Google-style syntax (`"exact" -exclude`) |

```php
/* Phrase search: words must appear in order */
Post::find()
    ->whereFulltext('title', 'query builder', 'phrase')
    ->get();

/* Websearch: supports quoted phrases, +/- operators */
Post::find()
    ->whereFulltext(['title', 'body'], '"active record" -doctrine', 'websearch')
    ->get();
```

### Language Configuration

```php
/* French text search */
Post::find()
    ->whereFulltext('body', 'optimisation requête', 'plain', 'french')
    ->get();
```

### The same modes on other engines

`whereFulltext()` works on MySQL and SQLite too, and the three modes mean the
same thing on each — a term is only read as query syntax in `websearch` mode.

| Mode        | PostgreSQL             | MySQL                       | SQLite (FTS5)   |
|-------------|------------------------|-----------------------------|-----------------|
| `plain`     | `plainto_tsquery`      | `IN NATURAL LANGUAGE MODE`  | `MATCH`         |
| `phrase`    | `phraseto_tsquery`     | quoted, `IN BOOLEAN MODE`   | quoted `MATCH`  |
| `websearch` | `websearch_to_tsquery` | `IN BOOLEAN MODE`           | `MATCH`         |

```php
/* Plain: the hyphen is part of the term, not an exclusion, on every engine */
Post::find()->whereFulltext(['title', 'body'], 'database -systems')->get();

/* Websearch: now the operators are the caller's, and are honoured */
Post::find()->whereFulltext(['title', 'body'], 'database -systems', 'websearch')->get();
```

Those three are the whole set. Any other name raises
`InvalidArgumentException` on every engine, rather than being answered in
`plain` mode:

```php
/* InvalidArgumentException: Unsupported full-text search mode 'boolean' for
   mysql. Supported modes: plain, phrase, websearch. For boolean operators
   (+, -, "), use 'websearch'. */
Post::find()->whereFulltext('title', '+PHP -Docker', 'boolean')->get();
```

`boolean` is worth naming because it is MySQL's own word for the mode, so it is
the one a MySQL user reaches for. Answered in `plain` mode it returned *more*
rows than asked for — the `-` was read as punctuation, so the excluded term was
not excluded. Case and surrounding space are normalised, so `'WebSearch'` and
`' phrase '` are accepted.

MySQL requires a `FULLTEXT` index over exactly the columns searched. The
`$language` argument is PostgreSQL-only; MySQL and SQLite ignore it.

SQLite searches one column per call, and only against a table created with
`CREATE VIRTUAL TABLE ... USING fts5` — passing several columns raises
`InvalidArgumentException` rather than searching the first and dropping the
rest. To cover several columns, search each one:

```php
/* SQLite: InvalidArgumentException — FTS5 matches one column at a time */
Post::find()->whereFulltext(['title', 'body'], 'database')->get();

/* Search them separately instead */
Post::find()
    ->whereFulltext('title', 'database')
    ->orWhereFulltext('body', 'database')
    ->get();
```

### GIN Index Recommendation

For production performance, create a GIN index:

```sql
CREATE INDEX idx_post_fts ON post
  USING GIN (to_tsvector('english', title || ' ' || body));
```

---

## JSON / JSONB Queries

PostgreSQL JSONB operators are fully supported:

```php
/* WHERE "user"."meta" ->> 'age' > ? */
User::find()->where('meta->age', '>', 25)->get();

/* WHERE "user"."tags" @> ?::jsonb */
User::find()->jsonContains('tags', 'php')->get();

/* WHERE NOT "user"."tags" @> ?::jsonb */
User::find()->jsonNotContains('tags', 'java')->get();

/* WHERE jsonb_exists("user"."meta", 'address') */
User::find()->jsonHas('meta->address')->get();

/* WHERE NOT jsonb_exists("user"."meta", 'temp_field') */
User::find()->jsonMissing('meta->temp_field')->get();

/* WHERE jsonb_array_length("user"."meta" -> 'tags') >= ? */
User::find()->whereJsonLength('meta->tags', '>=', 3)->get();
```

### Nested Paths

```php
/* meta -> 'address' ->> 'city' = ? */
User::find()->where('meta->address.city', '=', 'Tokyo')->get();

/* jsonb_exists(meta -> 'settings', 'theme') */
User::find()->jsonHas('meta->settings.theme')->get();
```

### The Document Root

A column written without `->` addresses the whole document. PostgreSQL spells
that differently from MySQL's `'$'`, so the grammar emits its own form:

```php
/* WHERE "user"."meta" @> ?::jsonb  — containment against the document */
User::find()->jsonContains('meta', ['priority' => 1])->get();

/* WHERE jsonb_array_length("user"."tags") = ? */
User::find()->whereJsonLength('tags', '=', 2)->get();
```

`jsonb_array_length` requires an array at the path, at the root as anywhere
else. MySQL's `JSON_LENGTH` also counts the keys of an object, so a length
query against a JSON *object* answers on MySQL and errors here.

`jsonHas()` and `jsonMissing()` test for a key, and the root is not one — both
raise `InvalidArgumentException` if the column has no `->key`. Use
`notNull('meta')` to test that the document is present.

---

## Array Columns

PostgreSQL supports native array types (`text[]`, `int[]`, `varchar[]`). FLIQ
provides query methods for array containment and overlap checks.

### Contains (single value)

```php
/* WHERE "user"."tags" @> ARRAY[?]::text[] */
User::find()->arrayContains('tags', 'php')->get();

/* Integer array */
User::find()->arrayContains('role_ids', 5, 'int')->get();
```

### Overlaps (any value matches)

```php
/* WHERE "user"."tags" && ARRAY[?, ?]::text[] */
User::find()->arrayOverlaps('tags', ['php', 'python'])->get();

/* Integer array column */
User::find()->arrayOverlaps('department_ids', [1, 3, 5], 'int')->get();
```

### Or Variants

```php
User::find()
    ->arrayContains('tags', 'php')
    ->orArrayContains('tags', 'python')
    ->get();

User::find()
    ->where('status', 'active')
    ->orArrayOverlaps('skills', ['docker', 'kubernetes'])
    ->get();
```

### Schema for Array Columns

```sql
CREATE TABLE "user" (
    id    SERIAL PRIMARY KEY,
    name  VARCHAR(100) NOT NULL,
    tags  TEXT[] DEFAULT '{}'
);

/* GIN index for fast containment/overlap queries */
CREATE INDEX idx_user_tags ON "user" USING GIN (tags);
```

---

## Boolean Columns

PostgreSQL uses native `true`/`false` boolean type. FLIQ's `bool` cast handles
all PG representations:

```php
class Feature extends Model
{
    protected string $table = 'feature';

    protected array $casts = [
        'is_enabled' => 'bool',
    ];
}

$feature = Feature::findByPk(1);
/* PG returns 't'/'f' strings — cast normalizes to PHP true/false */
var_dump($feature->is_enabled); /* bool(true) */
```

Values recognized as `false`: `'f'`, `'false'`, `'0'`, `''`, `'no'`, `'off'`
(case-insensitively).

All other non-empty string values are treated as `true`.

A nullable boolean column reads back as `null`, not `false` — the cast applies
to the value, not to its absence. Test with `=== null` if the distinction
matters.

---

## ILIKE (Case-Insensitive)

PostgreSQL uses `ILIKE` for case-insensitive matching (unlike MySQL's default
collation-based behavior). FLIQ automatically uses `ILIKE` / `NOT ILIKE` on
PostgreSQL:

```php
/* WHERE "user"."name" ILIKE ? */
User::find()->whereLike('name', '%alice%')->get();

/* WHERE "user"."email" NOT ILIKE ? */
User::find()->whereNotLike('email', '%spam%')->get();
```

For explicit case-sensitive matching on PG, use `caseSensitive: true`:

```php
/* WHERE "user"."username" LIKE ? (exact case) */
User::find()->whereLike('username', 'Alice%', caseSensitive: true)->get();
```

> **Watch the family you call.** `whereLike()` is case-insensitive by default,
> but `like()` is case-**sensitive** by default. On MySQL that difference is
> usually hidden by the collation; on PostgreSQL it is not, and the two return
> different rows:
>
> ```php
> User::find()->like('username', 'Alice%')->get();       // LIKE  — exact case
> User::find()->whereLike('username', 'Alice%')->get();  // ILIKE — any case
> ```
>
> Pass `caseSensitive` explicitly and the two families agree. See
> [Case sensitivity](02-QUERY-BUILDER.md#case-sensitivity).

---

## Advisory Locks

PostgreSQL advisory locks provide application-level distributed locking without
table rows. Useful for worker coordination, singleton tasks, and resource
control.

### Session-Level Locks

```php
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\PostgresDriver;

/** @var PostgresDriver $driver */
$driver = Connection::get('pgsql');

/* Blocking: waits until the lock is available */
$driver->advisoryLock(12345);

/* Do exclusive work... */

$driver->advisoryUnlock(12345);
```

### Non-Blocking (Try)

```php
if ($driver->advisoryLockTry(12345)) {
    /* Got the lock — do work */
    $driver->advisoryUnlock(12345);
}
/* Lock not available — skip or retry */
```

### Transaction-Level Locks

Released automatically when the transaction ends — no manual unlock needed.

```php
$driver->transaction(function () use ($driver) {
    $driver->advisoryLockTransaction(99);
    /* Work is protected until commit/rollback */
    return true;
});
/* Lock is released here */
```

| Method                             | Behavior                                       |
|------------------------------------|------------------------------------------------|
| `advisoryLock($key)`               | Blocking, session-level                        |
| `advisoryLockTry($key)`            | Non-blocking, session-level                    |
| `advisoryUnlock($key)`             | Release session lock                           |
| `advisoryLockTransaction($key)`    | Blocking, auto-released at transaction end     |
| `advisoryLockTransactionTry($key)` | Non-blocking, auto-released at transaction end |

---

## LISTEN / NOTIFY

PostgreSQL pub/sub for real-time event signaling between connections. Requires a
persistent connection (not compatible with PgBouncer transaction pooling).

### Publishing Events

```php
/** @var PostgresDriver $driver */
$driver = Connection::get('pgsql');

/* Simple notification */
$driver->notify('order_created');

/* With JSON payload (max 8000 bytes) */
$driver->notify('order_created', json_encode(['order_id' => 42, 'total' => 99.95]));
```

### Subscribing to Events

```php
/* Subscribe to channel */
$driver->listen('order_created');

/* Poll for notifications (non-blocking) */
$notification = $driver->getNotification();
if ($notification !== null) {
    echo $notification['channel'];  /* "order_created" */
    echo $notification['payload'];  /* '{"order_id":42,"total":99.95}' */
    echo $notification['pid'];      /* sender's backend PID */
}

/* Blocking wait (1000ms timeout) */
$notification = $driver->getNotification(1000);

/* Unsubscribe */
$driver->unlisten('order_created');
```

### Worker Loop Pattern

```php
$driver->listen('jobs');

while (true) {
    $event = $driver->getNotification(5000); /* 5s timeout */
    if ($event !== null) {
        $payload = json_decode($event['payload'], true);
        processJob($payload);
    }
}
```

### Channel Names

Channel names are used exactly as given. `listen()`, `unlisten()` and `notify()`
all address the same channel for the same string, so a name chosen anywhere —
including one a trigger passes to `pg_notify()` — reaches the subscriber that
asked for it:

```php
/* A trigger publishes under the channel name your application uses */
$driver->listen('order-created');
/* CREATE TRIGGER ... EXECUTE FUNCTION pg_notify('order-created', ...) */
```

Two consequences follow from names being literal:

```php
/* Case-sensitive: these are different channels */
$driver->listen('OrderCreated');
$driver->notify('ordercreated', 'x');  /* not delivered */

/* Distinct names stay distinct */
$driver->listen('tenant-1');
$driver->notify('tenant1', 'x');       /* not delivered */
```

A channel name must be 1 to 63 bytes — the server's `NAMEDATALEN` limit. Empty
and over-long names raise an `InvalidArgumentException` from all three methods
rather than being silently altered:

```php
$driver->listen('');                    /* InvalidArgumentException */
$driver->listen(str_repeat('c', 70));   /* InvalidArgumentException */
```

The check is done in the driver because the server truncates a long identifier
instead of refusing it: `LISTEN` on a 70-byte name would quietly subscribe to
its 63-byte prefix, while `pg_notify()` rejects the same string — so the pair
could never round trip.

> **Note:** `NOTIFY` is asynchronous. A notification sent by one connection is
> not guaranteed to be readable by another the instant `notify()` returns, so
> poll with a timeout — `getNotification(1000)` — rather than treating a single
> empty non-blocking poll as proof that nothing was sent.

---

## EXPLAIN / Query Plans

Get the database query execution plan for debugging and optimization:

### Basic EXPLAIN

```php
$plan = User::find()
    ->where('status_code', 1)
    ->orderBy('score', 'DESC')
    ->limit(10)
    ->explain();

/*
  [
    ['QUERY PLAN' => 'Limit  (cost=1.27..1.29 rows=10 ...)'],
    ['QUERY PLAN' => '  ->  Sort  (cost=1.27..1.29 rows=8 ...)'],
    ...
  ]
*/
```

### EXPLAIN ANALYZE (actual execution)

```php
/* Actually runs the query and reports real timings */
$plan = User::find()
    ->where('role', 'admin')
    ->explain(analyze: true);
```

### JSON Format (for programmatic analysis)

```php
$plan = Post::find()
    ->with('comments')
    ->whereFulltext(['title', 'body'], 'PHP optimization')
    ->explain(format: 'json');

/* Returns structured plan as JSON array */
```

| Parameter  | Values (PostgreSQL)                   | Default  |
|------------|---------------------------------------|----------|
| `$analyze` | `true` / `false`                      | `false`  |
| `$format`  | `'text'`, `'json'`, `'yaml'`, `'xml'` | `'text'` |

Every option goes in one parenthesised list, so `analyze: true` with a format
emits `EXPLAIN (ANALYZE, FORMAT JSON)`. PostgreSQL rejects the alternative
spelling `EXPLAIN ANALYZE (FORMAT JSON)` as a syntax error.

The list above is PostgreSQL's. Other drivers name different formats — MySQL has
`'tree'` and `'traditional'` but no `'yaml'` or `'xml'`, and SQLite has `'text'`
only — and `$format` is validated against whichever driver the query runs on.
See [Advanced Features](05-ADVANCED-FEATURES.md#query-execution-plans-explain).

### Available on All Queries

```php
/* Works on any builder */
use Simsoft\DB\Builder\ActiveQuery;

$query = (new ActiveQuery())
    ->from('order')
    ->join('user', ['id' => '!order.user_id'])
    ->where('!order.total', '>', 100)
    ->forUpdate();

$plan = $query->explain(analyze: true, format: 'json');
```

---

## PgBouncer Compatibility

When using PgBouncer or pgpool-II in front of PostgreSQL, the connection pooling
mode affects FLIQ's behavior.

### Transaction Pooling Mode (recommended)

| Feature                    | Compatible | Notes                                                                         |
|----------------------------|:----------:|-------------------------------------------------------------------------------|
| Prepared statement cache   |    Yes     | Statements are per-connection; PgBouncer assigns a connection per transaction |
| `FOR UPDATE` / `FOR SHARE` |    Yes     | Works within a single transaction                                             |
| Transactions               |    Yes     | Must complete within one checkout                                             |
| `SET search_path`          |  Caution   | Set per-transaction or use PgBouncer's `server_reset_query`                   |
| Advisory locks             |     No     | Locks require a persistent server connection                                  |
| `LISTEN/NOTIFY`            |     No     | Requires persistent connection                                                |

### Session Pooling Mode

All features work as if directly connected. Higher connection overhead.

### Configuration Recommendations

```php
Connection::add('pgsql', [
    'driver'          => 'pgsql',
    'host'            => 'pgbouncer-host',
    'port'            => 6432,
    'database'        => 'my_app',
    'username'        => 'app_user',
    'password'        => 'secret',
    'charset'         => 'utf8',
    'schema'          => 'public',
    'persistent'      => false,     /* Do NOT use persistent with PgBouncer */
    'statement_cache' => true,      /* Safe in transaction mode */
]);
```

### Disable Statement Cache if Needed

If you experience prepared statement conflicts (common with PgBouncer in
statement pooling mode):

```php
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\PostgresDriver;

/** @var PostgresDriver $driver */
$driver = Connection::get('pgsql');
$driver->disableStatementCache();
```

Or via config:

```php
Connection::add('pgsql', [
    /* ... */
    'statement_cache' => false,
]);
```

---

## Production Checklist

| Item                                |        Status         | Action                                               |
|-------------------------------------|:---------------------:|------------------------------------------------------|
| `ext-pdo_pgsql` installed           |       Required        | `php -m \| grep pdo_pgsql`                           |
| Connection timeout configured       |      Recommended      | `'timeout' => 5`                                     |
| Schema set explicitly               |      Recommended      | `'schema' => 'public'`                               |
| Statement cache sized               |       Optional        | Tune `statement_cache_size` for your query diversity |
| GIN indexes for JSONB               |      Recommended      | Index frequently queried JSON paths                  |
| GIN indexes for arrays              |      Recommended      | `USING GIN (column)` for `@>` and `&&` queries       |
| GIN indexes for FTS                 | Required for fulltext | `USING GIN (to_tsvector(...))`                       |
| Boolean casts declared              |       Required        | Add `'col' => 'bool'` in model `$casts`              |
| PgBouncer mode verified             |     If applicable     | Transaction mode recommended                         |
| `RETURNING` awareness               |       Automatic       | Model `save()` uses it transparently                 |
| Row-level locks in transactions     |       Required        | `forUpdate()` only works inside `transaction()`      |
| Advisory locks released             |       Important       | Session locks need explicit `advisoryUnlock()`       |
| LISTEN/NOTIFY needs persistent conn |     If applicable     | Not compatible with PgBouncer transaction mode       |
| Use `explain()` for slow queries    |      Recommended      | Identify missing indexes and seq scans               |
