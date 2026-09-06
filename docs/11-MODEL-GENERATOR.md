# Model Generator

FLIQ includes a command-line tool that reads your database tables and
automatically creates Model PHP files for you. No need to write boilerplate by
hand.

## Table of Contents

- [Quick Start](#quick-start)
- [Step 1: Create a Config File](#step-1-create-a-config-file)
- [Step 2: Generate a Single Model](#step-2-generate-a-single-model)
- [Step 3: Generate All Models at Once](#step-3-generate-all-models-at-once)
- [What the Generator Creates](#what-the-generator-creates)
- [Using It in PHP Code](#using-it-in-php-code)
- [All Options](#all-options)
- [New Features](#new-features)

---

## Quick Start

If you already have a database with tables, you can generate all your Model
files in one command:

```bash
vendor/bin/fliq make:model --all --config=config/db.php
```

That's it. One command creates a Model file for every table in your database.

---

## Step 1: Create a Config File

Before generating models, you need a config file that tells FLIQ how to connect
to your database. Create a file called `config/db.php` (or any name you like):

```php
<?php

/*
 * This file tells FLIQ how to connect to your database.
 *
 * It returns an array of named connections.
 * The key (like 'mysql' or 'pgsql') is the connection name.
 * The value is an array of connection settings.
 */
return [
    'mysql' => [
        'driver'   => 'mysqli',     /* Which driver to use (see table below) */
        'host'     => '127.0.0.1',  /* Your database server address */
        'port'     => 3306,         /* Port number (3306 is default for MySQL) */
        'database' => 'my_app',     /* Your database name */
        'username' => 'root',       /* Database login username */
        'password' => '',           /* Database login password */
        'charset'  => 'utf8mb4',    /* Character encoding */
    ],
];
```

### Available Drivers

| Database        | Driver value | PHP extension needed |
|-----------------|--------------|----------------------|
| MySQL / MariaDB | `mysqli`     | ext-mysqli           |
| MySQL (PDO)     | `pdo_mysql`  | ext-pdo              |
| PostgreSQL      | `pgsql`      | ext-pdo_pgsql        |
| SQLite          | `sqlite`     | ext-pdo_sqlite       |

### PostgreSQL Config Example

```php
return [
    'pgsql' => [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'port'     => 5432,          /* Default PostgreSQL port */
        'database' => 'my_app',
        'username' => 'postgres',
        'password' => '',
        'charset'  => 'utf8',
        'schema'   => 'public',      /* PostgreSQL schema name */
    ],
];
```

### Multiple Connections

You can list multiple databases in one config file:

```php
return [
    'mysql' => [
        'driver'   => 'mysqli',
        'host'     => '127.0.0.1',
        'database' => 'main_db',
        'username' => 'root',
        'password' => '',
    ],
    'pgsql' => [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'database' => 'analytics_db',
        'username' => 'postgres',
        'password' => '',
    ],
];
```

Then use `--connection=pgsql` to pick which one to use.

---

## Step 2: Generate a Single Model

Once your config file is ready, generate a model:

```bash
vendor/bin/fliq make:model User --config=config/db.php
```

This will:

1. Connect to your database using the config
2. Read the `user` table structure (columns, types, primary key)
3. Create the file `app/Models/User.php`

### How the Table Name is Determined

The generator converts your class name from PascalCase to snake_case:

| Class Name      | Table Looked Up   |
|-----------------|-------------------|
| `User`          | `user`            |
| `UserProfile`   | `user_profile`    |
| `OrderLineItem` | `order_line_item` |
| `BlogPost`      | `blog_post`       |

If your table name doesn't follow this pattern, specify it manually:

```bash
vendor/bin/fliq make:model BlogPost --table=posts --config=config/db.php
```

### Using a Custom Class Name

You can name your model class anything you want — it doesn't have to match the
table name. Provide the class name you want and use `--table` to point to the
actual table:

```bash
/* Table is "user_profile", but you want the class called "Profile" */
vendor/bin/fliq make:model Profile --table=user_profile --config=config/db.php
```

This creates `app/Models/Profile.php`:

```php
class Profile extends Model
{
    /** @var string Database connection name. */
    protected string $connection = 'mysql';

    /** @var string Database table name. */
    protected string $table = 'user_profile';

    /* ... */
}
```

More examples:

```bash
/* Table "tbl_orders" → class "Order" */
vendor/bin/fliq make:model Order --table=tbl_orders --config=config/db.php

/* Table "wp_users" → class "User" */
vendor/bin/fliq make:model User --table=wp_users --config=config/db.php

/* Table "user" → class "Account" */
vendor/bin/fliq make:model Account --table=user --config=config/db.php
```

The `--table` option tells the generator which table to read from the database.
The class name you provide becomes the filename and PHP class name.

### Preview Before Writing

Want to see what will be generated without creating a file? Use `--preview`:

```bash
vendor/bin/fliq make:model User --config=config/db.php --preview
```

This prints the PHP code to your terminal. Nothing is written to disk.

### Using a Different Connection

If your config has multiple connections, specify which one:

```bash
vendor/bin/fliq make:model Order --connection=pgsql --config=config/db.php
```

### Custom Output Location

By default, files are created in `app/Models/`. Change it with `--output` and
`--namespace`:

```bash
vendor/bin/fliq make:model Invoice \
    --namespace=App\\Billing\\Models \
    --output=app/Billing/Models \
    --config=config/db.php
```

### Overwriting an Existing File

If the file already exists, the generator skips it:

```
Skipped: app/Models/User.php already exists. Use --force to overwrite.
```

To regenerate (overwrite), add `--force`:

```bash
vendor/bin/fliq make:model User --config=config/db.php --force
```

---

## Step 3: Generate All Models at Once

Instead of running the command for each table, generate models for every table
in one go:

```bash
vendor/bin/fliq make:model --all --config=config/db.php
```

Output:

```
Created: app/Models/Department.php
Created: app/Models/User.php
Created: app/Models/UserProfile.php
Created: app/Models/Post.php
Created: app/Models/Comment.php
Created: app/Models/Tag.php
Created: app/Models/Order.php
Created: app/Models/OrderItem.php
Created: app/Models/Setting.php
Created: app/Models/Task.php

Done. 10 created, 0 skipped (10 tables).
```

### Generate All from PostgreSQL

```bash
vendor/bin/fliq make:model --all --config=config/db.php --connection=pgsql
```

### Force Regenerate Everything

```bash
vendor/bin/fliq make:model --all --config=config/db.php --force
```

---

## What the Generator Creates

Given this MySQL table:

```sql
CREATE TABLE post (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    body        TEXT DEFAULT NULL,
    status      ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    view_count  INT NOT NULL DEFAULT 0,
    metadata    JSON DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT NULL,
    updated_at  TIMESTAMP DEFAULT NULL,
    deleted_at  TIMESTAMP DEFAULT NULL
);
```

Running:

```bash
vendor/bin/fliq make:model Post --config=config/db.php
```

The generator creates `app/Models/Post.php`:

```php
<?php

namespace App\Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;
use Simsoft\DB\Traits\SoftDeletes;
use Simsoft\DB\Traits\Timestamps;

/**
 * Post Model Class.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $body
 * @property string $status
 * @property int $view_count
 * @property array|null $metadata
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $deleted_at
 */
class Post extends Model
{
    use SoftDeletes;
    use Timestamps;

    /** @var string Database connection name. */
    protected string $connection = 'mysql';

    /** @var string Database table name. */
    protected string $table = 'post';

    /** @var array<int, string> Mass-assignable attributes. */
    protected array $fillable = [
        'user_id',
        'title',
        'body',
        'status',
        'view_count',
        'metadata',
    ];

    /** @var array<int, string> Attributes excluded from mass assignment. */
    protected array $guarded = ['id'];

    /** @var array<string, string> Attribute type casts. */
    protected array $casts = [
        'user_id' => 'int',
        'view_count' => 'int',
        'metadata' => 'json',
    ];

    /*
     * Enum/check constraint values:
     *   status: draft,published,archived
     */

    /**
     * Get related user.
     *
     * @return Relation
     */
    public function user(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
```

### What Each Part Means

| Generated Code           | What It Does                                                              |
|--------------------------|---------------------------------------------------------------------------|
| `$connection = 'mysql'`  | Which database connection this model uses                                 |
| `$table = 'post'`        | Which table this model reads/writes                                       |
| `$fillable = [...]`      | Columns allowed for mass assignment (security feature)                    |
| `$guarded = ['id']`      | Columns that can NEVER be mass-assigned (the primary key)                 |
| `$casts = [...]`         | Auto-converts column values to PHP types when you read them               |
| `@property int $user_id` | Tells your IDE the type for autocomplete                                  |
| `use SoftDeletes`        | Added when `deleted_at` column exists (soft delete support)               |
| `use Timestamps`         | Added when `created_at`/`updated_at` or `created`/`updated` columns exist |
| `$primaryKey`            | Only shown if the primary key is NOT `id` (e.g., `uuid`)                  |
| `user(): Relation`       | Auto-detected from `user_id` column — creates a relation stub             |
| Enum comment             | Shows valid values for ENUM columns (MySQL)                               |

### Type Casting Rules

The generator looks at your column types and sets up automatic casting:

| Column Type in Database                 | PHP Cast    | What You Get in Code                       |
|-----------------------------------------|-------------|--------------------------------------------|
| `INT`, `INTEGER`, `BIGINT`, `SERIAL`    | `'int'`     | `$model->score` returns `int`              |
| `BOOLEAN`, `BOOL`                       | `'bool'`    | `$model->is_active` returns `true`/`false` |
| `FLOAT`, `DOUBLE`, `DECIMAL`, `NUMERIC` | `'float'`   | `$model->price` returns `float`            |
| `JSON`, `JSONB`                         | `'json'`    | `$model->metadata` returns `array`         |
| `VARCHAR`, `TEXT`, `DATE`, `TIMESTAMP`  | _(no cast)_ | `$model->name` returns `string`            |

A cast never applies to `NULL`, so a nullable column still returns `null` rather
than `0`, `false` or `[]`. See
[Attribute Casting](03-ACTIVE-RECORD.md#attribute-casting).

### What Gets Excluded from Fillable

These columns are never added to `$fillable` (they're managed automatically):

- Primary key column (e.g., `id`)
- `created_at`, `updated_at` (managed by Timestamps trait)
- `created`, `updated` (alternative naming, also managed by Timestamps)
- `deleted_at` (managed by SoftDeletes trait)

### What Gets Auto-Detected

| Pattern in Your Table                       | What the Generator Does                        |
|---------------------------------------------|------------------------------------------------|
| Column named `id` as primary key            | Omits `$primaryKey` (it's the default)         |
| Non-`id` primary key (e.g., `uuid`)         | Adds `$primaryKey = 'uuid'`                    |
| Multiple primary keys (composite)           | Adds `$primaryKey = ['col1', 'col2']`          |
| `deleted_at` column exists                  | Adds `use SoftDeletes;` trait                  |
| `created_at` + `updated_at` exist           | Adds `use Timestamps;` trait                   |
| `created` + `updated` exist                 | Adds `use Timestamps;` trait                   |
| Column ending with `_id` (e.g., `user_id`)  | Generates a `hasOne` relation method           |
| MySQL ENUM type (e.g., `ENUM('a','b','c')`) | Adds a comment listing the valid values        |
| Always                                      | Adds `$guarded` with the primary key column(s) |

---

## Using It in PHP Code

You can also use the generator programmatically (in scripts, migration tools,
etc.):

### Generate a Single Model

```php
use Simsoft\DB\Connection;
use Simsoft\DB\Generator\ModelGenerator;

/* Set up a connection first */
Connection::add('mysql', [
    'driver' => 'mysqli',
    'host' => '127.0.0.1',
    'database' => 'my_app',
    'username' => 'root',
    'password' => '',
]);

/* Generate the model file */
$path = ModelGenerator::fromTable('user')
    ->namespace('App\\Models')
    ->outputDir('app/Models')
    ->generate();

if ($path === false) {
    echo "Skipped (file already exists)\n";
} else {
    echo "Created: $path\n";
}
```

### Generate All Models

```php
use Simsoft\DB\Generator\ModelGenerator;

$result = ModelGenerator::generateAll(
    connectionName: 'mysql',
    namespace: 'App\\Models',
    outputDir: 'app/Models',
    force: false
);

echo count($result['created']) . " models created\n";
echo count($result['skipped']) . " tables skipped (files exist)\n";
```

### List All Tables

```php
use Simsoft\DB\Generator\ModelGenerator;

$tables = ModelGenerator::listTables('mysql');
/* Returns: ['department', 'user', 'user_profile', 'post', ...] */
```

### Preview Without Writing

```php
$code = ModelGenerator::fromTable('user')
    ->namespace('App\\Models')
    ->preview();

echo $code; /* Prints the PHP class code */
```

### Force Overwrite

```php
ModelGenerator::fromTable('user')
    ->namespace('App\\Models')
    ->outputDir('app/Models')
    ->force()
    ->generate();
```

---

## All Options

| Option                 | Default                        | What It Does                                                  |
|------------------------|--------------------------------|---------------------------------------------------------------|
| `<ClassName>`          | _(required unless --all)_      | The model class name, e.g. `User`, `BlogPost`                 |
| `--all`                | off                            | Generate models for every table in the database               |
| `--table=<name>`       | auto (snake_case of ClassName) | Specify the exact table name to read                          |
| `--connection=<name>`  | first in config                | Which database connection to use                              |
| `--namespace=<ns>`     | `App\Models`                   | PHP namespace for the generated class                         |
| `--output=<dir>`       | `app/Models`                   | Directory where the file is created                           |
| `--config=<file>`      | auto-discovered                | Path to your database config file                             |
| `--exclude=<list>`     | _(none)_                       | Comma-separated tables to skip (with --all)                   |
| `--with-observer`      | off                            | Also generate an Observer for the model(s)                    |
| `--observer-namespace` | `App\Observers`                | Observer namespace (used with --with-observer)                |
| `--observer-output`    | `app/Observers`                | Observer output directory (used with --with-observer)         |
| `--events=<list>`      | all 8 events                   | Observer events to include (used with --with-observer)        |
| `--dry-run`            | off                            | Show what would be generated without writing                  |
| `--force`              | off                            | Overwrite the file if it already exists                       |
| `--preview`            | off                            | Print the code to screen without creating a file              |
| `--verbose`, `-v`      | off                            | Show detailed output (table → class mapping, connection info) |
| `--help`               | —                              | Show help text                                                |

---

## New Features

### Generate Model + Observer Together

Instead of running two separate commands, use `--with-observer` to create both
at once:

```bash
vendor/bin/fliq make:model User --config=config/db.php --with-observer
```

Output:

```
Created: app/Models/User.php
Created: app/Observers/UserObserver.php
```

This works with `--all` too — generate models AND observers for every table:

```bash
vendor/bin/fliq make:model --all --config=config/db.php --with-observer
```

Output:

```
Created: app/Models/User.php
Created: app/Models/Post.php
Created: app/Models/Order.php

3 created, 0 skipped (3 tables).

Created: app/Observers/UserObserver.php
Created: app/Observers/PostObserver.php
Created: app/Observers/OrderObserver.php
3 observers created.
```

#### With specific observer events

Only generate certain event methods in the observer:

```bash
vendor/bin/fliq make:model User --with-observer --events=creating,saving,deleting
```

#### Custom observer location

```bash
vendor/bin/fliq make:model User --with-observer \
    --observer-namespace=App\\Listeners \
    --observer-output=app/Listeners
```

### Automatic Config Discovery

You don't always need `--config`. The CLI checks these locations automatically:

1. `config/db.php`
2. `config/database.php`
3. `tests/config/db.php`

If found, it loads connections without you specifying `--config`.

```bash
/* No --config needed if config/db.php exists */
vendor/bin/fliq make:model User
```

### Exclude Tables

Skip certain tables when using `--all` (e.g., migration tracking, sessions):

```bash
vendor/bin/fliq make:model --all --exclude=migrations,sessions,cache
```

Programmatically:

```php
use Simsoft\DB\Generator\ModelGenerator;

ModelGenerator::exclude(['migrations', 'sessions', 'cache']);
$result = ModelGenerator::generateAll();
```

### Dry Run

See what would be generated without writing any files:

```bash
vendor/bin/fliq make:model --all --dry-run
```

Output:

```
[dry-run] Would generate models for 10 tables:
  app/Models/Department.php ← department
  app/Models/User.php ← user
  app/Models/UserProfile.php ← user_profile
  app/Models/Post.php ← post
  ...
```

### Verbose Mode

Add `-v` or `--verbose` for detailed output:

```bash
vendor/bin/fliq make:model User -v
```

Output:

```
Table: user → Class: User
Connection: mysql
Created: app/Models/User.php
```

### Colored Output

The CLI uses colors when your terminal supports them:

- Green — a file created successfully
- Yellow — a file skipped (already exists)
- Red — errors
- Cyan — informational messages

### Composite Primary Keys

Tables with multi-column primary keys are detected automatically:

```sql
CREATE TABLE post_tag (
    post_id INT NOT NULL,
    tag_id  INT NOT NULL,
    PRIMARY KEY (post_id, tag_id)
);
```

Generates:

```php
class PostTag extends Model
{
    /** @var string|array Composite primary key columns. */
    protected string|array $primaryKey = ['post_id', 'tag_id'];

    /** @var array<int, string> Attributes excluded from mass assignment. */
    protected array $guarded = [
        'post_id',
        'tag_id',
    ];
}
```

### Guarded Property

Every generated model includes `$guarded` to protect the primary key from mass
assignment:

```php
/** @var array<int, string> Attributes excluded from mass assignment. */
protected array $guarded = ['id'];
```

### Relation Stubs

Columns ending with `_id` are detected as foreign keys and generate `hasOne`
relation stubs:

```php
/* Table: post (has user_id, category_id columns) */
class Post extends Model
{
    /* ... fillable, casts ... */

    /**
     * Get related user.
     *
     * @return Relation
     */
    public function user(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Get a related category.
     *
     * @return Relation
     */
    public function category(): Relation
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }
}
```

### Enum Column Comments

MySQL ENUM columns get their valid values listed as inline comments:

```php
protected array $casts = [
    'status_code' => 'int',
    'role' => 'string', /* admin,editor,member */
];
```

Or as a block comment for non-cast enum columns:

```php
/*
 * Enum/check constraint values:
 *   role: admin,editor,member
 *   priority: low,medium, high
 */
```

