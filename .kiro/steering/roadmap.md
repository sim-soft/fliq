---
inclusion: manual
---

# Roadmap

## Design Constraints (all features must satisfy)

- **Secure** — no new SQL injection vectors; parameterized by default; Raw usage
  must be explicit opt-in
- **High performance** — no unnecessary allocations, lazy evaluation where
  possible, zero overhead when feature unused
- **Ease of use** — fluent chainable API, sensible defaults, minimal boilerplate
  for common cases
- **Easy to learn** — Eloquent-familiar naming, consistent method patterns,
  every feature documented with SQL output

## High Priority

- [ ] Batch upsert (multi-row Upsert like insertBatch with conflict resolution)
- [ ] PSR-3 query logging adapter (bridge QueryLogger to Monolog/any PSR-3
  logger)
- [ ] Model toArray() / toJson() with $hidden and $appends support
- [ ] Verify Timestamps trait auto-touches updated_at on update()
- [ ] Window functions (OVER, PARTITION BY, ROW_NUMBER, RANK) via fluent API
- [ ] CTE support (WITH clause — recursive and non-recursive)
- [ ] firstOrCreate / firstOrNew (atomic find-or-insert)
- [ ] Eager load constraints (->with(['posts' => fn($q) => $q->where(...)]))
- [ ] whereHas (relation-aware exists without manual sub-query)
- [ ] In-memory SQLite test helper (Connection::testing() — zero-setup unit
  testing)
- [ ] Clone query ($query->clone() — prevent shared query mutation)

## Medium Priority

- [ ] HasMany through relation (Country → Users → Posts)
- [ ] BelongsTo relation (inverse of hasOne/hasMany)
- [ ] Schema builder (create/alter/drop table — standalone migrations)
- [ ] BackedEnum casting in $casts
- [ ] Typed Collection<T> via @template annotations
- [ ] INSERT from SELECT (bulk data movement without roundtrip)
- [ ] Mass update/delete from query without loading models
- [ ] Hidden attributes ($hidden — excluded from serialization)
- [ ] Appended computed attributes ($appends — virtual getters in serialization)
- [ ] Query timeout per-query (MySQL MAX_EXECUTION_TIME, PG statement_timeout)
- [ ] Read-after-write consistency (force reads to write connection after write)
- [ ] Subquery in WHERE scalar (->where('salary', '>', fn($q) => ...))
- [ ] Select subquery as column (computed columns in SELECT)
- [ ] Eager load count (->withCount('comments') adds comments_count)
- [ ] Increment/decrement shorthand ($model->increment('login_count'))
- [ ] Model refresh ($model->refresh() — reload from DB)
- [ ] Raw bindings in orderByRaw (accept bind parameters)
- [ ] Union ordering/limit (ORDER BY and LIMIT on outer union)
- [ ] Fake/mock driver (Driver::fake() — records queries, returns canned
  results)
- [ ] Database assertions (assertDatabaseHas/assertDatabaseMissing PHPUnit
  trait)
- [ ] Bulk delete by IDs (User::destroy([1, 2, 3]) with model events)
- [ ] Pluck with key column (->pluck('name', 'id') returns keyed map)
- [ ] Each with chunking (->each(fn($u) => ..., chunkSize: 100))
- [ ] Mass assignment protection on update (verify guarded
  blocks $model->update($externalArray))
- [ ] Exclusive scope (->without('softDelete') — disable specific scope)
- [ ] Named read replicas (multiple read connections with round-robin selection)

## Low Priority

