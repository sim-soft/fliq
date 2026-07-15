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
CREATE TABLE user (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50) NOT NULL,
    email       VARCHAR(100) NOT NULL,
    score       INT NOT NULL DEFAULT 0,
    is_active   TINYINT NOT NULL DEFAULT 1,
    metadata    JSON DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT NULL,
    updated_at  TIMESTAMP DEFAULT NULL,
    deleted_at  TIMESTAMP DEFAULT NULL
);
```

The generator creates this file:

```php
<?php

namespace App\Models;

use Simsoft\DB\Model;
use Simsoft\DB\Traits\SoftDeletes;
use Simsoft\DB\Traits\Timestamps;

/**
 * User Model Class.
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property int $score
 * @property int $is_active
 * @property array|null $metadata
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $deleted_at
 */
class User extends Model
{
    use SoftDeletes;
    use Timestamps;

    protected string $connection = 'mysql';

    protected string $table = 'user';

    protected array $fillable = [
        'username',
        'email',
        'score',
        'is_active',
        'metadata',
    ];

    protected array $casts = [
        'score' => 'int',
        'is_active' => 'int',
        'metadata' => 'json',
    ];
}
```

### What Each Part Means

| Generated Code          | What It Does                                                              |
|-------------------------|---------------------------------------------------------------------------|
| `$connection = 'mysql'` | Which database connection this model uses                                 |
| `$table = 'user'`       | Which table this model reads/writes                                       |
| `$fillable = [...]`     | Columns allowed for mass assignment (security feature)                    |
| `$casts = [...]`        | Auto-converts column values to PHP types when you read them               |
| `@property int $score`  | Tells your IDE the type for autocomplete                                  |
| `use SoftDeletes`       | Added when `deleted_at` column exists (soft delete support)               |
| `use Timestamps`        | Added when `created_at`/`updated_at` or `created`/`updated` columns exist |
| `$primaryKey`           | Only shown if the primary key is NOT `id` (e.g., `uuid`)                  |

### Type Casting Rules

The generator looks at your column types and sets up automatic casting:

| Column Type in Database                 | PHP Cast    | What You Get in Code                       |
|-----------------------------------------|-------------|--------------------------------------------|
| `INT`, `INTEGER`, `BIGINT`, `SERIAL`    | `'int'`     | `$model->score` returns `int`              |
| `BOOLEAN`, `BOOL`                       | `'bool'`    | `$model->is_active` returns `true`/`false` |
| `FLOAT`, `DOUBLE`, `DECIMAL`, `NUMERIC` | `'float'`   | `$model->price` returns `float`            |
| `JSON`, `JSONB`                         | `'json'`    | `$model->metadata` returns `array`         |
| `VARCHAR`, `TEXT`, `DATE`, `TIMESTAMP`  | _(no cast)_ | `$model->name` returns `string`            |

### What Gets Excluded from Fillable

These columns are never added to `$fillable` (they're managed automatically):

- Primary key column (e.g., `id`)
- `created_at`, `updated_at` (managed by Timestamps trait)
- `created`, `updated` (alternative naming, also managed by Timestamps)
- `deleted_at` (managed by SoftDeletes trait)

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

| Option                | Default                        | What It Does                                     |
|-----------------------|--------------------------------|--------------------------------------------------|
| `<ClassName>`         | _(required unless --all)_      | The model class name, e.g. `User`, `BlogPost`    |
| `--all`               | off                            | Generate models for every table in the database  |
| `--table=<name>`      | auto (snake_case of ClassName) | Specify the exact table name to read             |
| `--connection=<name>` | first in config                | Which database connection to use                 |
| `--namespace=<ns>`    | `App\Models`                   | PHP namespace for the generated class            |
| `--output=<dir>`      | `app/Models`                   | Directory where the file is created              |
| `--config=<file>`     | _(none)_                       | Path to your database config file                |
| `--force`             | off                            | Overwrite the file if it already exists          |
| `--preview`           | off                            | Print the code to screen without creating a file |
| `--help`              | —                              | Show help text                                   |
