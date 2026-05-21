# FLIQ

**F**ast, **L**ightweight, **I**ndependent **Q**uery Builder

A high-performance Active Record / ORM for MySQL, MariaDB, PostgreSQL, and SQLite. Zero framework dependencies, minimal footprint, maximum speed.

📖 [Documentation](https://sim-soft.github.io/fliq/) · [GitHub](https://github.com/sim-soft/fliq)

```php
use Simsoft\DB\Model;

class User extends Model
{
    protected string $table = 'user';
    protected array $fillable = ['name', 'email', 'status'];
}

// Query with fluent builder
$users = User::find()
    ->where('status', 'active')
    ->with('posts.comments')  // nested eager loading
    ->orderBy('name')
    ->get();

// CRUD
$user = new User(['name' => 'John', 'email' => 'john@example.com']);
$user->save();
```

## Why FLIQ?

The name says it all — **F**ast, **L**ightweight, **I**ndependent **Q**uery Builder.

- **Fast** — One object per query, zero-allocation fast path, prepared statement caching. No other PHP ORM builds query this lean.
- **Lightweight** — ~100KB install size, zero dependencies. No service containers, no config files, no boot process.
- **Independent** — Standalone library with no framework coupling. One `composer require`, one `Connection::add()` call, done.
- **Query Builder** — Fluent, expressive API that compiles directly to optimized SQL without intermediate object layers.

## When NOT to Choose FLIQ

- You need MSSQL or Oracle support
- You need schema migrations (use [Phinx](https://phinx.org/) or [doctrine/migrations](https://www.doctrine-project.org/projects/migrations.html))
- You need a Data Mapper pattern (use Doctrine or Cycle ORM)
- You need a massive ecosystem of community packages (use Eloquent)

For a detailed feature-by-feature comparison with Eloquent, Doctrine, Yii3, Cycle ORM, and Propel, see the [Comparison Guide](docs/07-COMPARISON.md).

## Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+ / PostgreSQL 12+ / SQLite 3.39+
- ext-pdo (required) or ext-mysqli (optional alternative for MySQL)

## Install

```shell
composer require simsoft/fliq
```

## Quick Start

### 1. Configure Connection

```php
require "vendor/autoload.php";

use Simsoft\DB\Connection;

Connection::add('mysql', [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'my_app',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
]);
```

### 2. Define a Model

```php
use Simsoft\DB\Model;
use Simsoft\DB\Relation;

class Post extends Model
{
    protected string $table = 'post';
    protected array $fillable = ['title', 'content', 'user_id'];

    public function author(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function comments(): Relation
    {
        return $this->hasMany(Comment::class, ['post_id' => 'id']);
    }
}
```

### 3. Use It

```php
// Find by primary key
$post = Post::findByPk(1);

// Query with conditions
$posts = Post::find()
    ->where('status', 'published')
    ->with('author', 'comments')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Create
$post = new Post(['title' => 'Hello', 'content' => 'World']);
$post->save();

// Update
$post->title = 'Updated';
$post->save();

// Delete
$post->delete();
```

## Features

- [Zero-Allocation Query Building](#zero-allocation-query-building)
- [Nested Eager Loading](#nested-eager-loading)
- [Conditional Queries](#conditional-queries)
- [JSON Column Queries](#json-column-queries)
- [Multi-Column Conditions](#multi-column-conditions)
- [Transactions](#transactions)
- [Soft Deletes & Timestamps](#soft-deletes--timestamps)
- [Model Events](#model-events)
- [Query Result Caching](#query-result-caching)
- [Read/Write Connection Splitting](#readwrite-connection-splitting)
- [Development Tools](#development-tools)

---

### Zero-Allocation Query Building

The most common query patterns (`where`, `in`, `like`, `between`, `orderBy`, `select`) build SQL strings directly without creating intermediate objects. One `ActiveQuery` object handles everything.

### Nested Eager Loading

```php
// 4 queries total, regardless of record count
$users = User::find()->with('posts.comments.author')->get();
```

### Conditional Queries

```php
$users = User::find()
    ->when($search !== null, fn($q) => $q->like('name', "%$search%"))
    ->unless($isAdmin, fn($q) => $q->where('published', true))
    ->get();
```

### JSON Column Queries

```php
// Auto JSON extraction via -> notation (works in where, in, orderBy, etc.)
User::find()->where('preferences->dining->meal', 'salad')->get();
User::find()->in('preferences->dining->meal', ['pasta', 'salad'])->get();
User::find()->orderBy('meta->score', 'DESC')->get();

// JSON methods
User::find()->jsonContains('tags', 'php')->get();            // array contains value
User::find()->jsonNotContains('tags', 'java')->get();        // array excludes value
User::find()->jsonHas('meta->address')->get();               // key exists
User::find()->jsonMissing('meta->foo')->get();               // key missing
User::find()->jsonLength('tags', '>', 2)->get();             // array length

// Aliases (whereJson* style)
User::find()->whereJsonContains('tags', 'php')->get();
User::find()->whereJsonDoesntContain('tags', 'java')->get();
User::find()->whereJsonContainsKey('meta->address')->get();
User::find()->whereJsonDoesntContainKey('meta->foo')->get();
User::find()->whereJsonLength('tags', '>', 2)->get();
```

### Multi-Column Conditions

```php
// Match ANY column (OR logic)
User::find()->whereAny(['name', 'email', 'phone'], 'like', '%john%')->get();
// → WHERE (name LIKE ? OR email LIKE ? OR phone LIKE ?)

// Match ALL columns (AND logic)
Post::find()->whereAll(['title', 'body'], 'like', '%Laravel%')->get();
// → WHERE (title LIKE ? AND body LIKE ?)

// Match NONE of the columns
Post::find()->whereNone(['title', 'body'], 'like', '%spam%')->get();
// → WHERE NOT (title LIKE ? OR body LIKE ?)
```

### Transactions

```php
User::transaction(function () {
    $user = new User(['name' => 'John', 'email' => 'john@example.com']);
    $user->save();

    $post = new Post(['user_id' => $user->id, 'title' => 'First Post']);
    $post->save();

    return true; // commit
});
// Return false (or don't return true) to roll back
```

### Soft Deletes & Timestamps

```php
class User extends Model
{
    use SoftDeletes, Timestamps;
    protected string $table = 'user';
}

$user->delete();    // sets deleted_at
$user->restore();   // clears deleted_at
User::withTrashed()->get(); // includes deleted
```

### Model Events

```php
// Register event listeners
User::on('creating', function (User $user) {
    $user->slug = strtolower($user->name);
});

User::on('deleting', function (User $user) {
    if ($user->role === 'admin') return false; // cancel deletion
});

// Observer class
User::observe(new AuditObserver());
```

### Query Result Caching

```php
use Simsoft\DB\Cache\QueryCache;
use Simsoft\DB\Cache\ArrayCache;

QueryCache::setDriver(new ArrayCache());

// Cache results for 60 seconds
$users = User::find()->where('active', 1)->cache(60)->get();
```

### Read/Write Connection Splitting

```php
Connection::add('mysql', [
    'driver' => 'mysql',
    'database' => 'myapp',
    'read' => ['host' => 'replica.db.internal'],
    'write' => ['host' => 'primary.db.internal'],
]);
// SELECT auto-routes to read, INSERT/UPDATE/DELETE to write
```

### Development Tools

```php
// N+1 detection
QueryMonitor::enable();

// Query logging with timing
QueryLogger::enable();
$queries = QueryLogger::getQueries();
$slowest = QueryLogger::getSlowestQuery();

// Index advisor
IndexAdvisor::suggestSQL();
```

## Documentation

📖 **[Read the full documentation](https://sim-soft.github.io/fliq/)**

1. [Getting Started](docs/01-GETTING-STARTED.md) — Connections, drivers, raw queries, DB facade, monitoring
2. [Query Builder](docs/02-QUERY-BUILDER.md) — Fluent API, conditions, joins, JSON, aggregation, scopes, unions
3. [Active Record](docs/03-ACTIVE-RECORD.md) — Models, CRUD, casting, hooks, events, soft deletes, timestamps
4. [Relations](docs/04-RELATION.md) — hasOne, hasMany, via, viaTable, eager loading, whereHas
5. [Advanced Features](docs/05-ADVANCED-FEATURES.md) — Caching, pagination, global scopes, batch operations, read/write split
6. [Collections](docs/06-COLLECTIONS.md) — Lazy iteration, filter, map, reduce, indexBy, groupBy, batch processing
7. [Comparison](docs/07-COMPARISON.md) — Feature comparison with Eloquent, Doctrine, Yii3, Cycle, Propel
8. [Cheatsheet](docs/08-CHEATSHEET.md) — Quick reference for all common operations

## License

FLIQ is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
