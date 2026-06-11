# FLIQ — PHP Active Record ORM

> **F**ast, **L**ightweight, **I**ndependent **Q**uery Builder

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://github.com/sim-soft/fliq/blob/main/LICENSE)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-8892BF.svg)](https://www.php.net/releases/8.4/en.php)

A high-performance PHP Active Record / ORM for MySQL, MariaDB, PostgreSQL, and
SQLite. Zero framework dependencies, minimal footprint, maximum speed. An
alternative to Eloquent and Doctrine for developers who want speed without
complexity.

## What is FLIQ?

FLIQ is a standalone PHP database library that combines a fluent query builder
with the Active Record pattern. It compiles queries directly to optimized SQL
using a single object per query — no intermediate layers, no hidden allocations,
no framework required.

Built for developers who:

- Want Eloquent-like syntax without Laravel
- Need an ORM that doesn't slow down their application
- Prefer understanding their tools (50 source files, readable in an afternoon)
- Work with MySQL, PostgreSQL, or SQLite

FLIQ is part of the [Simsoft](https://github.com/sim-soft) ecosystem — a
collection of lightweight, independent PHP libraries.

## Quick Example

```php
use Simsoft\DB\Connection;
use Simsoft\DB\Model;

Connection::add('mysql', [
    'driver' => 'mysqli',
    'host' => 'localhost',
    'database' => 'my_app',
    'username' => 'root',
    'password' => '',
]);

class User extends Model
{
    protected string $connection = 'mysql';
    protected string $table = 'user';
    protected array $fillable = ['name', 'email', 'status'];
}

/* SELECT `user`.* FROM `user`
   WHERE `user`.`status` = ?
   ORDER BY `user`.`name` ASC */
$users = User::find()
    ->where('status', 'active')
    ->with('posts.comments')
    ->orderBy('name')
    ->get();
```

## Why FLIQ?

| Principle         | What it means                                                                                           |
|-------------------|---------------------------------------------------------------------------------------------------------|
| **Fast**          | One object per query, zero-allocation fast path, prepared statement caching. Query building takes ~7μs. |
| **Lightweight**   | ~100KB install size, zero runtime dependencies. No service containers, no config files.                 |
| **Independent**   | No framework coupling. Works in any PHP project — vanilla, Slim, Symfony, or your own framework.        |
| **Query Builder** | Fluent API that compiles directly to optimized SQL. Every query shows you the actual SQL it generates.  |

## Install

```bash
composer require simsoft/fliq
```

Requires PHP 8.4+ with `ext-pdo`.

## Features

### Query Builder

- Fluent method chaining with full SQL output
- WHERE, IN, BETWEEN, LIKE, EXISTS, REGEX conditions
- JOIN (inner, left, right, cross) with dot notation
- JSON column queries with auto `->` path notation
- Sub-queries, unions, aggregations (count, sum, avg, min, max)
- Raw expressions: `selectRaw()`, `whereRaw()`, `orderByRaw()`, `havingRaw()`
- Date filters: `whereDate()`, `whereMonth()`, `whereYear()`, `whereTime()`
- Multi-column conditions: `whereAny()`, `whereAll()`, `whereNone()`
- Conditional clauses: `when()`, `unless()`, `scope()`, `tap()`

### Active Record

- CRUD with automatic dirty tracking
- Mass assignment protection (fillable/guarded)
- Attribute casting (int, bool, float, string, array, JSON)
- Composite primary keys
- Lifecycle hooks and model events/observers
- Soft deletes and automatic timestamps
- Scenarios for context-aware validation
- `saveTogether()` — save model + all relations in one transaction

### Relations

- hasOne, hasMany, viaTable (many-to-many)
- Nested eager loading: `with('posts.comments.author')`
- Constrained eager loading with callbacks
- Relation filtering: `has()`, `doesntHave()`, `whereHas()`
- Write operations: `save()`, `saveMany()`, `attach()`, `detach()`, `sync()`

### Collections

- Lazy iteration with chunked fetching
- `filter()`, `map()`, `reduce()`, `indexBy()`, `groupBy()`, `pluck()`
- Batch processing and cursor pagination

### Developer Tools

- N+1 query detection (`QueryMonitor`)
- Query logging with timing (`QueryLogger`)
- Index advisor — suggests missing indexes from logged queries
- `dd()` and `dump()` — inspect generated SQL during development
- Query result caching with pluggable drivers

### Database Support

- MySQL 5.7+ / MariaDB 10.3+ (PDO and MySQLi drivers)
- PostgreSQL 12+ (PDO driver)
- SQLite 3.39+ (PDO driver)
- Read/write connection splitting
- Prepared statement caching
- Persistent connections

## Performance

| Metric                       | Result           |
|------------------------------|------------------|
| Simple SELECT build          | 6.9μs per query  |
| Complex WHERE (5 conditions) | 9.1μs per query  |
| Model hydration              | 0.67μs per model |
| ActiveQuery object size      | 448 bytes        |
| Model instance size          | 536 bytes        |
| Install size                 | ~100KB           |
| Dependencies                 | 0                |

See [full benchmarks](07-COMPARISON.md) for comparison with Eloquent, Doctrine,
and other ORMs.

## Requirements

- PHP 8.4+
- MySQL 5.7+ / MariaDB 10.3+ / PostgreSQL 12+ / SQLite 3.39+
- ext-pdo (required) or ext-mysqli (optional)

## Documentation

| Guide                                        | Topics                                             |
|----------------------------------------------|----------------------------------------------------|
| [Getting Started](01-GETTING-STARTED.md)     | Connections, drivers, raw queries, DB facade       |
| [Query Builder](02-QUERY-BUILDER.md)         | WHERE, JOIN, JSON, aggregation, scopes, unions     |
| [Active Record](03-ACTIVE-RECORD.md)         | Models, CRUD, casting, events, soft deletes        |
| [Relations](04-RELATION.md)                  | hasOne, hasMany, viaTable, eager loading, whereHas |
| [Advanced Features](05-ADVANCED-FEATURES.md) | Caching, pagination, batch, index advisor          |
| [Collections](06-COLLECTIONS.md)             | filter, map, reduce, indexBy, groupBy              |
| [Comparison](07-COMPARISON.md)               | Benchmarks, FLIQ vs Eloquent/Doctrine/Yii3         |
| [Cheatsheet](08-CHEATSHEET.md)               | Quick reference for all operations                 |

## Links

- [GitHub Repository](https://github.com/sim-soft/fliq)
- [Packagist](https://packagist.org/packages/simsoft/fliq)
- [Simsoft Organization](https://github.com/sim-soft)

## License

MIT
