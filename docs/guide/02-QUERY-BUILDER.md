# Query Builder

## Basic Usage

Get all records from `user` table

```php
use Simsoft\ActiveRecord\Builder\Query;

$query = new Query();

// SELECT t.* FROM user t
$users = $query->from('user')->get();

foreach($users as $user) {
    echo $user->first_name , ' ' . $user->last_name . PHP_EOL;
}
```

Get first record `user` table

```php
use Simsoft\ActiveRecord\Builder\Query;

$query = new Query();

// SELECT t.* FROM user t
$user = $users = $query->from('user')->first();

echo $user->first_name;
echo $user->last_name;
```

## SELECT Clauses

Basic select

```php
use Simsoft\ActiveRecord\Builder\Query;

$query = new Query();

// SELECT t.first_name, t.last_name FROM user t
$users = $query->from('user')->select('first_name', 'last_name')->get();
```

Select with raw expression.

```php
// SELECT t.first_name, t.last_name, COUNT(*) AS count, SUM(t.age) AS sum FROM user t
$users = $query->from('user')
            ->select('first_name', 'last_name', new Raw('COUNT(*) AS count, SUM({age}) AS sum'))
            ->get()
```

Declare alias for `user` table.

```php
// SELECT u.first_name, u.last_name FROM user u
$users = $query->from('user', 'u')->select('first_name', 'last_name')->get();
```

## Where Clauses

Methods: **where(), orWhere(), whereNot(), orWhereNot();

```php
// SELECT t.first_name, t.last_name, t.email FROM user t WHERE t.age > 25
// AND t.status = 1 LIMIT 800
// default limit is 800
$users = (new Query())
    ->from('user')
    ->select('first_name', 'last_name', 'email')
    ->where('age', '>', 25)
    ->where('status', 1)
    ->get();

foreach($users as $user) {
    echo $user->first_name;
    echo $user->last_name;
}

// SELECT t.* FROM user t WHERE t.status = 1 AND t.gender != 'male' AND t.height >= 150
// AND t.weight < 70 AND t.salary >= 3000 AND (t.age > 18 OR t.age <= 25) LIMIT 800
$users = (new Query())
    ->from('user')
    ->where('status', 1)
    ->whereNot('gender', 'male')
    ->where([                                   // array conditions
        ['height', '>=', 150],
        ['weight', '<', 70],
    ])
    ->where(new Raw('t.salary >= ?', [3000]))   // Raw query
    ->where(function($query){                       // group conditions
        $query->where('age', '>', 18)
            ->orWhere('age', '<=', 25);
    })
    ->get();
```

Methods: whereNull(), orWhereNotNull().

```php
// SELECT t.* FROM user t WHERE t.last_name IS NULL OR t.email IS NOT NULL LIMIT 800
$users = (new Query())
    ->from('user')
    ->whereNull('name')
    ->orWhereNull('name')
    ->whereNotNull('name')
    ->orWhereNotNull('name')
    ->get();
```

## In Clauses

Methods: whereIn(), whereNotIn(), orWhereIn(), orWhereNotIn().

```php
// SELECT t.* FROM user t WHERE t.role IN (1,2,3) AND t.status NOT IN (1,2,3,4) LIMIT 800;
$users = (new Query())
    ->from('order')
    ->whereIn('invoice_id', [1, 2, 3])
    ->orWhereIn('invoice_id', [8, 9, 10])
    ->whereNotIn('invoice_id', [4, 5, 6, 7])
    ->orWhereNotIn('invoice_id', [11, 12, 13, 14])
    ->get();
```

## Between Clauses

Methods: between(), notBetween(), orBetween(), orNotBetween().

```php
// SELECT t.* FROM user t WHERE t.height BETWEEN 150 AND 200
// OR t.birth_day NOT BETWEEN '1990-01-01' AND '1990-01-31' LIMIT 800;
$users = (new Query())
    ->from('user')
    ->between('created', '2018-01-01', '2018-12-31')
    ->orBetween('created', '2018-01-01', '2018-12-31')
    ->notBetween('created', '2018-01-01', '2018-12-31')
    ->orNotBetween('created', '2018-01-01', '2018-12-31')
    ->get();
```

## Like Clauses

Methods: like(), notLike(), orLike(), orNotLike().

```php
// SELECT t.* FROM user t WHERE t.name LIKE '%john%' AND (t.name NOT LIKE '%Jane%' OR t.name NOT LIKE '%Simon%')
(new Query())
    ->from('user')
    ->like('name', '%john%')
    ->where(function($query){
        $query->notLike('name', '%Jane%')
          ->orNotLike('name', '%Simon%');
    })
    ->get();
```

## Ordering, Grouping, Limit & Offset Clauses

```php
// SELECT first_name, last_name, email FROM users WHERE id = 1
(new Select('users'))
    ->select('first_name', 'last_name', new Raw('COUNT(*) AS count, SUM(age) AS sum'))

    ->orderBy('id', 'desc')
    ->orderBy([
        'id' => 'desc',
        'name' => 'asc',
    ])
    ->groupBy('id', 'name')
    ->having('salary', '>', 3000)
    ->having(new Raw('SUM(salary) > ?', [2000]))
    ->limit(20, 30)
    ->page(1)
    ->get();
```

## Joins Clauses

## Aggregation Clauses
