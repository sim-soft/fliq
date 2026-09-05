# Changelog

All notable changes to `simsoft/fliq` are documented here.

## [Unreleased]

### Fixed

**Security**

- **Identifier quoting could be escaped** — `quoteIdentifier()` wrapped names in
  backticks (MySQL) or double quotes (PostgreSQL, SQLite) without escaping those
  characters in the input, so a column name containing one closed the quoting
  early and the rest was parsed as SQL. Passing
  ``id` FROM `user` UNION SELECT username FROM `user` --`` as a column returned
  every username. The quote character is now doubled, which is the standard
  escape for all three engines.
- **JSON paths could escape their string literal** — JSON paths cannot be bound
  as parameters, so they are interpolated into a quoted literal; a quote in the
  path closed it and the remainder became SQL. All 17 interpolation sites across
  the three grammars now escape the path. MySQL also doubles backslashes, which
  it treats as an escape character inside string literals.
- **PostgreSQL text search config could escape its string literal** — the
  `$language` argument to `whereFulltext()` reached `to_tsvector('...')` and
  `plainto_tsquery('...')` unescaped, so a quote in it closed the literal. It is
  now escaped like any other interpolated literal.
- **PostgreSQL array cast type was interpolated unchecked** — the `$type`
  argument to `arrayContains()` / `arrayOverlaps()` becomes a bare SQL keyword
  (`::text[]`), so it can be neither quoted nor bound. It is now restricted to a
  simple identifier and rejected with `InvalidArgumentException` otherwise;
  multi-word types such as `double precision` remain valid.

- **Mass assignment bypass in `Model::update()`** — attributes passed to
  `update()` were written straight to the database without consulting
  `$fillable` / `$guarded`, so `$user->update($_POST)` could set guarded columns
  (including the primary key). The rules are now applied to the supplied
  attributes, matching the constructor and `fill()`.
- **`ORDER BY` direction injection** — the array form of `orderBy()` only
  uppercased the direction instead of whitelisting it, so
  `orderBy(['id' => $_GET['sort']])` could append arbitrary SQL. Both the array
  and scalar forms now normalise to `ASC` / `DESC`, with anything else falling
  back to `ASC`.
- **Comparison operator injection** — operators are interpolated into SQL rather
  than bound, and were accepted unvalidated, so an operator taken from request
  data (`where('id', $_GET['op'], 1)`) could replace the comparison outright:
  `'UNION SELECT'` turned the comparison into `WHERE id UNION SELECT ?`. Operators are now
  checked against a whitelist and rejected with `InvalidArgumentException`.
  This affected every condition method, not just `where()`: `orWhere()`,
  `having()`, `whereColumn()`, `whereAny()` / `whereAll()` / `whereNone()`,
  `whereJson()`, `whereJsonLength()`, `whereDate()` / `whereMonth()` /
  `whereYear()` / `whereTime()`, and `CaseExpression::when()` / `whenColumn()`.

**Data Integrity**

- **Nested transactions silently lost atomicity** — no driver tracked whether a
  transaction was already open, so a nested `transaction()` call issued a second
  `BEGIN`. On the default MySQLi driver that **implicitly commits** the outer
  transaction, so the inner call permanently wrote the outer's work and a later
  outer rollback had nothing left to undo — both rows survived a rollback that
  should have discarded them. The PDO drivers raised
  `There is already an active transaction` instead. Nesting is now supported on
  all four drivers: the outermost call opens the real transaction and inner
  calls use savepoints, so an inner rollback undoes only its own work while an
  outer rollback still undoes everything. `getTransactionLevel()` reports the
  current depth.
- **Reconnecting during a transaction silently discarded it** —
  `reconnectIfNeeded()` runs before every query and would transparently
  re-establish a dropped connection mid-transaction, abandoning the statements
  written so far while letting the remaining ones commit on their own. It now
  throws `ConnectionException` instead of turning an atomic block into a partial
  write.
- **Cached query results leaked between connections** — the cache key was built
  from the SQL and bind values only, so the same statement run against two
  different databases shared one entry and the second connection was served the
  first one's rows. Verified end to end: a query against database B returned
  database A's data. The connection name is now part of the key, which matters
  most for multi-tenant setups that keep tenants apart by connection.
- **`update()` left `updated_at` stale** — `update()` wrote its columns without
  running `beforeSave()`, so the `Timestamps` trait never fired and the row's
  other columns changed while `updated_at` kept its old value. `save()` was
  unaffected, which made the two paths disagree about the same record.
  `beforeSave()` now runs on both, exactly once per write.

**Query Builder**

- **A negative `limit()` silently returned every row** — `hasLimit()` tested
  `$limit > 0`, so a negative limit reported that no limit was set and
  `Collection` fell back to paginating the full result set. `limit(-5)` returned
  the entire table while `getSQL()` displayed `LIMIT -5`. Negative values are now
  rejected by `limit()` and `offset()`, and `page()` requires a page of 1 or
  greater (`page(0)` previously computed a negative offset).
- **`getTotalPages()` divided by an unchecked page size** — `getTotalPages(0)`
  raised an uncaught `DivisionByZeroError` and a negative size returned a
  negative page count (`getTotalPages(-5)` gave `-2`), which a paginator would
  then loop over. Both are now rejected, matching `limit()` and `page()`.
- `mysqli::ping()` is deprecated in PHP 8.4 and emitted a deprecation notice on
  every reconnect check; `MySQLiDriver::ping()` now issues `SELECT 1`, matching
  the PDO, PostgreSQL and SQLite drivers.

**Reliability**

- **MySQLi never recovered a dropped connection** — `reconnectIfNeeded()` was
  called by the PDO and PostgreSQL drivers only. `MySQLiDriver::execute()` and
  `query()` never called it, so the default driver had no reconnect path at all
  and a connection dropped by `wait_timeout` failed every subsequent query for
  the life of the process. All four drivers now recover.
- **A dropped connection was only noticed by the pre-query ping** — recovery
  depended entirely on the liveness check happening to run first. A connection
  that died between the check and the statement failed outright. Recovery is now
  driven by the statement: a failure whose error names a lost connection is
  reconnected and retried once. Errors that are not connection losses are
  re-thrown untouched and never retried, and a loss inside a transaction still
  throws `ConnectionException` rather than retrying, since retrying one
  statement of an atomic block would commit it alone.
- **A connection lost during rollback masked the real exception** — tearing down
  a transaction on a dead connection threw the driver's own connection error
  from inside the rollback, replacing the `ConnectionException` that explains
  what happened. The rollback now swallows connection-loss errors only; any
  other rollback failure still propagates.

**Performance**

- **Every query paid for a `SELECT 1` liveness check** — the ping ran before
  each statement, costing a full round trip. Measured against a local MySQL over
  `pdo_mysql`, the ping was **77%** of the cost of a small bound query (1.001 ms
  of 1.291 ms). Connections used within the last 30 seconds are no longer
  re-checked, which more than halved the measured per-query time (1.291 ms →
  0.507 ms). Idle connections — the ones that actually get dropped — are still
  checked. The window is configurable per connection with `ping_idle_seconds`;
  `0` restores a ping before every query.

**Robustness**

- **`QueryLogger` grew without bound** — every executed query was retained for
  the life of the process, so a long-running worker or queue consumer
  accumulated the SQL and bind values of every query it had ever run until it
  exhausted memory. The log now keeps the most recent 1000 entries and discards
  older ones, while `getQueryCount()` and `getTotalTime()` continue to describe
  every query, not just the retained ones. Configurable with
  `QueryLogger::setLimit()`; `0` restores unlimited retention.
- **A mistyped `Query::` method silently matched every row** — `__callStatic()`
  checked whether the method existed and, when it did not, returned a bare query
  with no conditions instead of failing. `Query::wheer('id', 5)` therefore
  matched the whole table, so a typo in a `DELETE` or `UPDATE` would apply to
  every row. Unknown methods now throw `BadMethodCallException`.
- **`DB::table()` ignored the connection argument for models** — the `Model`
  overload returned `$model::find()` and dropped `$connection` on the floor,
  while `resolveTable()` — used by every write method — honoured it. So
  `DB::insert($model, [...], 'reporting')` wrote to `reporting` but
  `DB::table($model, 'reporting')` read from the default connection: the same
  argument in the same position, silently routed to a different database. The
  override is now applied; passing no connection still leaves the model's own
  connection intact.
- **A relation with a `NULL` key returned the entire related table** —
  `Relation::applyConstraints()` returned early when the local key value was
  `null`, leaving the query with no condition at all. Fetching such a relation
  therefore returned every row of the related table rather than none:
  `$post->getComments()->fetch()` on an unsaved `Post` returned all comments in
  the database, and a *saved* row with a nullable foreign key was worse —
  `$post->getCategory()->fetch()` returned an arbitrary unrelated `Category`
  instead of `null`, so the record appeared to belong to something it has no
  link to. A relation with no key value now matches nothing. Note this is not
  `WHERE fk IS NULL`, which would wrongly match other rows whose key is also
  null.

### Changed

- `Model::update()` now filters its argument through the mass assignment rules.
  Attributes set directly (`$model->column = $value`) remain trusted and are
  still written, as are attributes set by lifecycle hooks such as the
  `Timestamps` trait. **This is breaking** for code that relied on `update()`
  writing columns outside `$fillable` — use `updateAttributes()` for those.
- `updateAttributes()`, `updateAll()`, `insertBatch()` and `updateBatch()`
  documented as bypassing mass assignment protection, alongside the lifecycle
  hooks they already skipped. Never pass unvalidated external input to them.
- Condition methods now accept only whitelisted comparison operators: `=`, `!=`,
  `<>`, `>`, `>=`, `<`, `<=`, `<=>`, `LIKE`, `NOT LIKE`, `ILIKE`, `NOT ILIKE`,
  `IN`, `NOT IN`, `IS`, `IS NOT`, `BETWEEN`, `NOT BETWEEN`, `REGEXP`,
  `NOT REGEXP` and `RLIKE`. Word operators are case-insensitive, and the
  `where('col', 'value')` shorthand is unaffected. **This is breaking** for code
  passing any other operator — use `Raw` for expressions outside this set.
- `limit()`, `offset()` and `page()` now throw `InvalidArgumentException` on
  negative or out-of-range values instead of generating invalid SQL or silently
  ignoring the limit. `limit(0)` still means "no limit" and is unchanged.
- `getTotalPages()` now throws `InvalidArgumentException` when the page size is
  below 1. **This is breaking** for callers passing `0` or a negative size,
  though the previous behaviour was a fatal error or a negative page count.
  Unlike `limit()`, `0` is not "no limit" here — a page has to hold something.
- `Model::update()` now runs `beforeSave()`, so lifecycle traits apply to it as
  they do to `save()`. **This is breaking** for a `beforeSave()` override with
  side effects that assumed `update()` would skip it. Model events are still not
  fired by `update()`; call `save()` when you need those.
- `QueryException` no longer appends the failing SQL to `getMessage()`. That
  message is what reaches logs, error pages and third-party error trackers, and
  SQL text names tables and columns and often embeds literals. The statement is
  still carried on the exception — call `getSql()` and `getBinds()` to inspect
  it deliberately. **This is breaking** for anything parsing the message for
  SQL. `QueryException::enableDebug()` restores the old message format for local
  development. Note the driver's own error text is passed through unchanged and
  may still name the table it failed on: withholding the statement narrows what
  leaks, it does not make the message safe to show a user.
- `Query::__callStatic()` now throws `BadMethodCallException` for an unknown
  method instead of returning an unconditioned query. **This is breaking** only
  for code relying on the previous behaviour, which had no legitimate use.
- `QueryLogger` now retains at most 1000 queries by default. **This is breaking**
  for code reading `getQueries()` expecting the complete history of a long run;
  call `QueryLogger::setLimit(0)` for the old unlimited behaviour. Aggregates
  (`getQueryCount()`, `getTotalTime()`) are unaffected and still cover every
  query. `getDroppedCount()` reports how many were discarded.
- Connections are no longer pinged before every query, only after
  `ping_idle_seconds` (default 30) of inactivity. A lost connection is now
  recovered when the statement fails rather than by the pre-query check, so
  recovery is stronger than before rather than weaker. Set `ping_idle_seconds`
  to `0` in a connection's config to restore a ping before every query.

### Added

- `Model::requireAssignmentRules()` makes mass assignment on a model that
  declares neither `$fillable` nor `$guarded` throw `MassAssignmentException`
  instead of accepting every column but the primary key. Opt-in rather than the
  default, deliberately: switching it on for everyone would break existing
  undeclared models silently, at runtime, in exactly the write paths that
  matter. Call it once during bootstrap to find the omissions on the first
  request instead of after a bad write. `Model::allowUndeclaredAssignment()`
  reverses it. Direct assignment and `updateAttributes()` are unaffected.
- `ping_idle_seconds` connection config controls how long a connection may sit
  idle before its next use re-checks it.
- `QueryLogger::setLimit()`, `getLimit()` and `getDroppedCount()` for bounding
  and inspecting the query log.
- `QueryException::enableDebug()` / `disableDebug()` / `isDebug()` control
  whether the failing SQL is appended to the exception message.

### Tests

- 7 security tests covering `ORDER BY` direction whitelisting (rejection and
  valid directions), `update()` mass assignment (guarded attributes stripped,
  directly-assigned attributes preserved), and operator whitelisting (rejection
  across every condition method, and valid comparisons still accepted)
- 3 query builder tests covering negative `limit()` / `offset()`, invalid
  `page()` numbers, and valid limits still applying (including `limit(0)`)
- 7 security tests covering identifier-quote escaping, JSON path escaping, the
  PostgreSQL text search config and the array cast type — each paired with a
  test that the valid forms (`*`, `user.*`, `!user.id`, nested JSON paths,
  `double precision`) are unchanged
- 7 transaction tests covering nesting: an outer rollback discarding work an
  inner call committed, an inner rollback leaving the outer intact, nesting
  beyond two levels, exception propagation from a nested call, and that the
  depth counter unwinds to zero after both success and failure
- 2 cache tests covering key separation by connection and an end-to-end check
  that two databases holding the same table never serve each other's rows
- 2 timestamp tests covering `update()` advancing `updated_at` while leaving
  `created_at` alone, and an explicitly assigned `updated_at` surviving it
- 2 aggregation tests covering the rejected page sizes and that valid ones still
  count correctly, including a query matching no rows
- 7 connection resilience tests run against a live server, severing the
  connection with MySQL's `KILL` rather than simulating it: transparent recovery
  on both reads and writes, a loss inside a transaction throwing instead of
  retrying (asserting no partial write survives and the depth counter unwinds),
  a genuine SQL error being neither retried nor swallowed, and the idle window
  itself — that a live connection is not pinged, that `ping_idle_seconds => 0`
  pings every time, and that an idle connection is still checked. The ping
  assertions count server-side `Com_select` rather than measuring elapsed time,
  so a slow CI machine cannot make them flap.
- 4 query logger tests covering the retention limit, that a limit of `0` keeps
  everything, that lowering the limit trims immediately, and that `reset()`
  clears the dropped totals
- 7 security tests covering the mass assignment opt-in (permissive by default,
  rejecting undeclared models when required, leaving declared models and direct
  assignment alone), `QueryException` keeping SQL out of its message and debug
  mode restoring it, and `Query::` rejecting an unknown method while valid ones
  still work
- 34 relation tests covering the M:N pivot write API, which had no coverage at
  all: `attach()` (including that a duplicate pair throws rather than being
  ignored, and that one bad pair rolls back the whole batch), `detach()` (a
  given subset, `null` for all, that an empty array is not treated as "all", and
  that unattaching a shared tag leaves other rows alone), `sync()` (attach-only,
  detach-only, both at once, idempotence, and string IDs from request data
  comparing equal to integer IDs), `saveMany()`, the `viaTable` guard on
  non-pivot relations, and the accessors. Also covers the `NULL` key fix from
  both directions — an unsaved parent and a saved row with a nullable foreign
  key — and asserts writes on a keyless relation cannot touch the table. This
  takes `Relation` from 34.58% to 100% line coverage.
- 40 `DB` facade tests covering the execution path. The existing suite only
  exercised `sqlOnly` mode, so every method was tested for the SQL it builds
  and none for what it does to a database — the branch that runs in
  production. Covers all four write families including the `IGNORE`, `QUICK`
  and `LOW_PRIORITY` variants, `upsert()` inserting and updating, `raw()` and
  `query()` (including that binds stay data and that both throw on bad SQL),
  `transaction()` committing, rolling back on `false`, rolling back on an
  exception, and preserving the original exception as `getPrevious()`, and the
  `sqlOnly` flag applying to every write method while `raw()` / `query()`
  deliberately bypass it. This takes `DB` from 5.45% to 100% line coverage.

### Documentation

- New "Security: how your values are protected" section in the query builder
  guide, covering value binding, which parts of a query are validated instead of
  bound, validating column names that come from user input, and the
  responsibility that comes with `Raw`.
- The operator whitelist, the `ASC` / `DESC` fallback and the `limit()` /
  `offset()` / `page()` constraints are now documented where they are used, in
  the query builder guide and the cheatsheet.
- New "Nesting transactions" section in the Active Record guide, covering
  savepoint semantics, what an inner versus an outer rollback undoes, and the
  connection-loss behaviour.
- New "What makes a cache entry unique" section in the advanced features guide,
  explaining that the cache key includes the connection name and why that
  matters for multi-tenant setups.

---

## [2.0.6] - 2026-08-13

### Fixed

- **`join()` with a table alias produced a wrong ON clause** — a qualified
  foreign key matching the join table or its alias (`['s.supp_idx' =>
  'supp_idx']` with alias `s`) was quoted whole, generating an invalid column
  reference; the prefix is now stripped before quoting
- **`make:model <ClassName>` ignored the supplied class name** — the generator
  always derived the class name from the table, so the file was written under
  the derived name; `ModelGenerator::className()` now overrides it in both
  `generate()` and `preview()`

---

## [2.0.5] - 2026-07-22

### Fixed

- **`SoftDeletes` trait silently overrode `Model::find()`** — a model defining
  its own `find()` (or using a custom query class) lost the soft delete scope
  entirely, returning trashed records. The scope is now applied by the
  `ActiveQuery` constructor instead of by a trait-level `find()` override, so it
  survives any custom `find()`.
- **`withoutGlobalScope()` dropped the soft delete scope** — excluding one named
  global scope also discarded soft delete filtering; the soft delete scope is
  now re-applied independently.

**Security**

- **Connection charset/collation interpolated into SQL** — `charset`,
  `collation` and `schema` config values were interpolated into `SET NAMES` /
  `SET search_path` unsanitised; now stripped to `[a-zA-Z0-9_]` in the PDO and
  PostgreSQL drivers
- **`NOTIFY` payload escaped by hand** — `str_replace("'", "''", ...)` replaced
  with a prepared `SELECT pg_notify(?, ?)`

### Changed

- `ActiveQuery::__construct()` accepts `withScopes` to opt out of automatic
  scope application
- `Model::applyGlobalScopes()` visibility raised from `protected` to `public`
  so `ActiveQuery` can invoke it

---

## [2.0.4] - 2026-07-16

### Added

**PostgreSQL — Production Grade**

- `RETURNING` clause on INSERT (reliable `lastInsertId` without sequence names)
- `RETURNING` clause on UPDATE/DELETE (`returning()` method +
  `getReturningResult()`)
- `INSERT ... ON CONFLICT DO NOTHING` for `insertOrIgnore()` /
  `DB::insertOrIgnore()`
- `FOR UPDATE`, `FOR SHARE`, `FOR UPDATE NOWAIT`, `FOR UPDATE SKIP LOCKED`
  row-level locking
- `whereFulltext()` / `orWhereFulltext()` — PostgreSQL full-text search via
  `to_tsvector`/`to_tsquery`
- `arrayContains()` / `arrayOverlaps()` — native PG array column queries (`@>`,
  `&&`)
- `jsonHas()` / `jsonMissing()` now uses `jsonb_exists()` on PostgreSQL
- Advisory locks: `advisoryLock()`, `advisoryLockTry()`, `advisoryUnlock()`,
  `advisoryLockTransaction()`, `advisoryLockTransactionTry()`
- LISTEN/NOTIFY: `listen()`, `unlisten()`, `notify()`, `getNotification()`
- Statement cache configurability: `statement_cache`, `statement_cache_size`
  config + `enableStatementCache()`, `disableStatementCache()`
- Schema-qualified table references (`"schema"."table"` quoting)
- Boolean casting handles PostgreSQL `'t'`/`'f'`/`'true'`/`'false'` strings

**Query Builder**

- `explain()` / `explain(analyze: true, format: 'json')` — query execution plan
  for all drivers
- `forUpdate()`, `forShare()`, `forUpdateNoWait()`, `forUpdateSkipLocked()` —
  row-level locking
- `whereFulltext(columns, term, mode, language)` — full-text search (PG:
  tsvector, MySQL: MATCH AGAINST)
- `arrayContains(column, value, type)` / `arrayOverlaps(column, values, type)` —
  array column queries
- `CaseExpression` — fluent CASE WHEN THEN ELSE END builder with `when()`,
  `whenColumn()`, `whenRaw()`, alias support via `->as()`

**Code Generators (CLI)**

- `vendor/bin/fliq make:model <ClassName>` — generate Model from database table
- `vendor/bin/fliq make:model --all` — generate models for all tables
- `vendor/bin/fliq make:observer <ModelName>` — generate Observer class
- `vendor/bin/fliq make:observer --all` — generate observers for all models
- Auto-detection: relations (`*_id` → `hasOne`), traits (SoftDeletes,
  Timestamps), casts, `$guarded`, composite PKs, enum comments
- Config auto-discovery (`config/db.php`, `config/database.php`)
- `--exclude`, `--dry-run`, `--preview`, `--force`, `--verbose`, colored output
- Namespace auto-detection from `composer.json` PSR-4 mapping

**Documentation**

- `docs/10-POSTGRESQL.md` — full PostgreSQL production guide
- `docs/11-MODEL-GENERATOR.md` — Model generator tutorial
- `docs/12-OBSERVER-GENERATOR.md` — Observer generator tutorial

### Changed

- PostgreSQL driver no longer labeled "beta"
- `Model::insert()` uses `RETURNING` on PostgreSQL for reliable auto-increment
  ID retrieval
- `jsonHas()` / `jsonMissing()` now use Grammar interface (`jsonKeyExists()`)
  instead of hardcoded MySQL SQL
- LIKE methods auto-use `ILIKE` / `NOT ILIKE` on PostgreSQL when
  case-insensitive (verified, no change needed)

### Fixed

- `INSERT IGNORE` on PostgreSQL now generates `ON CONFLICT DO NOTHING` (was
  producing bare `INSERT`)
- `PDO::lastInsertId()` on PostgreSQL now works via `RETURNING` (was unreliable
  without sequence name)
- Boolean cast `(bool)'f'` no longer incorrectly returns `true` on PostgreSQL
- Schema-qualified tables now quote correctly (`"public"."users"` not
  `"public.users"`)

---

## [1.0.0] - 2026-05-20

Initial release.

- Fluent query builder (SELECT, INSERT, UPDATE, DELETE, UPSERT)
- Active Record pattern with hasOne/hasMany/viaTable relations
- PDO driver (MySQL/MariaDB) and MySQLi driver
- PostgreSQL driver
- Eager loading with dot notation and constraints
- Soft deletes and timestamps traits
- Collection with lazy/chunked iteration
- JSON column queries
- Fulltext search (MATCH AGAINST)
- Query logging and N+1 detection
- PHPStan level 8, PHPMD, PSR-12

### Added

**Query Builder**
- `whereAny(array $columns, string $operator, mixed $value)` — WHERE (col1 op ? OR col2 op ?)
- `whereAll(array $columns, string $operator, mixed $value)` — WHERE (col1 op ? AND col2 op ?)
- `whereNone(array $columns, string $operator, mixed $value)` — WHERE NOT (col1 op ? OR col2 op ?)
- `orWhereAny()`, `orWhereAll()`, `orWhereNone()` — OR variants

**JSON Queries**
- Auto JSON extraction via `->` notation in `where()`, `in()`, `orderBy()`, `groupBy()`, etc.
  - `->where('meta->age', '>', 25)` now works without `whereJson()`
- `whereJsonDoesntContain(string $column, mixed $value)` — NOT JSON_CONTAINS
- `whereJsonContainsKey(string $column)` — JSON_CONTAINS_PATH key exists
- `whereJsonDoesntContainKey(string $column)` — NOT JSON_CONTAINS_PATH
- `orWhereJson()`, `orWhereJsonContains()`, `orWhereJsonDoesntContain()`
- `orWhereJsonContainsKey()`, `orWhereJsonDoesntContainKey()`
- `orWhereJsonLength()`
- Short aliases: `jsonContains()`, `jsonNotContains()`, `jsonHas()`, `jsonMissing()`

### Fixed

- **PDODriver statement cache HY093** — `execute(null)` on a cached prepared statement caused parameter count mismatch; fixed by passing `[]` instead of `null`
- **`has()`/`whereHas()`/`doesntHave()`/`whereDoesntHave()` wrong table correlation** — EXISTS subquery referenced the wrong table; fixed by using explicit `parent_table.local_key` reference
- **`viaTable()` M:N wrong JOIN column** — `JOIN post_tag ON post_tag.id = tag.id` was generated instead of `post_tag.tag_id = tag.id`; fixed in both `Relation::applyConstraints()` and `EagerLoader::buildBatchQuery()`
- **`Condition::buildSQL()` null bind injection** — `appendBinds(null)` was called when a `Raw` object had no binds, adding a phantom null bind value

### Improved

- PHPStan level 8 — 0 errors across `src/` and `tests/`
- PHPMD — 0 violations
- Null safety in all 3 drivers (PDO, MySQLi, PostgreSQL) — connection guards throw `RuntimeException` instead of calling methods on null
- Full iterable value type annotations on all array properties and parameters

### Tests

- 321 integration tests covering: CRUD, relationships, eager loading, soft deletes, timestamps, collections, JSON queries, fulltext search, GROUP BY/HAVING/JOIN, query monitoring/logging, security (SQL injection resistance), upsert execution, MySQLi driver