- [ ] Nested transactions (savepoints)
- [ ] Composite relation keys
- [ ] Database connection events (connect/disconnect/query-error hooks)
- [ ] Lazy collection (generator-based filter/map/chunk)
- [ ] LATERAL JOIN (PostgreSQL correlated sub-queries in FROM)
- [ ] Conditional aggregates (COUNT FILTER WHERE / CASE WHEN via fluent API)
- [ ] Connection retry with configurable backoff
- [ ] Touch related timestamps (update parent updated_at on child change)
- [ ] Model factory for testing (User::factory()->create())
- [ ] Model IDE helper generator (@property docblocks from DB schema)
- [ ] PHPStan extension / type stubs for typed query results
- [ ] Debug bar collector (PSR-compatible query count/time)
- [ ] HasOne through relation
- [ ] Immutable models ($immutable — prevent writes on read-only tables)
- [ ] Attribute accessors (getFullNameAttribute() convention)
- [ ] Model lifecycle events (creating, created, updating, updated, deleting,
  deleted)
- [ ] Duplicate query detection (extend QueryMonitor)
- [ ] Slow query threshold (log only queries exceeding N ms)
- [ ] Query count assertions for testing (QueryMonitor::assertQueryCount())
- [ ] MariaDB-specific grammar (JSON, CTE, window function divergences)
- [ ] RETURNING for MySQLi (MariaDB 10.5+ support)
- [ ] Database version detection (auto-detect capabilities, degrade gracefully)
- [ ] Query parameter type validation (reject objects/resources as bind values)
- [ ] SQL injection audit mode (dev-only warn when Raw used without binds)
- [ ] Streaming CSV/JSON export (->streamCsv($handle) — row-by-row without
  buffering)
- [ ] Chunked insert from iterable (insertFromIterable($generator, chunkSize))
- [ ] Connection health check (Connection::healthCheck() — latency + status)
- [ ] Configurable idle timeout (auto-disconnect for long-running workers)
- [ ] whereColumn cross-table (->whereColumn('order.user_id', '=', 'user.id'))

## Verify (may already exist)

- [ ] pluck() with key column
- [ ] when() inside join callbacks
- [ ] orderBy() with array syntax
- [ ] clone() on ActiveQuery
- [ ] whereColumn cross-table support

## Documentation / Ecosystem

- [ ] Interactive playground (browser-based FLIQ code → generated SQL preview)
- [ ] Benchmark suite vs Eloquent/Doctrine (automated, published in docs)
- [ ] Upgrade guide from Eloquent (side-by-side translation table)
- [ ] Recipe cookbook (API pagination, search filters, reporting, job queues
  with FOR UPDATE SKIP LOCKED)

## Out of Scope

| Feature                             | Reason                                                                          |
|-------------------------------------|---------------------------------------------------------------------------------|
| Migration CLI                       | Framework-level orchestration — users can wire schema builder + any file runner |
| Database seeder CLI                 | Testing concern — belongs in the test suite, not the ORM                        |
| Attribute mutators                  | Casts + `__get`/`__set` already cover this adequately                           |
| Multi-tenancy                       | Too opinionated for a standalone library                                        |
| Connection pooling                  | Infrastructure concern — belongs in PgBouncer/ProxySQL, not the ORM             |
| Repository pattern                  | Fights against Active Record philosophy                                         |
| Polymorphic relations               | High complexity, rare outside Laravel apps                                      |
| Query scopes as classes             | Closures + custom ActiveQuery subclass already covers this                      |
| Query result DTOs                   | Adds mapping layer complexity — toArray() + casting is sufficient               |
| Auto-discovery of models            | Magic/scanning — conflicts with explicit philosophy                             |
| Database queue driver               | Job queue is application-level, not ORM-level                                   |
| GraphQL integration                 | Too coupled to a specific API style                                             |
| Event sourcing                      | Entirely different architecture from Active Record                              |
| Multi-database joins                | Extremely complex, rarely needed outside enterprise                             |
| Query result streaming (SSE)        | Application-layer concern, not ORM                                              |
| Automatic sharding                  | Infrastructure-level routing, not ORM                                           |
| Audit trail (created_by/updated_by) | Requires app context the ORM shouldn't know                                     |
| Versioned models (history)          | Better as a separate package                                                    |
