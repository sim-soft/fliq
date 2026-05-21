# FLIQ

> **F**ast, **L**ightweight, **I**ndependent **Q**uery Builder

A high-performance Active Record / ORM for MySQL, MariaDB, PostgreSQL, and
SQLite. Zero framework dependencies, minimal footprint, maximum speed.

## Quick Example

```php
use Simsoft\DB\Connection;
use Simsoft\DB\Model;

Connection::add('mysql', [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'my_app',
    'username' => 'root',
    'password' => '',
]);

class User extends Model
{
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

- **Fast** — One object per query, zero-allocation fast path, prepared statement
  caching
- **Lightweight** — ~100KB install size, zero dependencies
- **Independent** — No framework coupling, one `composer require` and done
- **Query Builder** — Fluent API that compiles directly to optimized SQL

## Install

```bash
composer require simsoft/fliq
```

Requires PHP 8.1+ with `ext-pdo`.

## Features

- Fluent query builder with full SQL output
- Active Record CRUD with dirty tracking
- Relations: hasOne, hasMany, viaTable (M:N)
- Nested eager loading (`with('posts.comments.author')`)
- JSON column queries with auto `->` notation
- Soft deletes, timestamps, model events
- Cursor pagination, batch operations
- N+1 detection, query logging, index advisor
- Read/write connection splitting
- PDO, MySQLi, PostgreSQL, SQLite drivers

## Requirements

- PHP 8.1+
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

## License

MIT
