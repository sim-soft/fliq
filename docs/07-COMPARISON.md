# FLIQ vs Other PHP ORMs

A detailed feature comparison between FLIQ and popular PHP ORM/Active Record libraries.

## Benchmark Results

Measured on PHP 8.4, MySQL 8.0, single machine (no network latency). Run `php benchmarks/run.php` to reproduce.

### Query Building (no DB execution, 10,000 iterations)

| Operation | Time per query | Memory |
|-----------|:--------------:|:------:|
| Simple SELECT (`where('status', 1)`) | 6.9μs | 448 bytes/object |
| Complex WHERE (5 conditions) | 9.1μs | — |
| JOIN + GROUP BY + HAVING + ORDER BY + LIMIT | 9.3μs | — |

### Database Execution (MySQL, 1,000 iterations)

| Operation | Time per query |
|-----------|:--------------:|
| `findByPk(1)` | 1.09ms |
| `find()->where()->first()` | 1.15ms |
| `find()->get()->all()` (10 rows) | 1.26ms |

### Model Hydration

| Operation | Time | Memory |
|-----------|:----:|:------:|
| 1,000 models hydrated | 0.67ms total | 543KB |
| Per model | 0.67μs | 536 bytes |

### Memory Footprint

| Object | Size |
|--------|:----:|
| ActiveQuery instance | 448 bytes |
| Model instance | 536 bytes |
| Peak memory (full benchmark) | 6 MB |

## Performance

> **Disclaimer:** The comparison values for other ORMs are estimates based on architecture analysis (object count, abstraction layers) and published community benchmarks. They are not measured on the same machine. Run your own benchmarks for production decisions.

| Metric                     |   FLIQ    | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|----------------------------|:---------:|:--------:|:--------:|:-------:|:---------:|:----------:|
| Query builder overhead     | ~1 object | 5-10 obj | 20+ obj  | 3-5 obj | 10-15 obj |  5-8 obj   |
| Memory per simple query    |   ~2KB    |   ~8KB   |  ~50KB   |  ~6KB   |   ~30KB   |   ~10KB    |
| Install size               |  ~100KB   |   ~5MB   |  ~10MB   |  ~2MB   |   ~8MB    |    ~3MB    |
| Dependencies               |     0     |   30+    |   15+    |   8+    |    20+    |    ~10     |
| Zero-allocation query path |     ✅     |    ❌     |    ❌     |    ❌    |     ❌     |     ❌      |
| Prepared statement caching |     ✅     |    ❌     |    ✅     |    ❌    |     ❌     |     ❌      |

## Architecture

| Aspect                    |       FLIQ        |         Eloquent         |  Doctrine   |             Yii3 AR              |        Cycle ORM         |            Propel ORM            |
|---------------------------|:-----------------:|:------------------------:|:-----------:|:--------------------------------:|:------------------------:|:--------------------------------:|
| Pattern                   |   Active Record   |      Active Record       | Data Mapper |          Active Record           |       Data Mapper        |          Active Record           |
| Unit of Work              |   None (direct)   |           None           |    High     |               None               |           High           |          None (direct)           |
| Learning curve            |        Low        |          Medium          |    High     |              Medium              |           High           |              Medium              |
| Standalone (no framework) |         ✅         |            ❌             |      ✅      |                ✅                 |            ✅             |                ✅                 |
| Database support          | MySQL, PG, SQLite | MySQL, PG, SQLite, MSSQL |  All major  | MySQL, PG, SQLite, MSSQL, Oracle | MySQL, PG, SQLite, MSSQL | MySQL, PG, SQLite, MSSQL, Oracle |

## Query Builder Features

| Feature                         |  FLIQ  | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|---------------------------------|:------:|:--------:|:--------:|:-------:|:---------:|:----------:|
| Fluent query builder            |   ✅    |    ✅     |    ✅     |    ✅    |     ✅     |     ✅      |
| Scopes / when / unless          |   ✅    |    ✅     |    ❌     | Partial |     ❌     |  Partial   |
| whereAny / whereAll / whereNone |   ✅    |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| Upsert                          |   ✅    |    ✅     |    ❌     |    ❌    |     ❌     |     ✅      |
| Sub-queries                     |   ✅    |    ✅     |    ✅     |    ✅    |     ✅     |     ✅      |
| Unions                          |   ✅    |    ✅     |    ✅     |    ✅    |     ✅     |     ❌      |

## JSON Support

| Feature                       | FLIQ | Eloquent | Doctrine | Yii3 AR | Cycle ORM | Propel ORM |
|-------------------------------|:----:|:--------:|:--------:|:-------:|:---------:|:----------:|
| JSON where (auto -> notation) |  ✅   |    ✅     |    ❌     |    ❌    |  Partial  |     ❌      |
| JSON contains / length / key  |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| JSON column casts             |  ✅   |    ✅     |    ✅     |    ❌    |     ✅     |     ❌      |

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
| Full-text search (MATCH)  |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| Cursor (unbuffered)       |  ✅   |    ✅     |    ❌     |    ❌    |     ❌     |     ❌      |
| N+1 query detection       |  ✅   | Package  |    ❌     |    ❌    |     ❌     |     ❌      |
| Query logging with timing |  ✅   |    ✅     |    ✅     |    ❌    |     ❌     |     ❌      |

## Summary

FLIQ is purpose-built for developers who want Active Record simplicity with maximum performance. It trades broad database support (no MSSQL/Oracle) and ecosystem size for a dramatically smaller footprint, zero dependencies, and the fastest query compilation path available in PHP.

If you need a Data Mapper pattern, schema migrations, or MSSQL/Oracle support, consider Doctrine or Cycle ORM. If you need a massive plugin ecosystem, Eloquent is the pragmatic choice. For everything else, FLIQ gets out of your way and lets you ship.
