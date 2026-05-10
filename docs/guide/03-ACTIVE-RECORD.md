# Active Record

## Declare Model Class

```php
namespace Models;

use Simsoft\ActiveRecord\ActiveRecord;

class User extends ActiveRecord
{
    protected string $connection = 'mysql';
    protected string $table = 'user';
    protected string|array $primaryKey = 'id';
}
```

## Find Records

Get user by primary key.

```php
use Models\User;

// return a single user whose ID is 123
// SELECT * FROM `user` WHERE `id` = 123
$user = User::findByPk(123);

echo $user->first_name;
echo $user->last_name;
```

Get all users.

```php
// return all users
// SELECT * FROM `user` LIMIT 10
$users = User::find()->limit(10)->get();

foreach($users as $index => $user) {
    echo $index . ' ' . $user->first_name . ' ' . $user->last_name . PHP_EOL;
}
```

Get all users with filters.

```php
// return all users where conditions
$users = User::find()
            ->select('first_name', 'last_name', 'email')
            ->where('email', 'johndoe@email.com')
            ->whereNot('email', 'xyz@email.com')
            ->where('age', '>',  20)
            ->orWhere('age', '<',  30)
            ->where(function($query){
                $query
                    ->like('username', 'abc%')
                    ->orLike('username', '%efg%')
                    ->notLike('username', '%xyz');
            })
            ->orWhere(function($query){
                $query
                    ->whereNull('contact_number')
                    ->orWhereNotNull('mobile_number');
            })
            ->whereIn('country', ['MY', 'SG', 'ID'])
            ->orderBy('id', 'desc')
            ->orderBy(['gender', 'email'])
            ->orderBy([
                'first_name' => 'asc',
                'last_name' => 'desc',
            ])
            ->get();

foreach($users as $index => $user) {
    echo $index . ' ' . $user->first_name . ' ' . $user->last_name . PHP_EOL;
}
```

Get all users record by modify the result index.

```php
    $users = User::find()
            ->select('first_name', 'last_name', 'email')
            ->where('age', '>',  20)
            ->orderBy(['gender', 'email'])
            ->indexBy('id') // return all users in an array indexed by record IDs
            ->get();

foreach($users as $pk => $user) {
    echo $pk . ' ' . $user->first_name . ' ' . $user->last_name . PHP_EOL;
}
```

## CRUD

### Create new record.

```php
$values = [
    'name' => 'John',
    'email' => 'johndoe@email.com',
];

$user = new User($values);
echo $user->save() ? 'Add new user successfully' : 'Add new user failed';

// OR

$user = new User();
$user->fill($values); // Mass assignment
echo $user->save() ? 'Add new user successfully' : 'Add new user failed';

// Both implementation execute INSERT INTO user (name, email) VALUES ('John', 'johndoe@email.com')
```

### Update record

```php
$user = User::findByPk(2);
$user->name = 'Jack';
$user->email = 'jack@email.com';
$user->save() ? 'Update user successfully' : 'Update user failed';

// OR

$user->fill(['name' => 'Jack', 'email' => 'jack@email.com'])  // Mass assignment
$user->save() ? 'Update user successfully' : 'Update user failed';
```

### Delete record

```php
$user = User::findByPk(2);
$user->delete() ? 'Delete user successfully' : 'Delete user failed';
```

## Massive Assignment

```php
```

## Scope

```php
```


