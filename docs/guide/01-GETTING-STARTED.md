# Getting Started

Interact with database using raw query.

## Find All Records

Find all records where status = 1.

```php
use Simsoft\ActiveRecord\Builder\Raw;

$users = (new Raw('SELECT * FROM users WHERE status = ?', [1]))->get();

foreach($users as $user) {
    echo $user->first_name;
    echo $user->last_name;
}
```

## Find First Record

```php
use Simsoft\ActiveRecord\Builder\Raw;

$user = (new Raw('SELECT * FROM users WHERE status = ?', [1]))->first();

echo $user->first_name;
echo $user->last_name;
```

## Manipulate Record

```php
use Simsoft\ActiveRecord\Builder\Raw;

$status = (new Raw('INSERT INTO users (name, status) VALUES (?, ?)', ['John', 1]))->execute();

$status = (new Raw('UPDATE users SET name = 'john doe' WHERE id = ?', [1]))->execute();

$status = (new Raw('DELETE FROM users WHERE id = ?', [1]))->execute();
```
