# FLIQ vs Other PHP ORMs

A detailed feature comparison between FLIQ and popular PHP ORM/Active Record libraries.

## Benchmark Results

Measured on PHP 8.4.16, MySQL 8.0.30, single machine (no network latency). Run
`php benchmarks/run.php` to reproduce. Timings are the median of seven runs; the
query-building and memory figures were stable across all of them, while the
database numbers varied by roughly ±40% run to run, so treat them as an order of
magnitude rather than a measurement.

### Query Building (no DB execution, 10,000 iterations)

| Operation                                   | Time per query |      Memory      |
|---------------------------------------------|:--------------:|:----------------:|
| Simple SELECT (`where('status', 1)`)        |     5.6μs      | 640 bytes/object |
| Complex WHERE (5 conditions)                |     11.4μs     |        —         |
| JOIN + GROUP BY + HAVING + ORDER BY + LIMIT |     11.4μs     |        —         |

### Database Execution (MySQL)

Against `resources/sample_db.sql`, whose `user` table holds 10 rows, 8 of them
matching the `status_code = 1` the benchmark filters on. The first two run 1,000
iterations, the third 100.

| Operation                       | Time per query |
|---------------------------------|:--------------:|
| `findByPk(1)`                   |     0.82ms     |
| `find()->where()->first()`      |     0.77ms     |
| `find()->get()->all()` (8 rows) |     0.95ms     |

### Model Hydration

| Operation             |     Time     |  Memory   |
|-----------------------|:------------:|:---------:|
| 1,000 models hydrated | 0.48ms total |   606KB   |
| Per model             |    0.48μs    | 600 bytes |

### Memory Footprint

| Object                       |   Size    |
|------------------------------|:---------:|
| ActiveQuery instance         | 640 bytes |
| Model instance               | 600 bytes |
| Peak memory (full benchmark) |   6 MB    |

## Performance

> **Disclaimer:** Only the FLIQ column is measured. The values for other ORMs are estimates based on architecture analysis (object count, abstraction layers) and published community benchmarks — they are not measured on the same machine, and the same caveat applies to the ❌/✅ marks in the feature tables below. Run your own benchmarks, against the versions you actually use, for production decisions.

| Metric                     |   FLIQ    | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|----------------------------|:---------:|:--------:|:--------:|:-------:|:---------:|:----------:|
| Query builder overhead     | 2 objects | 5-10 obj | 20+ obj  | 3-5 obj | 10-15 obj |  5-8 obj   |
| Memory per simple query    |  ~1.2KB   |   ~8KB   |  ~50KB   |  ~6KB   |   ~30KB   |   ~10KB    |
| Source size                |  ~650KB   |   ~5MB   |  ~10MB   |  ~2MB   |   ~8MB    |    ~3MB    |
| Dependencies               |     0     |   30+    |   15+    |   8+    |    20+    |    ~10     |
| No per-condition objects   |     ✅     |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |
| Prepared statement caching |     ✅     |    ❌     |    ✅     |    ❌    |     ❌     |     ❌      |

A built query holds two objects — the `ActiveQuery` and its grammar — and stays
at two however many conditions you add, because conditions are compiled into the
SQL string rather than accumulated as a node tree. That is the sense in which the
query path is cheap; it is not literally allocation-free, and a simple built
query retains ~1.2KB.

## Architecture

| Aspect                    |       FLIQ        |         Eloquent         |  Doctrine   |             Yii3 AR              |        Cycle ORM         |            Propel ORM            |
|---------------------------|:-----------------:|:------------------------:|:-----------:|:--------------------------------:|:------------------------:|:--------------------------------:|
| Pattern                   |   Active Record   |      Active Record       | Data Mapper |          Active Record           |       Data Mapper        |          Active Record           |
| Unit of Work              |   None (direct)   |           None           |    High     |               None               |           High           |          None (direct)           |
| Learning curve            |        Low        |          Medium          |    High     |              Medium              |           High           |              Medium              |
| Standalone (no framework) |         ✅         |            ❌             |      ✅      |                ✅                 |            ✅             |                ✅                 |
| Database support          | MySQL, PG, SQLite | MySQL, PG, SQLite, MSSQL |  All major  | MySQL, PG, SQLite, MSSQL, Oracle | MySQL, PG, SQLite, MSSQL | MySQL, PG, SQLite, MSSQL, Oracle |

## Query Builder Features

| Feature                           | FLIQ | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|-----------------------------------|:----:|:--------:|:--------:|:-------:|:---------:|:----------:|
| Fluent query builder              |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |     ✅      |
| Scopes / when / unless            |  ✅   |    ✅     |    ❌     | Partial |     ❌     |  Partial   |
| whereAny / whereAll / whereNone   |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| Upsert                            |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ✅      |
| Sub-queries                       |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |     ✅      |
| Unions                            |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |     ❌      |
| Row-level locking (FOR UPDATE)    |  ✅   |    ✅     |    ✅     |    ❌    |     ✅     |     ❌      |
| RETURNING on INSERT/UPDATE/DELETE |  ✅   |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |
| Fluent CASE WHEN builder          |  ✅   |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |

## JSON Support

| Feature                       | FLIQ | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|-------------------------------|:----:|:--------:|:--------:|:-------:|:---------:|:----------:|
| JSON where (auto -> notation) |  ✅   |    ✅     |    ❌     |    ❌    |  Partial  |     ❌      |
| JSON contains / length / key  |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| JSON column casts             |  ✅   |    ✅     |    ✅     |    ❌    |     ✅     |     ❌      |
| Array column queries (PG)     |  ✅   |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |

## Active Record Features

| Feature                        | FLIQ | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|--------------------------------|:----:|:--------:|:--------:|:-------:|:---------:|:----------:|
| Nested eager loading           |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |     ❌      |
| Relations (hasOne/hasMany/M:N) |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |     ✅      |
| whereHas / doesntHave          |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| Soft deletes                   |  ✅   |    ✅     |    ❌     | Package |  Package  |  Behavior  |
| Timestamps                     |  ✅   |    ✅     |    ❌     | Package |  Package  |  Behavior  |
| Model events / observers       |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |  Behavior  |
| Attribute casting              |  ✅   |    ✅     |    ✅     |    ❌    |     ✅     |     ❌      |
| Dirty tracking                 |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |     ✅      |

## Developer Tools

| Feature                   | FLIQ | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|---------------------------|:----:|:--------:|:--------:|:-------:|:---------:|:----------:|
| Full-text search          |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| Full-text search (PG FTS) |  ✅   |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |
| Cursor (unbuffered)       |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| N+1 query detection       |  ✅   | Package  |    ❌     |    ❌    |     ❌     |     ❌      |
| Query logging with timing |  ✅   |    ✅     |    ✅     |    ❌    |     ❌     |     ❌      |
| Advisory locks (PG)       |  ✅   |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |
| LISTEN / NOTIFY (PG)      |  ✅   |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |
| EXPLAIN / query plans     |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| Model generator (CLI)     |  ✅   |    ✅     |    ✅     |    ✅    |     ✅     |     ✅      |
| Observer generator (CLI)  |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |

## Summary

FLIQ is purpose-built for developers who want Active Record simplicity with maximum performance. It trades broad database support (no MSSQL/Oracle) and ecosystem size for a smaller footprint, zero runtime dependencies, and a query compilation path that stays at two objects no matter how large the query gets.

If you need a Data Mapper pattern, schema migrations, or MSSQL/Oracle support, consider Doctrine or Cycle ORM. If you need a massive plugin ecosystem, Eloquent is the pragmatic choice. For everything else, FLIQ gets out of your way and lets you ship.
