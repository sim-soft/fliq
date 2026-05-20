# Changelog

All notable changes to `simsoft/fliq` are documented here.

## [1.1.0] - 2025-07-01

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

---

## [1.0.0] - 2025-01-01

Initial release.

- Fluent query builder (SELECT, INSERT, UPDATE, DELETE, UPSERT)
- Active Record pattern with hasOne/hasMany/viaTable relations
- PDO driver (MySQL/MariaDB) and MySQLi driver
- PostgreSQL driver (beta)
- Eager loading with dot notation and constraints
- Soft deletes and timestamps traits
- Collection with lazy/chunked iteration
- JSON column queries
- Fulltext search (MATCH AGAINST)
- Query logging and N+1 detection
- PHPStan level 8, PHPMD, PSR-12
