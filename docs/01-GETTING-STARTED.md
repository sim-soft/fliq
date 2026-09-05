# Getting Started

## Table of Contents
- [Connection Management](#connection-management)
- [Database Drivers](#database-drivers)
- [Dropped Connections](#dropped-connections)
- [Read/Write Connection Splitting](#readwrite-connection-splitting)
- [N+1 Query Detection](#n1-query-detection)
- [Query Logging](#query-logging)
- [Raw Query](#raw-query)
- [DB Facade](#using-the-db-facade)
- [DB Facade CRUD](#db-facade-crud-operations)
- [DB Facade Transactions](#db-facade-transactions)
- [DB Facade SQL-Only Mode](#db-facade-sql-only-mode)

## Connection Management
Setup database connections.

```php
use Simsoft\DB\Connection;

// Add connections
Connection::add('mysql', ['driver' => 'mysqli', 'host' => 'localhost', ...]);
Connection::add('replica', ['driver' => 'mysqli', 'host' => 'replica-host', ...]);

// Check if a connection exists
Connection::has('mysql'); // true

/* Set the default connection */
Connection::setDefault('mysql');

// Disconnect (keeps config, closes connection)
Connection::disconnect('mysql');

// Reconnect (force fresh connection)
Connection::reconnect('mysql');

// Remove connection entirely (config + connection)
Connection::remove('replica');

/* Reset all connections (useful for testing or long-running processes) */
Connection::reset();
```

## Database Drivers

| Driver      | Config `driver` value | Extension required |
|-------------|-----------------------|--------------------|
| MySQLi      | `mysqli`              | ext-mysqli         |
| MySQL (PDO) | `pdo_mysql`           | ext-pdo            |
| PostgreSQL  | `pgsql`               | ext-pdo_pgsql      |
| SQLite      | `sqlite`              | ext-pdo_sqlite     |

> **Recommended:** Use `'driver' => 'mysqli'` for MySQL/MariaDB — it's faster
> than PDO for this use case. Use `'driver' => 'pdo_mysql'` only if you need
> PDO-specific features like statement caching.
>
> If `driver` is not specified, defaults to `mysqli`.

### MySQL / MariaDB

```php
Connection::add('mysql', [
    'driver' => 'mysqli',
    'host' => 'localhost',
    'port' => 3306,             // default: 3306
    'database' => 'mydb',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',     // default: utf8mb4
    'persistent' => false,      // default: false (reuse TCP across requests)
    'timeout' => 5,             // default: 5 (connection timeout in seconds)
    'ping_idle_seconds' => 30,  // default: 30 (see "Dropped connections" below)
    'init_command' => [         // SQL commands to run after connecting
        "SET time_zone = '+08:00'",
    ],
]);
```

Pass `MYSQLI_OPT_*` constants via `options` for driver-level tuning:

```php
Connection::add('mysql', [
    'driver' => 'mysqli',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'root',
    'password' => '',
    'options' => [
        MYSQLI_OPT_CONNECT_TIMEOUT => 10,
        MYSQLI_OPT_READ_TIMEOUT => 30,
    ],
]);
```

See [mysqli::options](https://www.php.net/manual/en/mysqli.options.php) for all
available constants.

### PostgreSQL

```php
Connection::add('pgsql', [
    'driver' => 'pgsql',
    'host' => 'localhost',
    'port' => 5432,             // default: 5432
    'database' => 'mydb',
    'username' => 'postgres',
    'password' => '',
    'charset' => 'utf8',        // default: utf8
    'schema' => 'public',       // default: public
    'persistent' => false,      // default: false
    'timeout' => 5,             // default: 5
]);
```

Pass `PDO::ATTR_*` constants via `options`:

```php
Connection::add('pgsql', [
    'driver' => 'pgsql',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'postgres',
    'password' => '',
    'options' => [
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ],
]);
```

### SQLite

```php
// File-based
Connection::add('sqlite', [
    'driver' => 'sqlite',
    'database' => __DIR__ . '/database.db',
]);

// In-memory (useful for testing)
Connection::add('sqlite', [
    'driver' => 'sqlite',
    'database' => ':memory:',
]);
```

Pass `PDO::ATTR_*` constants via `options`:

```php
Connection::add('sqlite', [
    'driver' => 'sqlite',
    'database' => __DIR__ . '/database.db',
    'options' => [
        PDO::ATTR_TIMEOUT => 10,
    ],
]);
```

### MySQL via PDO

Only use this if you need PDO-specific features (e.g., statement caching):

```php
Connection::add('mysql', [
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'mydb',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',  // default: utf8mb4_unicode_ci
    'statement_cache' => true,            // default: true
    'statement_cache_size' => 100,        // default: 100
]);
```

Pass `PDO::ATTR_*` or `PDO::MYSQL_ATTR_*` constants via `options` to override
defaults:

```php
Connection::add('mysql', [
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'root',
    'password' => '',
    'options' => [
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
        PDO::ATTR_TIMEOUT => 10,
    ],
]);
```

See [PDO::setAttribute](https://www.php.net/manual/en/pdo.setattribute.php) for
all available constants.

PDO defaults (applied automatically, user options override):

| Option                         | Default                  | Description                    |
|--------------------------------|--------------------------|--------------------------------|
| `PDO::ATTR_ERRMODE`            | `PDO::ERRMODE_EXCEPTION` | Throw exceptions on errors     |
| `PDO::ATTR_DEFAULT_FETCH_MODE` | `PDO::FETCH_ASSOC`       | Return associative arrays      |
| `PDO::ATTR_EMULATE_PREPARES`   | `false`                  | Use real prepared statements   |
| `PDO::ATTR_STRINGIFY_FETCHES`  | `false`                  | Keep native PHP types          |
| `PDO::ATTR_TIMEOUT`            | `5`                      | Connection timeout (seconds)   |
| `PDO::ATTR_PERSISTENT`         | `false`                  | Set via `'persistent' => true` |

**Statement Cache (PDO only):**

The PDO driver caches prepared statements to avoid repeated `prepare()` calls.
Enabled by default via `statement_cache` and `statement_cache_size` in the
config above.

Runtime control:

```php
$driver = Connection::get('mysql');

$driver->disableStatementCache();      // disable + clear cache
$driver->enableStatementCache();       // re-enable
$driver->clearStatementCache();        // flush without disabling
$driver->setStatementCacheSize(50);    // change max size
$driver->isStatementCacheEnabled();    // check status
```

Disable when running many unique one-off queries (migrations, bulk imports) to
avoid filling memory.

**Statement Cache** — the PDO driver caches prepared statements to avoid
repeated `prepare()` calls. Enabled by default.

```php
$driver = Connection::get('mysql');

$driver->disableStatementCache();      // disable + clear cache
$driver->enableStatementCache();       // re-enable
$driver->clearStatementCache();        // flush without disabling
$driver->setStatementCacheSize(50);    // change max size
$driver->isStatementCacheEnabled();    // check status
```

Disable caching when running many unique one-off queries (e.g., migrations, bulk
imports) to avoid filling memory.

## Dropped Connections

Database servers close idle connections — MySQL's `wait_timeout` defaults to
eight hours, and load balancers are usually far less patient. A long-running
worker or daemon will therefore find its connection gone at some point. All four
drivers recover from this automatically, in two ways:

**When a statement fails.** If a query fails with an error that names a lost
connection, the driver reconnects and runs it once more. Errors that are not
connection losses are re-thrown untouched and never retried, so a constraint
violation or a syntax error behaves exactly as it always did.

**When a connection has been idle.** Before using a connection that has sat
unused for `ping_idle_seconds` (default 30), the driver checks it is still alive
and reconnects if not. A connection used more recently than that is not
re-checked — the check costs a full round trip, which for small queries was the
larger part of their cost.

```php
Connection::add('mysql', [
    // ...
    'ping_idle_seconds' => 30,   // default
    'ping_idle_seconds' => 0,    // check before every query
]);
```

Lower the window if your network drops connections aggressively. Raise it if
your queries are small and frequent. Recovery does not depend on it — the
statement-level retry covers a connection that dies inside the window.

### Inside a transaction

A connection lost mid-transaction throws `ConnectionException` and is **not**
retried. Reconnecting would start a fresh transaction, so retrying the failed
statement would commit it on its own and leave the earlier statements behind — a
partial write, which is precisely what the transaction was there to prevent.

```php
try {
    $driver->transaction(function () {
        // ...
    });
} catch (ConnectionException $e) {
    // Nothing was written. Safe to retry the whole block.
}
```

The server discards an interrupted transaction, so nothing is committed and the
whole block can be retried.

### In-memory SQLite

An in-memory SQLite database exists only inside its connection. If that
connection is lost the data is gone, so rather than silently reconnecting to an
empty database, the driver throws `ConnectionException`.

## Read/Write Connection Splitting

Route SELECT queries to a read replica and writes to the primary server:

```php
Connection::add('mysql', [
    'driver' => 'mysqli',
    'database' => 'myapp',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'read' => ['host' => 'replica-1.db.internal'],
    'write' => ['host' => 'primary.db.internal'],
]);
```

**Automatic routing:**
- `SELECT` queries (`find()`, `first()`, `count()`, `get()`) → **read** connection
- `INSERT`/`UPDATE`/`DELETE` (`save()`, `delete()`, `execute()`) → **write** connection

**Partial override** — only specify what differs:

```php
Connection::add('mysql', [
    'driver' => 'mysqli',
    'host' => 'primary.db.internal',  // default for write
    'database' => 'myapp',
    'username' => 'app_user',
    'password' => 'secret',
    'read' => [
        'host' => 'replica.db.internal',
        'username' => 'readonly_user',  // can override any key
    ],
]);
```

Without `read`/`write` keys, all queries use the same connection (no behavior change).

## N+1 Query Detection

Enable `QueryMonitor` during development to detect N+1 query patterns:

```php
use Simsoft\DB\QueryMonitor;

// Enable monitoring (triggers warning after 5 identical query patterns)
QueryMonitor::enable(threshold: 5);

// Your code runs...
$users = User::find()->get();
foreach ($users as $user) {
    echo $user->posts; // N+1! Will trigger a warning after 5 iterations
}

// Check detected patterns
$patterns = QueryMonitor::getDetectedPatterns();
/* ['SELECT ... FROM `posts` WHERE `user_id` = ?' => ['count' => 50, 'origin' => 'app/Controller.php:42']] */

// Custom handler instead of trigger_error
QueryMonitor::setHandler(function (string $pattern, int $count, string $origin) {
    logger()->warning("N+1 detected: $pattern ($count queries) from $origin");
});

// Disable when done
QueryMonitor::disable();

/* Reset counters */
QueryMonitor::reset();
```
### Query Logging

```php
use Simsoft\DB\QueryLogger;

// Enable logging
QueryLogger::enable();

// Run queries...
$users = User::find()->where('status', 1)->get();
$posts = Post::find()->where('published', true)->get();

// Get all logged queries with timing
$queries = QueryLogger::getQueries();
// [
/* ['sql' => 'SELECT ...', 'binds' => [1], 'time' => 0.523],
   ['sql' => 'SELECT ...', 'binds' => [true], 'time' => 1.204], */
// ]

// Summary
echo QueryLogger::getQueryCount();  // 2
echo QueryLogger::getTotalTime();   // 1.727 (ms)

// Slowest query
$slow = QueryLogger::getSlowestQuery();
echo $slow['sql'];   // the SQL
echo $slow['time'];  // time in ms

// Custom handler (e.g., log to file)
QueryLogger::setHandler(function (string $sql, ?array $binds, float $timeMs) {
    file_put_contents('queries.log', "$timeMs ms: $sql\n", FILE_APPEND);
});

// Reset
QueryLogger::reset();
QueryLogger::disable();
```

#### Retention

The log keeps the most recent 1000 queries and discards older ones. Without a
cap, a long-running process — a queue worker, a daemon, a batch import — retains
the SQL and bind values of every query it has ever run, and eventually runs out
of memory.

```php
QueryLogger::setLimit(100);   // keep the last 100
QueryLogger::setLimit(0);     // unlimited (only for short-lived scripts)

QueryLogger::getDroppedCount();  // how many were discarded
```

Discarding entries does not distort the summary: `getQueryCount()` and
`getTotalTime()` describe every query that ran, not just the retained ones. Only
`getQueries()` and `getSlowestQuery()` are limited to what is still held.
# Raw Query
Interact with the database using raw queries.

The `->on('connection_name')` method specifies which database connection to use.
The name must match a connection registered via `Connection::add()`. If omitted
when using the `DB` facade, the default connection is used automatically.

## Find All Records

```php
use Simsoft\DB\Builder\Raw;

$users = (new Raw('SELECT * FROM users WHERE status = ?', [1]))
    ->on('mysql') // use 'mysql' connection.
    ->fetchAll();

foreach ($users as $user) {
    echo $user['first_name'];
    echo $user['last_name'];
}
```

## Find First Record

```php
use Simsoft\DB\Builder\Raw;

$results = (new Raw('SELECT * FROM users WHERE status = ? LIMIT 1', [1]))
    ->on('mysql')
    ->fetchAll();

$user = $results[0] ?? null;

if ($user) {
    echo $user['first_name'];
    echo $user['last_name'];
}
```

## Manipulate Records

```php
use Simsoft\DB\Builder\Raw;

/* Insert */
$status = (new Raw('INSERT INTO users (name, status) VALUES (?, ?)', ['John', 1]))
    ->on('mysql')
    ->execute();

/* Update */
$status = (new Raw('UPDATE users SET name = ? WHERE id = ?', ['john doe', 1]))
    ->on('mysql')
    ->execute();

/* Delete */
$status = (new Raw('DELETE FROM users WHERE id = ?', [1]))
    ->on('mysql')
    ->execute();
```

## Using the DB Facade

The `DB` class provides a simpler interface for raw queries. It uses the default
connection (`Connection::getDefaultName()`) automatically. Pass a connection
name as the last parameter to target a specific connection.

```php
use Simsoft\DB\DB;

// Execute raw SQL (uses default connection)
DB::raw('INSERT INTO users (name, status) VALUES (?, ?)', ['John', 1]);

/* SELECT query (uses default connection) */
$users = DB::query('SELECT * FROM users WHERE status = ?', [1]);

// Explicit connection as last parameter
DB::raw('INSERT INTO logs (msg) VALUES (?)', ['hello'], 'pgsql');
$rows = DB::query('SELECT * FROM logs', [], 'pgsql');

// Query a table using the fluent builder
$users = DB::table('users')
    ->where('status', 1)
    ->get();

// A model works too, and takes the same optional connection argument.
// Without one, the model keeps its own connection.
$users = DB::table(new User())->where('status', 1)->get();
$users = DB::table(new User(), 'reporting')->get();
```

## DB Facade CRUD Operations

```php
use Simsoft\DB\DB;
use Simsoft\DB\Builder\ActiveQuery;

/* Insert */
DB::insert('users', ['name' => 'John', 'status' => 1]);

/* Insert ignore (skip on duplicate key) */
DB::insertOrIgnore('users', ['name' => 'John', 'status' => 1]);

/* Update with condition */
DB::update('users', ['status' => 0], (new ActiveQuery())->where('id', 5));

/* Update ignore */
DB::updateIgnore('users', ['email' => 'new@test.com'], (new ActiveQuery())->where('id', 5));

/* Delete with condition */
DB::delete('users', (new ActiveQuery())->where('status', 0));

/* Delete with modifiers */
DB::deleteIgnore('users', (new ActiveQuery())->where('id', 99));
DB::deleteQuick('users', (new ActiveQuery())->where('status', 0));
```

## DB Facade Transactions

Run multiple operations atomically — if anything fails, everything rolls back:

```php
use Simsoft\DB\DB;

DB::transaction('mysql', function () {
    DB::insert('orders', ['user_id' => 1, 'total' => 99.90]);
    DB::update('users', ['balance' => 0], (new ActiveQuery())->where('id', 1));

    return true; // commit
});
```

Rules:

- Return `true` → commit
- Return `false` (or don't return `true`) → rollback
- Throw an exception → auto-rollback, exception re-thrown as `QueryException`

```php
/* Rollback example */
DB::transaction('mysql', function () {
    DB::insert('logs', ['action' => 'transfer']);

    if ($insufficientFunds) {
        return false; // rollback — nothing is saved
    }

    return true;
});
```

> **Tip:** If you're working inside a model, use
`User::transaction(fn() => ...)` instead — it automatically uses the model's
> connection.
> See [Transactions in Active Record](03-ACTIVE-RECORD.md#transactions).

The exception a callback throws is wrapped in a `QueryException`, but it is kept
as the previous exception, so the original cause is still reachable:

```php
try {
    DB::transaction('mysql', fn() => throw new RuntimeException('out of stock'));
} catch (QueryException $e) {
    $e->getMessage();          // 'out of stock'
    $e->getPrevious();         // the original RuntimeException
}
```

## DB Facade SQL-Only Mode

`DB::sqlOnly()` makes the write methods return their builder instead of
executing it — useful for inspecting or logging the SQL a call would produce:

```php
DB::sqlOnly();

$builder = DB::insert('users', ['name' => 'John']);
$builder->getSQL();      // 'INSERT INTO `users` (`name`) VALUES (?)'
$builder->getBinds();    // ['John']

DB::disableSqlOnly();    // back to executing
```

Two things to know before using it:

- **It is global and it stays on.** The flag is static, so it applies to every
  `DB::` call anywhere in the process until `DB::disableSqlOnly()` is called.
  Turn it off in a `finally` block if the code in between might throw.
- **`raw()` and `query()` ignore it.** They do not go through the builder, so
  they execute even in SQL-only mode. Only `insert()`, `insertOrIgnore()`, the
  `update*()` family, the `delete*()` family and `upsert()` are affected.
