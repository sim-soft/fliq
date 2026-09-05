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

**Query Builder**

- **A negative `limit()` silently returned every row** — `hasLimit()` tested
  `$limit > 0`, so a negative limit reported that no limit was set and
  `Collection` fell back to paginating the full result set. `limit(-5)` returned
  the entire table while `getSQL()` displayed `LIMIT -5`. Negative values are now
  rejected by `limit()` and `offset()`, and `page()` requires a page of 1 or
  greater (`page(0)` previously computed a negative offset).
- `mysqli::ping()` is deprecated in PHP 8.4 and emitted a deprecation notice on
  every reconnect check; `MySQLiDriver::ping()` now issues `SELECT 1`, matching
  the PDO, PostgreSQL and SQLite drivers.

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
