# Introduction

Active record inspired by Yii2 ActiveRecord and Eloquent.

## Install

```shell
composer require simsoft/db-mysql
```

## Configuration

Examples setup in bootstrap or entry script file.

### Basic Setup

```php
require "vendor/autoload.php";

use Simsoft\DB\MySQL\Connection;

Connection::add('connection_name', [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'sample_db',
    'username' => 'username',
    'password' => 'password',
    'charset' => 'utf8mb4',
]);
```

### Multiple Connections Setup

index.php
```php
$config = [
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'database' => 'my_db',
        'username' => 'username',
        'password' => 'password',
        'charset' => 'utf8mb4',
    ],
    'db2' => [
        'driver' => Namespace\MyCustomDriver::class,
        'host' => '127.0.0.1',
        'database' => '/absolute/path/to/database.sqlite',
        'username' => 'username',
        'password' => 'password',
        'charset' => 'utf8mb4',
    ],
];

Connection::configure($config);
```

# Documentation

1. [Getting Started](docs/guide/01-GETTING-STARTED.md)
2. [Query Builder](docs/guide/02-QUERY-BUILDER.md)
3. [Active Record](docs/guide/03-ACTIVE-RECORD.md)
4. [Relation](docs/guide/04-RELATION.md)
