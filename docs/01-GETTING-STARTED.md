# Getting Started

## Table of Contents
- [Connection Management](#connection-management)
- [Persistent Connections](#persistent-connections)
- [Statement Cache](#statement-cache)
- [Read/Write Connection Splitting](#readwrite-connection-splitting)
- [Database Drivers](#database-drivers)
- [N+1 Query Detection](#n1-query-detection)
- [Query Logging](#query-logging)
- [Raw Query](#raw-query)
- [DB Facade](#using-the-db-facade)
- [DB Facade CRUD](#db-facade-crud-operations)

## Connection Management
Setup database connections.

```php
use Simsoft\DB\Connection;

// Add connections
Connection::add('mysql', ['driver' => 'mysql', 'host' => 'localhost', ...]);
Connection::add('replica', ['driver' => 'mysql', 'host' => 'replica-host', ...]);

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

### Persistent Connections

Enable persistent connections to reuse TCP connections across requests:

```php
Connection::add('mysql', [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'charset' => 'utf8mb4',
    'persistent' => true,  // reuses TCP connection
    'timeout' => 5,        // connection timeout in seconds
]);
```

### Statement Cache

The PDO driver caches prepared statements to avoid repeated `prepare()` calls for identical SQL. Enabled by default.

**Configure via connection:**

```php
Connection::add('mysql', [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'statement_cache' => true,       // enable/disable (default: true)
    'statement_cache_size' => 200,   // max cached statements (default: 100)
]);
```

**Control at runtime:**

```php
use Simsoft\DB\Connection;

$driver = Connection::get('mysql');

// Disable caching (clears existing cache)
$driver->disableStatementCache();

// Re-enable
$driver->enableStatementCache();

// Flush cache without disabling
$driver->clearStatementCache();

// Change max size
$driver->setStatementCacheSize(50);

// Check status
$driver->isStatementCacheEnabled(); // true/false
```

Disable caching when running many unique one-off queries (e.g., migrations, bulk imports with varying column counts) to avoid filling memory with unused statements.

### Read/Write Connection Splitting

Route SELECT queries to a read replica and writes to the primary server:

```php
Connection::add('mysql', [
    'driver' => 'mysql',
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
    'driver' => 'mysql',
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

## Database Drivers

| Driver | Config `driver` value | Extension required |
|--------|----------------------|-------------------|
| MySQL (PDO) | `mysql` | ext-pdo |
| MySQLi | `mysqli` | ext-mysqli |
| PostgreSQL | `pgsql` | ext-pdo_pgsql |
| SQLite | `sqlite` | ext-pdo_sqlite |

```php
// MySQL
Connection::add('mysql', [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'charset' => 'utf8mb4',
]);

// PostgreSQL
Connection::add('pgsql', [
    'driver' => 'pgsql',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
]);

// SQLite
Connection::add('sqlite', [
    'driver' => 'sqlite',
    'database' => __DIR__ . '/database.db', // or ':memory:'
]);
```

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
