# Query Builder

## Table of Contents
- [Basic Usage](#basic-usage)
- [Table Aliases](#table-aliases)
- [SELECT Clauses](#select-clauses)
- [WHERE Clauses](#where-clauses)
- [Null Conditions](#null-conditions)
- [In Clauses](#in-clauses)
- [Between Clauses](#between-clauses)
- [Exists Clauses](#exists-clauses)
- [Regex / Contains](#regex--contains)
- [Full-Text Search (MATCH AGAINST)](#full-text-search-match-against)
- [Merge Queries](#merge-queries)
- [Union Queries](#union-queries)
- [JSON Column Queries](#json-column-queries)
- [Array Columns (PostgreSQL)](#array-columns-postgresql)
- [CASE WHEN Expressions](#case-when-expressions)
- [Multi-Column Conditions](#multi-column-conditions)
- [Like Clauses](#like-clauses)
- [Ordering, Grouping, Limit & Offset](#ordering-grouping-limit--offset)
- [Join Clauses](#join-clauses)
- [Aggregation](#aggregation)
- [Sub-queries](#sub-queries)
- [Collections](#collections)
- [Raw Expressions](#raw-expressions)
- [Security: how your values are protected](#security-how-your-values-are-protected)
- [Date Filters](#date-filters)
- [Scopes](#scopes)
- [Conditional Clauses](#conditional-clauses)
- [Debugging (explain, dump, dd)](#explain--query-execution-plan)

The query builder provides a fluent interface for building SQL queries without writing raw SQL.

## Basic Usage

```php
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/* SELECT `user`.* FROM `user` */
$users = (new ActiveQuery())
    ->from('user')
    ->on('mysql')
    ->get();

foreach ($users as $user) {
    echo $user['first_name'] . ' ' . $user['last_name'] . PHP_EOL;
}
```

Get the first record:

```php
/* SELECT `user`.* FROM `user` LIMIT 1 */
$user = (new ActiveQuery())
    ->from('user')
    ->on('mysql')
    ->first();

echo $user['first_name'];
echo $user['last_name'];
```

## Table Aliases

Use a space-separated string to declare a table alias:

```php
/* SELECT `u`.* FROM `user` `u` */
$users = (new ActiveQuery())
    ->from('user u')
    ->on('mysql')
    ->get();
```

## SELECT Clauses

Basic select:

```php
/* SELECT `user`.`first_name`, `user`.`last_name` FROM `user` */
$users = (new ActiveQuery())
    ->from('user')
    ->select('first_name', 'last_name')
    ->on('mysql')
    ->get();
```

Select with a raw expression:

```php
/* SELECT `u`.`first_name`, `u`.`last_name`, COUNT(*) AS count, SUM(`u`.`age`) AS sum FROM `user` `u` */
$users = (new ActiveQuery())
    ->from('user u')
    ->select('first_name', 'last_name', new Raw('COUNT(*) AS count, SUM({age}) AS sum'))
    ->on('mysql')
    ->get();
```

Select distinct:

```php
/* SELECT DISTINCT `user`.`first_name`, `user`.`last_name` FROM `user` */
$users = (new ActiveQuery())
    ->from('user')
    ->selectDistinct('first_name', 'last_name')
    ->on('mysql')
    ->get();
```

## Where Clauses

Methods: `where()`, `orWhere()`, `not()`, `orNot()`

Aliases: `whereNot()`, `orWhereNot()`

```php
/* SELECT `user`.`first_name`, `user`.`last_name`, `user`.`email` FROM `user`
   WHERE `user`.`age` > ? AND `user`.`status` = ? */
$users = (new ActiveQuery())
    ->from('user')
    ->select('first_name', 'last_name', 'email')
    ->where('age', '>', 25)
    ->where('status', 1)
    ->on('mysql')
    ->get();

foreach ($users as $user) {
    echo $user['first_name'];
    echo $user['last_name'];
}
```

Complex conditions with grouping:

```php
/* WHERE `t`.`status` = ? AND `t`.`gender` != ?
   AND `t`.`height` >= ? AND `t`.`weight` < ?
   AND `t`.`salary` >= ?
   AND ( `t`.`age` > ? OR `t`.`age` <= ? ) */
$users = (new ActiveQuery())
    ->from('user t')
    ->where('status', 1)
    ->not('gender', 'male')
    ->where([
        ['height', '>=', 150],
        ['weight', '<', 70],
    ])
    ->where(new Raw('{salary} >= ?', [3000]))
    ->where(function ($query) {
        $query->where('age', '>', 18)
            ->orWhere('age', '<=', 25);
    })
    ->on('mysql')
    ->get();
```

### Passing several conditions at once

`where()` accepts two array shapes. A **list of triplets**, each
`[attribute, operator, value]`, joined with `AND`:

```php
/* WHERE `user`.`score` >= ? AND `user`.`id` IN (?,?) */
$users = User::find()->where([
    ['score', '>=', 150],
    ['id', 'IN', [1, 2]],
])->get();
```

Or a **map** of `attribute => value`, where an array value means `IN`. The map
form is wrapped in parentheses, so it stays one unit next to an `OR`:

```php
/* WHERE (`user`.`role` = ? AND `user`.`id` IN (?,?)) */
$users = User::find()->where(['role' => 'admin', 'id' => [1, 2]])->get();
```

An entry whose value list is empty adds no condition, and the rest of the
group still applies — the same rule `in()` follows:

```php
/* WHERE (`user`.`role` = ?) — the empty id filter contributes nothing */
$users = User::find()->where(['role' => 'admin', 'id' => []])->get();
```

In the list form every entry must be a full triplet; a shorter one throws an
`InvalidArgumentException` naming its position. In the map form a `null` value
is bound as a value rather than becoming an `IS NULL` check — use
[`isNull()`](#null-conditions) for that.

`orWhere()` takes everything `where()` takes — both array shapes, a closure, a
`Raw`, and a clause object — and differs only in joining with `OR`:

```php
/* WHERE `user`.`id` = ? OR `user`.`score` >= ? AND `user`.`id` IN (?,?) */
$users = User::find()
    ->where('id', 10)
    ->orWhere([
        ['score', '>=', 150],
        ['id', 'IN', [1, 2]],
    ])
    ->get();
```

The list form is not wrapped, and does not need to be: SQL binds `AND` tighter
than `OR`, so the triplets already group as one unit against the `OR`.

### Which operators can I use?

The middle argument of `where()` is the **comparison operator**. Only these are
accepted:

| Kind       | Operators                                            |
|------------|------------------------------------------------------|
| Comparison | `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `<=>`         |
| Pattern    | `LIKE`, `NOT LIKE`, `ILIKE`, `NOT ILIKE`             |
| Set        | `IN`, `NOT IN`                                       |
| Null       | `IS`, `IS NOT`                                       |
| Range      | `BETWEEN`, `NOT BETWEEN`                             |
| Regex      | `REGEXP`, `NOT REGEXP`, `RLIKE`                      |

Word operators are case-insensitive — `'like'` and `'LIKE'` both work.

Anything else throws an `InvalidArgumentException`:

```php
$users = User::find()->where('age', 'BIGGER THAN', 25)->get();
// InvalidArgumentException: Invalid operator: 'BIGGER THAN'.
```

**Why the restriction?** Values are sent to the database separately from the
query (see [Security](#security-how-your-values-are-protected) below), but the
operator becomes part of the SQL text itself. If your app let a visitor choose
the operator, an unchecked value could rewrite the whole query:

```php
/* DANGEROUS if the operator came from a URL like ?op=... */
$users = User::find()->where('id', $_GET['op'], 1)->get();
```

FLIQ rejects the bad operator instead of running it. You still need to validate
the *column* name yourself if that comes from user input.

If you need an expression the list doesn't cover, use `Raw` and bind your
values with `?`:

```php
$users = User::find()->where(new Raw('{score} <=> ?', [50]))->get();
```

### Operators that take more than one value

`IN`, `NOT IN`, `BETWEEN` and `NOT BETWEEN` need a right-hand side that isn't a
single value, so pass an array:

```php
/* WHERE `user`.`id` IN (?,?,?) */
$users = User::find()->where('id', 'IN', [1, 2, 3])->get();

/* WHERE `user`.`id` BETWEEN ? AND ? */
$users = User::find()->where('id', 'BETWEEN', [2, 4])->get();
```

These are the same conditions [`in()`](#in-clauses) and
[`between()`](#between-clauses) build, and those methods read better when you
know the operator up front. The operator form is there for when the operator
itself is a variable.

A range needs exactly two bounds, and a set needs an array, a subquery or a
`Raw` expression — anything else throws an `InvalidArgumentException` naming the
attribute rather than producing SQL the server rejects. An empty set adds no
condition at all, matching `in()`.

`IS` and `IS NOT` compare against null and take no value:

```php
/* WHERE `user`.`deleted_at` IS NULL */
$users = User::find()->where('deleted_at', 'IS', null)->get();
```

`where('col', null)` and `where('col', '=', null)` mean the same thing, as do
`where('col', '!=', null)`, `where('col', '<>', null)` and
`where('col', 'IS NOT', null)`. The dedicated
[null methods](#null-conditions) are clearer if you aren't choosing the
operator dynamically.

### The two-argument shortcut

When you only pass two arguments, FLIQ assumes you meant `=`:

```php
User::find()->where('status', 'active');   /* WHERE `status` = ? */
User::find()->where('status', '=', 'active'); /* identical */
```

So `where('status', 'active')` is *not* treated as the operator `'active'` —
the shortcut is applied first. Both forms are safe.

### Joining a condition with OR

Most condition methods take a trailing logical operator saying how to join the
condition to the one before it. It defaults to `AND`:

```php
/* WHERE `user`.`role` = ? AND `user`.`deleted_at` IS NULL */
User::find()->where('role', '=', 'admin')->isNull('deleted_at');

/* WHERE `user`.`role` = ? OR `user`.`deleted_at` IS NULL */
User::find()->where('role', '=', 'admin')->isNull('deleted_at', 'OR');
```

Every such method also has an `or`-prefixed variant that reads better and means
exactly the same thing:

```php
/* WHERE `user`.`role` = ? OR `user`.`deleted_at` IS NULL */
User::find()->where('role', '=', 'admin')->orIsNull('deleted_at');
```

Only `AND` and `OR` are accepted, in any case. The operator is written into the
SQL rather than bound as a parameter, so anything else is refused rather than
passed through to the server:

```php
// InvalidArgumentException: Invalid logical operator: 'XOR'.
//                           Allowed operators: AND, OR.
User::find()->where('role', '=', 'admin')->isNull('deleted_at', 'XOR');
```

Never build this argument from request data. It selects between two fixed
keywords, so there is nothing a user needs to choose here; if a filter is
optional, use [`when()`](#conditional-clauses) or the `or` variant instead.

## Null Conditions

Methods: `isNull()`, `orIsNull()`, `notNull()`, `orNotNull()`

Aliases (Eloquent-style): `whereNull()`, `orWhereNull()`, `whereNotNull()`, `orWhereNotNull()`

```php
/* WHERE `t`.`last_name` IS NULL OR `t`.`email` IS NOT NULL */
$users = (new ActiveQuery())
    ->from('user t')
    ->isNull('last_name')
    ->orNotNull('email')
    ->on('mysql')
    ->get();

// Using Eloquent-style aliases (identical behavior)
$users = (new ActiveQuery())
    ->from('user t')
    ->whereNull('last_name')
    ->orWhereNotNull('email')
    ->on('mysql')
    ->get();
```

The `where()` method also handles null values automatically:

```php
/* where('col', null) produces: WHERE col IS NULL */
$users = (new ActiveQuery())
    ->from('user')
    ->where('deleted_at', null)
    ->on('mysql')
    ->get();

/* where('col', '!=', null) produces: WHERE col IS NOT NULL */
$users = (new ActiveQuery())
    ->from('user')
    ->where('email', '!=', null)
    ->on('mysql')
    ->get();
```

## In Clauses

Methods: `in()`, `notIn()`, `orIn()`, `orNotIn()`

Aliases: `whereIn()`, `whereNotIn()`, `orWhereIn()`, `orWhereNotIn()`

```php
/* WHERE `user`.`role` IN (?,?,?) AND `user`.`status` NOT IN (?,?,?,?) */
$users = (new ActiveQuery())
    ->from('user')
    ->in('role', [1, 2, 3])
    ->notIn('status', [1, 2, 3, 4])
    ->on('mysql')
    ->get();
```

The right-hand side can also be a subquery or a `Raw` expression:

```php
/* WHERE `user`.`id` IN (SELECT `post`.`user_id` FROM `post`) */
$authors = (new ActiveQuery())
    ->from('user')
    ->in('id', (new ActiveQuery())->from('post')->select('user_id'))
    ->on('mysql')
    ->get();
```

### Empty lists

An empty list is a set with nothing in it, not an absent condition:

```php
/* WHERE 1 = 0 — matches no rows */
$users = (new ActiveQuery())->from('user')->in('id', [])->on('mysql')->get();

/* WHERE 1 = 1 — matches every row */
$users = (new ActiveQuery())->from('user')->notIn('id', [])->on('mysql')->get();
```

This matters when the list is computed. An allow-list that filters down to
nothing narrows the query to nothing, rather than dropping the restriction and
returning the whole table:

```php
$visibleIds = $this->idsTheUserMaySee(); // may legitimately be []

// Returns nothing when the user may see nothing.
$posts = Post::find()->whereIn('id', $visibleIds)->get();
```

Note this differs from `like()`, where an empty array of patterns *is* skipped —
a search with no terms is an absent filter, whereas membership of an empty set
has a defined answer.

## Between Clauses

Methods: `between()`, `notBetween()`, `orBetween()`, `orNotBetween()`

```php
/* WHERE `t`.`height` BETWEEN ? AND ? OR `t`.`birth_day` NOT BETWEEN ? AND ? */
$users = (new ActiveQuery())
    ->from('user t')
    ->between('height', 150, 200)
    ->orNotBetween('birth_day', '1990-01-01', '1990-01-31')
    ->on('mysql')
    ->get();
```

### Between Date

Methods: `betweenDate()`, `notBetweenDate()`, `orBetweenDate()`, `orNotBetweenDate()`

Handles open-ended date ranges (null start or end):

```php
/* WHERE created_at >= ? AND created_at <= ? */
$users = (new ActiveQuery())
    ->from('user')
    ->betweenDate('created_at', '2024-01-01', '2024-12-31')
    ->on('mysql')
    ->get();

/* WHERE created_at >= ? */
$users = (new ActiveQuery())
    ->from('user')
    ->betweenDate('created_at', '2024-01-01', null)
    ->on('mysql')
    ->get();

/* WHERE created_at <= ? */
$users = (new ActiveQuery())
    ->from('user')
    ->betweenDate('created_at', null, '2024-12-31')
    ->on('mysql')
    ->get();
```

At least one of the two dates is required. Passing neither throws
`InvalidArgumentException`, since there is no range to test.

The negated forms match the exact complement — a row outside the range is
before the start *or* after the end, so they build a disjunction and wrap it in
parentheses. The parentheses matter: `AND` binds tighter than `OR`, so without
them a neighbouring condition would attach to only one half of the range.

```php
/* WHERE (created_at < ? OR created_at > ?) */
$users = (new ActiveQuery())
    ->from('user')
    ->notBetweenDate('created_at', '2024-01-01', '2024-12-31')
    ->on('mysql')
    ->get();

/* WHERE created_at < ? -- open-ended, so a single comparison */
$users = (new ActiveQuery())
    ->from('user')
    ->notBetweenDate('created_at', '2024-01-01', null)
    ->on('mysql')
    ->get();
```

### Between Date Interval

Methods: `betweenDateInterval()`, `notBetweenDateInterval()`, `orBetweenDateInterval()`

The window is half-open: it includes the start date and excludes the day the
interval lands on, so consecutive windows tile without overlapping.

```php
/* WHERE created_at >= ? AND created_at < ? + INTERVAL 7 DAY */
$users = (new ActiveQuery())
    ->from('user')
    ->betweenDateInterval('created_at', '2024-01-01', 7)
    ->on('mysql')
    ->get();

/* WHERE (created_at < ? OR created_at >= ? + INTERVAL 7 DAY) */
$users = (new ActiveQuery())
    ->from('user')
    ->notBetweenDateInterval('created_at', '2024-01-01', 7)
    ->on('mysql')
    ->get();
```

## Exists Clauses

Methods: `exists()`, `notExists()`, `orExists()`, `orNotExists()`

Each takes a sub-query and adds an `EXISTS` condition to the current query:

```php
/* WHERE EXISTS (SELECT * FROM orders WHERE orders.user_id = user.id) */
$users = (new ActiveQuery())
    ->from('user')
    ->exists(
        (new ActiveQuery())->from('orders')->whereRaw('{user_id} = {user.id}')
    )
    ->on('mysql')
    ->get();
```

To ask instead whether the query itself matches anything, use `hasRecords()`.
It fetches at most one row, so the cost does not grow with the number of
matches:

```php
if (User::find()->where('email', '=', $email)->hasRecords()) {
    throw new RuntimeException('That address is already registered.');
}
```

> **Note:** `hasRecords()` is the row-existence check. `exists()` is the SQL
> `EXISTS` sub-query condition above, and always takes a query argument.

## Regex / Contains

Methods: `regex()`, `notRegex()`, `orRegex()`, `orNotRegex()`, `containsWords()`, `orContainsWords()`

```php
/* WHERE `user`.`name` REGEXP ? */
$users = (new ActiveQuery())
    ->from('user')
    ->regex('name', '\\bJohn\\b')
    ->on('mysql')
    ->get();

/* WHERE `user`.`bio` REGEXP '[[:<:]]php[[:>:]]' AND `user`.`bio` REGEXP '[[:<:]]mysql[[:>:]]' */
$users = (new ActiveQuery())
    ->from('user')
    ->containsWords('bio', ['php', 'mysql'])
    ->on('mysql')
    ->get();
```

## Full-Text Search (MATCH AGAINST)

Requires a MySQL FULLTEXT index on the searched columns.

### Boolean Mode (default)

```php
use Simsoft\DB\Builder\Conditions\MatchAgainst;

/* WHERE MATCH(`articles`.`title`, `articles`.`body`) AGAINST ('+php +mysql -java' IN BOOLEAN MODE) */
$results = (new ActiveQuery())
    ->from('articles')
    ->where(
        (new MatchAgainst(['title', 'body']))
            ->mustHave(['php', 'mysql'])
            ->mustNot(['java'])
            ->booleanMode()
    )
    ->on('mysql')
    ->get();
```

### Natural Language Mode

Let MySQL rank results by relevance without boolean operators:

```php
/* WHERE MATCH(`posts`.`title`, `posts`.`content`) AGAINST ('database optimization' IN NATURAL LANGUAGE MODE) */
$results = (new ActiveQuery())
    ->from('posts')
    ->where(
        (new MatchAgainst(['title', 'content']))
            ->optional(['database', 'optimization'])
            ->naturalLanguageMode()
    )
    ->on('mysql')
    ->get();
```

### Wildcard Search

Match words that start with a prefix:

```php
/* WHERE MATCH(`products`.`name`) AGAINST ('micro* soft*' IN BOOLEAN MODE) */
$results = (new ActiveQuery())
    ->from('products')
    ->where(
        (new MatchAgainst(['name']))
            ->wildcard(['micro', 'soft'])
            ->booleanMode()
    )
    ->on('mysql')
    ->get();
```

### Exact Phrase Match

Search for an exact phrase:

```php
/* WHERE MATCH(`articles`.`body`) AGAINST ('"dependency injection"' IN BOOLEAN MODE) */
$results = (new ActiveQuery())
    ->from('articles')
    ->where(
        (new MatchAgainst(['body']))
            ->contains(['dependency injection'])
            ->booleanMode()
    )
    ->on('mysql')
    ->get();
```

### Combining Operators

Mix required, excluded, wildcard, and phrase in one query:

```php
/*
 * WHERE MATCH(`posts`.`title`, `posts`.`body`)
 * AGAINST ('+laravel -wordpress "service container" php*' IN BOOLEAN MODE)
 */
$results = (new ActiveQuery())
    ->from('posts')
    ->where(
        (new MatchAgainst(['title', 'body']))
            ->mustHave(['laravel'])
            ->mustNot(['wordpress'])
            ->contains(['service container'])
            ->wildcard(['php'])
            ->booleanMode()
    )
    ->on('mysql')
    ->get();
```

### MatchAgainst Methods

| Method                       | Operator   | Description                              |
|------------------------------|------------|------------------------------------------|
| `search(string $expression)` | (as-is)    | Pass your own search expression directly |
| `mustHave(array $words)`     | `+word`    | Words that must appear                   |
| `mustNot(array $words)`      | `-word`    | Words that must not appear               |
| `optional(array $words)`     | `word`     | Optional words (improve ranking)         |
| `wildcard(array $words)`     | `word*`    | Wildcard prefix match                    |
| `contains(array $phrases)`   | `"phrase"` | Exact phrase match                       |
| `negation(array $words)`     | `~word`    | Reduce ranking (not exclude)             |
| `booleanMode()`              | —          | IN BOOLEAN MODE (default)                |
| `naturalLanguageMode()`      | —          | IN NATURAL LANGUAGE MODE                 |
| `queryExpansion()`           | —          | WITH QUERY EXPANSION                     |

### Custom Search Expression

When you want full control over the boolean syntax, use `search()`:

```php
/* WHERE MATCH(`posts`.`title`, `posts`.`body`) AGAINST ('+laravel -wordpress "service container" php*' IN BOOLEAN MODE) */
$results = (new ActiveQuery())
    ->from('posts')
    ->where(
        (new MatchAgainst(['title', 'body']))
            ->search('+laravel -wordpress "service container" php*')
            ->booleanMode()
    )
    ->on('mysql')
    ->get();
```

## Merge Queries

Combine conditions from two query objects targeting the same table. Useful when
building queries dynamically from separate sources (e.g., filters from different
form sections).

Both queries **must target the same table** — otherwise the merge is silently
skipped.

### `merge()` — Combine with AND

```php
/* Build filters separately */
$statusFilter = (new ActiveQuery())->from('user')->where('status', 'active');
$ageFilter = (new ActiveQuery())->from('user')->where('age', '>', 18);

/*
 * SELECT * FROM `user`
 * WHERE `user`.`status` = ? AND `user`.`age` > ?
 */
$results = $statusFilter->merge($ageFilter)->on('mysql')->get();
```

### `orMerge()` — Combine with OR

```php
$admins = (new ActiveQuery())->from('user')->where('role', 'admin');
$verified = (new ActiveQuery())->from('user')->where('verified', 1);

/*
 * SELECT * FROM `user`
 * WHERE `user`.`role` = ? OR `user`.`verified` = ?
 */
$results = $admins->orMerge($verified)->on('mysql')->get();
```

### What gets merged

`merge()` combines: SELECT columns, WHERE conditions, HAVING clauses, GROUP BY,
ORDER BY, JOINs, and bound parameters.

### Practical example — dynamic filter builder

```php
$query = User::find()->where('status', 'active');

if ($minAge = $_GET['min_age'] ?? null) {
    $ageFilter = User::find()->where('age', '>=', (int) $minAge);
    $query->merge($ageFilter);
}

if ($role = $_GET['role'] ?? null) {
    $roleFilter = User::find()->where('role', $role);
    $query->merge($roleFilter);
}

$users = $query->get();
```

## Union Queries

Combine results from multiple SELECT queries. Only the main query (the one you
call `get()` on) needs `->on()` — sub-queries are just SQL fragments.

### `union()` — Removes duplicates

```php
/* (SELECT `user`.`name`, `user`.`email` FROM `user` WHERE `user`.`status` = ?)
   UNION
   (SELECT `user`.`name`, `user`.`email` FROM `user` WHERE `user`.`created_at` > ?) */
$activeUsers = (new ActiveQuery())
    ->from('user')
    ->select('name', 'email')
    ->where('status', 'active');

$recentUsers = (new ActiveQuery())
    ->from('user')
    ->select('name', 'email')
    ->where('created_at', '>', '2025-01-01');

$results = $activeUsers->union($recentUsers)->on('mysql')->get();
```

### `unionAll()` — Keeps duplicates

```php
/* (SELECT ...) UNION ALL (SELECT ...) */
$results = $activeUsers->unionAll($recentUsers)->on('mysql')->get();
```

### `unionDistinct()` — Explicit UNION DISTINCT

```php
/* (SELECT ...) UNION DISTINCT (SELECT ...) */
$results = $activeUsers->unionDistinct($recentUsers)->on('mysql')->get();
```

### Multiple unions

```php
$admins = (new ActiveQuery())->from('user')->select('name')->where('role', 'admin');
$editors = (new ActiveQuery())->from('user')->select('name')->where('role', 'editor');
$authors = (new ActiveQuery())->from('user')->select('name')->where('role', 'author');

/* (SELECT `user`.`name` FROM `user` WHERE `user`.`role` = ?)
   UNION
   (SELECT `user`.`name` FROM `user` WHERE `user`.`role` = ?)
   UNION ALL
   (SELECT `user`.`name` FROM `user` WHERE `user`.`role` = ?)
   Binds: ['admin', 'editor', 'author'] */
$all = $admins
    ->union($editors)
    ->unionAll($authors)
    ->on('mysql')
    ->get();
```

## JSON Column Queries

Query JSON columns with database-specific syntax (works on MySQL, PostgreSQL, and SQLite).

### Auto-Detection via `->` Notation

The `->` notation in column names auto-generates JSON extraction in `where()`, `in()`, `orderBy()`, etc.:

```php
/* WHERE JSON_UNQUOTE(JSON_EXTRACT(`preferences`, '$.theme')) = ? */
User::find()->where('preferences->theme', 'dark')->get();

/* WHERE JSON_UNQUOTE(JSON_EXTRACT(`preferences`, '$.meal')) IN (?,?) */
User::find()->in('preferences->meal', ['pasta', 'salad'])->get();

/* ORDER BY JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.score')) DESC */
User::find()->orderBy('meta->score', 'DESC')->get();

/* WHERE JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.address.city')) = ? */
User::find()->where('meta->address.city', '=', 'Kuala Lumpur')->get();
```

### Path Syntax

Use `->` to separate column from path, and `.` for nested keys:

| Expression           | MySQL path       | PostgreSQL                |
|----------------------|------------------|---------------------------|
| `meta->age`          | `$.age`          | `'age'`                   |
| `meta->address.city` | `$.address.city` | `-> 'address' ->> 'city'` |

### `jsonContains()` — Check if JSON array contains a value

```php
/* MySQL:      WHERE JSON_CONTAINS(`user`.`tags`, ?, '$')
   PostgreSQL: WHERE "user"."tags" @> ?::jsonb
   SQLite:     WHERE EXISTS (SELECT 1 FROM json_each("user"."tags", '$')
                             WHERE json_each.value = json_extract(?, '$')) */
User::find()->jsonContains('tags', 'php')->get();

// Nested path
/* MySQL:      WHERE JSON_CONTAINS(`user`.`meta`, ?, '$.skills')
   PostgreSQL: WHERE "user"."meta" -> 'skills' @> ?::jsonb */
User::find()->jsonContains('meta->skills', 'docker')->get();
```

A column written without `->` addresses the whole document rather than a key
inside it, which is what `jsonContains('tags', ...)` above relies on.

### `jsonNotContains()` — Value NOT in JSON array

```php
/* MySQL: WHERE NOT JSON_CONTAINS(`user`.`tags`, ?, '$') */
User::find()->jsonNotContains('tags', 'spam')->get();
```

### `jsonHas()` — JSON path exists

```php
/* MySQL:      WHERE JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.address')
   PostgreSQL: WHERE jsonb_exists("user"."meta", 'address')
   SQLite:     WHERE json_type("user"."meta", '$.address') IS NOT NULL */
User::find()->jsonHas('meta->address')->get();
```

### `jsonMissing()` — JSON path does not exist

```php
/* MySQL:      WHERE NOT JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.phone')
   PostgreSQL: WHERE NOT jsonb_exists("user"."meta", 'phone') */
User::find()->jsonMissing('meta->phone')->get();
```

Both methods test for a **key**, so the column must carry one:

```php
// Throws InvalidArgumentException — the document root is not a key.
User::find()->jsonHas('meta')->get();

// To test that the document itself is present, ask for the column instead.
User::find()->notNull('meta')->get();
```

### `jsonLength()` — Check JSON array length

```php
/* MySQL:      WHERE JSON_LENGTH(`user`.`meta`, '$.tags') > ?
   PostgreSQL: WHERE jsonb_array_length("user"."meta" -> 'tags') > ?
   SQLite:     WHERE json_array_length("user"."meta", '$.tags') > ? */
User::find()->jsonLength('meta->tags', '>', 3)->get();

/* MySQL: WHERE JSON_LENGTH(`user`.`meta`, '$.tags') = ? */
User::find()->jsonLength('tags', '=', 2)->get();
```

### JSON Comparison

Use `where()` with `->` notation for comparisons, or the explicit `whereJson()` method:

```php
/* WHERE JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.age')) > ? */
User::find()->where('meta->age', '>', 25)->get();
User::find()->whereJson('meta->age', '>', 25)->get();
```

### JSON OR Conditions

Each method has an `or` variant:

```php
/* WHERE JSON_CONTAINS(`user`.`tags`, ?, '$') OR JSON_CONTAINS(`user`.`tags`, ?, '$') */
User::find()
    ->jsonContains('tags', 'php')
    ->orJsonContains('tags', 'python')
    ->get();

/* WHERE JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.role')) = ?
   OR JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.role')) = ? */
User::find()
    ->where('meta->role', '=', 'admin')
    ->orWhereJson('meta->role', '=', 'editor')
    ->get();

/* WHERE JSON_LENGTH(`user`.`tags`, '$') > ? OR JSON_LENGTH(`user`.`skills`, '$') > ? */
User::find()
    ->jsonLength('tags', '>', 3)
    ->orWhereJsonLength('skills', '>', 5)
    ->get();
```

### Aliases (whereJson* style)

For users who prefer the verbose naming:

| Primary (recommended) | Alias                         |
|-----------------------|-------------------------------|
| `jsonContains()`      | `whereJsonContains()`         |
| `jsonNotContains()`   | `whereJsonDoesntContain()`    |
| `jsonHas()`           | `whereJsonContainsKey()`      |
| `jsonMissing()`       | `whereJsonDoesntContainKey()` |
| `jsonLength()`        | `whereJsonLength()`           |

### JSON with Joins

JSON methods support dot notation for referencing columns on joined tables (using either the table alias or the full table name):

```php
// JSON on the main table (auto-prefixed with table alias)
/* WHERE JSON_CONTAINS(`u`.`meta`, ?, '$.tags') */
$users = (new ActiveQuery())
    ->from('user u')
    ->join('profile p', ['user_id' => 'id'])
    ->jsonContains('meta->tags', 'php')
    ->get();

/* JSON on a joined table via alias (s.column->path)
   WHERE JSON_CONTAINS(`s`.`metadata`, ?, '$.tags') */
$users = (new ActiveQuery())
    ->from('user u')
    ->join('setting s', ['user_id' => 'id'])
    ->jsonContains('s.metadata->tags', 'core')
    ->get();

/* JSON on a joined table via full table name
   WHERE JSON_CONTAINS(`setting`.`metadata`, ?, '$.tags') */
$users = (new ActiveQuery())
    ->from('user u')
    ->join('setting s', ['user_id' => 'id'])
    ->jsonContains('setting.metadata->tags', 'core')
    ->get();

/* whereJson on aliased table
   WHERE JSON_UNQUOTE(JSON_EXTRACT(`p`.`metadata`, '$.verified')) = ? */
$users = (new ActiveQuery())
    ->from('user u')
    ->join('profile p', ['user_id' => 'id'])
    ->whereJson('p.metadata->verified', '=', true)
    ->get();

// jsonHas on aliased table
/* WHERE JSON_CONTAINS_PATH(`s`.`metadata`, 'one', '$.priority') */
$users = (new ActiveQuery())
    ->from('user u')
    ->join('setting s', ['user_id' => 'id'])
    ->jsonHas('s.metadata->priority')
    ->get();

// Multiple JSON conditions across multiple aliased tables
/* WHERE JSON_CONTAINS(`u`.`meta`, ?, '$.tags')
   AND JSON_CONTAINS(`p`.`preferences`, ?, '$.theme')
   AND JSON_CONTAINS_PATH(`s`.`metadata`, 'one', '$.priority') */
$users = (new ActiveQuery())
    ->from('user u')
    ->join('profile p', ['user_id' => 'id'])
    ->join('setting s', ['user_id' => 'id'])
    ->jsonContains('meta->tags', 'admin')        // → u.meta (main table auto-prefix)
    ->jsonContains('p.preferences->theme', 'dark')  // → p.preferences (alias)
    ->jsonHas('s.metadata->priority')               // → s.metadata (alias)
    ->get();
```

**Syntax:** `[table_or_alias.]column->json_path`
- Without prefix → main (FROM) table alias
- With `alias.` prefix → uses the alias (e.g., `s.metadata`)
- With `table_name.` prefix → uses the full table name (e.g., `setting.metadata`)
- The part after `->` is always the JSON path (`$.tags`, `$.address.city`, etc.)

## Array Columns (PostgreSQL)

PostgreSQL supports native array column types (`text[]`, `int[]`, `varchar[]`).
FLIQ provides fluent query methods for array containment and overlap checks.

On MySQL and SQLite the same calls work against a JSON array column, so a query
written once selects the same rows on all three engines.

### `arrayContains()` — Column contains a value

```php
/* PostgreSQL: WHERE "user"."tags" @> ARRAY[?]::text[]
   MySQL:      WHERE JSON_CONTAINS(`user`.`tags`, JSON_ARRAY(CAST(? AS CHAR)), '$')
   SQLite:     WHERE EXISTS (SELECT 1 FROM json_each("user"."tags") WHERE json_each.value = ?) */
User::find()->arrayContains('tags', 'php')->get();

/* Integer array column */
/* MySQL: WHERE JSON_CONTAINS(`user`.`role_ids`, JSON_ARRAY(CAST(? AS SIGNED)), '$') */
User::find()->arrayContains('role_ids', 5, 'int')->get();
```

### `arrayOverlaps()` — Column has any of the given values

```php
/* PostgreSQL: WHERE "user"."tags" && ARRAY[?, ?]::text[]
   MySQL:      WHERE JSON_OVERLAPS(`user`.`tags`, JSON_ARRAY(CAST(? AS CHAR),CAST(? AS CHAR))) */
User::find()->arrayOverlaps('tags', ['php', 'python'])->get();

/* Integer array */
User::find()->arrayOverlaps('department_ids', [1, 3, 5], 'int')->get();

/* No values overlaps nothing — the condition is 0 = 1 and binds no value */
User::find()->arrayOverlaps('tags', [])->get();
```

### The element type argument

The third argument names the type of a single element. On PostgreSQL it is the
cast in `ARRAY[?]::type[]`. On MySQL it decides how the bound value is compared:
PDO sends every value as a string, so without it the integer `5` would be
compared as `"5"` and would not match a stored `[1, 5]`.

| `$type`                              | MySQL comparison |
|--------------------------------------|------------------|
| `text`, `varchar`, anything else      | as text          |
| `int`, `integer`, `bigint`, `int4`, … | as an integer    |
| `numeric`, `decimal`, `float`, …      | as a number      |
| `date`                                | as a date        |
| `timestamp`, `datetime`               | as a timestamp   |

```php
/* Matches a stored [1, 5] */
User::find()->arrayContains('role_ids', 5, 'int')->get();

/* Does NOT match a stored [1, 5] — 5 is compared as the text "5" */
User::find()->arrayContains('role_ids', 5)->get();
```

### Or Variants

```php
User::find()
    ->arrayContains('tags', 'php')
    ->orArrayContains('tags', 'python')
    ->get();

User::find()
    ->where('status', 'active')
    ->orArrayOverlaps('skills', ['docker', 'kubernetes'])
    ->get();
```

> For GIN index recommendations and schema examples, see
> the [PostgreSQL Guide](10-POSTGRESQL.md#array-columns).

## CASE WHEN Expressions

Build SQL `CASE WHEN ... THEN ... ELSE ... END` expressions using a fluent
builder. Works in SELECT, WHERE, and ORDER BY.

### What is a CASE Expression?

A CASE expression lets you return different values based on conditions — like an
if/else in SQL:

```sql
SELECT name,
       CASE WHEN score > 90 THEN 'A'
            WHEN score > 70 THEN 'B'
            ELSE 'C'
       END AS grade
FROM user
```

With FLIQ, you build this fluently:

```php
use Simsoft\DB\Builder\Clauses\CaseExpression;

User::find()->select(
    'name',
    CaseExpression::when('score', '>', 90)->then('A')
        ->andWhen('score', '>', 70)->then('B')
        ->else('C')
        ->as('grade')
)->get();
```

### Basic Usage

```php
use Simsoft\DB\Builder\Clauses\CaseExpression;

/* Single condition with else */
User::find()->select(
    'name',
    CaseExpression::when('status', '=', 'active')->then('Yes')
        ->else('No')
        ->as('is_active')
)->get();
```

### Multiple Conditions

Chain `->andWhen()` for additional WHEN clauses:

```php
User::find()->select(
    'name',
    CaseExpression::when('role', '=', 'admin')->then('Full Access')
        ->andWhen('role', '=', 'editor')->then('Edit Access')
        ->andWhen('role', '=', 'member')->then('Read Only')
        ->else('No Access')
        ->as('access_level')
)->get();
```

### Comparing Two Columns

Use `whenColumn()` when both sides are column names (not literal values):

```php
/* Same-table columns */
User::find()->select(
    'name',
    CaseExpression::whenColumn('score', '>', 'min_score')->then('pass')
        ->andWhenColumn('score', '=', 'min_score')->then('borderline')
        ->else('fail')
        ->as('result')
)->get();

/* Cross-table columns (with dot notation) */
CaseExpression::whenColumn('order.total', '>', 'customer.credit_limit')
    ->then('over_limit')
    ->else('ok')
    ->as('credit_status');
```

### Raw Conditions

For complex conditions that can't be expressed with column/operator/value:

```php
CaseExpression::whenRaw('age >= ? AND age < ?', [18, 30])->then('young')
    ->andWhenRaw('age >= ? AND age < ?', [30, 50])->then('middle')
    ->else('senior')
    ->as('age_group');
```

### Use in ORDER BY

Pass the expression itself. Its WHEN and THEN values are bound, and `orderBy()`
collects them along with the SQL:

```php
/* Sort admins first, editors second, others last */
User::find()
    ->orderBy(
        CaseExpression::when('role', '=', 'admin')->then(1)
            ->andWhen('role', '=', 'editor')->then(2)
            ->else(3)
    )
    ->get();
```

Casting it to a string leaves those values behind, so if you do that, hand them
over yourself — and read them *after* the cast, since the expression collects
them while it builds:

```php
$order = CaseExpression::when('role', '=', 'admin')->then(1)->else(2);
$sql = (string) $order;

User::find()->orderByRaw($sql, $order->getBinds())->get();
```

### Method Reference

| Method                                             | What It Does                                    |
|----------------------------------------------------|-------------------------------------------------|
| `CaseExpression::when($col, $op, $val)`            | Start with a value comparison (binds the value) |
| `CaseExpression::whenColumn($col, $op, $otherCol)` | Start with a column-to-column comparison        |
| `CaseExpression::whenRaw($sql, $binds)`            | Start with a raw SQL condition                  |
| `->then($value)`                                   | Set the result for the current WHEN             |
| `->andWhen($col, $op, $val)`                       | Add another value comparison WHEN               |
| `->andWhenColumn($col, $op, $otherCol)`            | Add another column comparison WHEN              |
| `->andWhenRaw($sql, $binds)`                       | Add another raw WHEN                            |
| `->else($value)`                                   | Set the fallback value (optional)               |
| `->as($alias)`                                     | Add a column alias for SELECT                   |

---

## Multi-Column Conditions

### `whereAny()` — Any column matches (OR)

```php
/* WHERE (name LIKE ? OR email LIKE ? OR phone LIKE ?) */
User::find()->whereAny(['name', 'email', 'phone'], 'like', '%john%')->get();
```

### `whereAll()` — All columns match (AND)

```php
/* WHERE (title LIKE ? AND body LIKE ?) */
Post::find()->whereAll(['title', 'body'], 'like', '%php%')->get();
```

### `whereNone()` — No column matches (NOT OR)

```php
/* WHERE NOT (title LIKE ? OR body LIKE ?) */
Post::find()->whereNone(['title', 'body'], 'like', '%spam%')->get();
```

### With JSON paths

JSON `->` notation works in all multi-column methods:

```php
/* WHERE (`user`.`username` LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.nickname')) LIKE ?
   OR JSON_UNQUOTE(JSON_EXTRACT(`user`.`meta`, '$.email')) LIKE ?) */
User::find()->whereAny(['username', 'meta->nickname', 'meta->email'], 'like', '%john%')->get();

/* WHERE (JSON_UNQUOTE(JSON_EXTRACT(`post`.`meta`, '$.lang')) = ?
   AND JSON_UNQUOTE(JSON_EXTRACT(`post`.`meta`, '$.region')) = ?) */
Post::find()->whereAll(['meta->lang', 'meta->region'], '=', 'en')->get();
```

## Like Clauses

Methods: `like()`, `notLike()`, `orLike()`, `orNotLike()`

```php
/* WHERE `t`.`name` LIKE ? AND ( `t`.`name` NOT LIKE ? OR `t`.`name` NOT LIKE ? ) */
$users = (new ActiveQuery())
    ->from('user t')
    ->like('name', '%john%')
    ->where(function ($query) {
        $query->notLike('name', '%Jane%')
            ->orNotLike('name', '%Simon%');
    })
    ->on('mysql')
    ->get();
```

An empty array of patterns adds no condition, so a search form submitted blank
returns unfiltered results rather than failing. Any other conditions still
apply on their own:

```php
/* WHERE `user`.`status_code` = ? — the like contributes nothing */
$terms = [];
$users = User::find()->where('status_code', 1)->like('username', $terms)->get();
```

This matches `in()`, which likewise skips an empty value list. Filter the
result set yourself if an empty search should instead return nothing.

## Ordering, Grouping, Limit & Offset

```php
/* SELECT `t`.`role`, COUNT(*) AS total FROM `user` `t`
   GROUP BY `t`.`role`
   HAVING COUNT(*) > ?
   ORDER BY `t`.`role` ASC
   LIMIT 30, 20 */
$users = (new ActiveQuery())
    ->from('user t')
    ->select('role', new Raw('COUNT(*) AS total'))
    ->groupBy('role')
    ->having(new Raw('COUNT(*)'), '>', 1)
    ->orderBy('role')
    ->limit(20, 30)
    ->on('mysql')
    ->get();

/* SELECT `user`.* FROM `user` ORDER BY `user`.`first_name` ASC, `user`.`last_name` DESC */
$users = (new ActiveQuery())
    ->from('user')
    ->orderBy([
        'first_name' => 'ASC',
        'last_name' => 'DESC',
    ])
    ->on('mysql')
    ->get();

/* SELECT `user`.* FROM `user` ORDER BY `user`.`last_name` DESC, `user`.`first_name` DESC */
// A plain list takes the direction argument, so several columns can share one.
$users = (new ActiveQuery())
    ->from('user')
    ->orderByDesc(['last_name', 'first_name'])
    ->on('mysql')
    ->get();

/* SELECT `user`.* FROM `user` ORDER BY `user`.`role` ASC, `user`.`score` DESC */
// The two shapes may be mixed: a keyed entry uses its own direction.
$users = (new ActiveQuery())
    ->from('user')
    ->orderBy(['role', 'score' => 'DESC'])
    ->on('mysql')
    ->get();

/* SELECT `user`.* FROM `user` ORDER BY `user`.`created_at` DESC */
$users = (new ActiveQuery())
    ->from('user')
    ->orderByDesc('created_at')
    ->on('mysql')
    ->get();

/* SELECT `user`.* FROM `user` LIMIT 0, 50 */
$users = (new ActiveQuery())
    ->from('user')
    ->page(1, 50)  // page 1, 50 per page
    ->on('mysql')
    ->get();
```

### `HAVING`: one expression, several conditions

`GROUP BY` takes a list, but `HAVING` takes a single boolean expression. Repeated
calls are joined with `AND`, or with `OR` through `orHaving()` and
`orHavingRaw()` — the same way `where()` and `orWhere()` build the `WHERE`
clause:

```php
/* HAVING COUNT(*) > ? AND MAX(`score`) > ? */
$q->groupBy('role')
  ->having(new Raw('COUNT(*)'), '>', 1)
  ->having(new Raw('MAX(score)'), '>', 80);

/* HAVING COUNT(*) > ? OR MAX(`score`) > ? */
$q->groupBy('role')
  ->having(new Raw('COUNT(*)'), '>', 5)
  ->orHaving(new Raw('MAX(score)'), '>', 90);
```

#### Filtering on an aggregate

A plain string attribute is qualified with the table alias, as everywhere else
in the builder. That is what you want for a real column, but a `SELECT` alias is
not one — `having('total', '>', 1)` builds `` `t`.`total` > ? `` and the server
answers `Unknown column 't.total' in 'having clause'`. Repeat the aggregate in a
`Raw`, or name the alias through `havingRaw()`:

```php
/* HAVING COUNT(*) > ? */
$q->select('role', new Raw('COUNT(*) AS total'))
  ->groupBy('role')
  ->having(new Raw('COUNT(*)'), '>', 1);

/* HAVING total > ? — MySQL resolves the select alias here */
$q->select('role', new Raw('COUNT(*) AS total'))
  ->groupBy('role')
  ->havingRaw('total > ?', [1]);
```

Alias resolution in `HAVING` is a MySQL extension; the standard, and Postgres,
require the expression. Prefer the `Raw` aggregate form if you target both.

#### Comparing against a `Raw` expression

A `Raw` attribute given alone is used as the whole condition. Given an operator
and a value it becomes the left-hand side of a comparison, with the value bound:

```php
$q->where(new Raw('score > 90'));              /* WHERE score > 90 */
$q->where(new Raw('score'), '>', 90);          /* WHERE score > ?  — binds 90 */
$q->groupBy('role')->having(new Raw('COUNT(*)'), '>', 2);
```

This holds for `where()` and `having()` alike. As always with `Raw`, the
expression is emitted verbatim — never build one from user input; put the value
in the operand, where it is bound.

### `IS` and `IS NOT` compare against `NULL`

Both operators take `NULL` on the right, not a value, in `where()` and
`having()`:

```php
$q->where('deleted_at', 'IS', null);      /* WHERE `u`.`deleted_at` IS NULL */
$q->where('deleted_at', 'IS NOT', null);  /* WHERE `u`.`deleted_at` IS NOT NULL */
```

Given anything else they throw `InvalidArgumentException`, since `col IS ?` is
not a shape the server accepts. Use `=` or `!=` to compare a value.

### Sort direction: `ASC` or `DESC` only

The direction is part of the SQL text, so — like the operator in `where()` — it
is checked against a short list. `ASC` and `DESC` are accepted in any case
(`'asc'`, `'Desc'`); **anything else quietly falls back to `ASC`** rather than
erroring:

```php
User::find()->orderBy('id', 'DESC');   /* ORDER BY `id` DESC */
User::find()->orderBy('id', 'desc');   /* ORDER BY `id` DESC — same */
User::find()->orderBy('id', 'sideways'); /* ORDER BY `id` ASC — fallback */
```

This makes a sort direction taken straight from a URL safe to pass through:

```php
/* A visitor sending ?sort=DESC;DROP TABLE user gets plain ASC, not a broken query */
User::find()->orderBy('created_at', $_GET['sort'] ?? 'ASC')->get();
```

Every form is guarded the same way: the keyed array (`orderBy(['id' => 'DESC'])`)
and the list array, whose direction comes from the argument
(`orderBy(['id', 'score'], $_GET['sort'] ?? 'ASC')`). For a sort expression the
keywords can't express, use `orderByRaw()`.

### Limit and page numbers must make sense

`limit()`, `offset()` and `page()` reject values that can't produce a sensible
query, so a bad number fails loudly instead of silently returning the wrong
rows:

```php
User::find()->limit(10);      /* fine */
User::find()->limit(0);       /* fine — 0 means "no limit" */
User::find()->page(1, 25);    /* fine — pages start at 1 */

User::find()->limit(-5);      /* InvalidArgumentException */
User::find()->offset(-1);     /* InvalidArgumentException */
User::find()->page(0);        /* InvalidArgumentException — there is no page 0 */
```

If a page number comes from a URL, clamp it before passing it on:

```php
$page = max(1, (int)($_GET['page'] ?? 1));
$users = User::find()->page($page, 25)->get();
```

## Join Clauses

Methods: `join()`, `leftJoin()`, `rightJoin()`, `crossJoin()`, `leftOuterJoin()`, `rightOuterJoin()`

### ON Key Mapping

The `join()` array parameter is `['joined_table_column' => 'reference_column']`:
- The **key** is always a column on the joined table (auto-prefixed with the join table name)
- The **value** references a column — use dot notation (`table.column`) to specify which table

### Attribute Resolution

| Syntax           | Resolves to                 | Use case                        |
|------------------|-----------------------------|---------------------------------|
| `'column'`       | `` `main_table`.`column` `` | Column on the main (FROM) table |
| `'table.column'` | `` `table`.`column` ``      | Explicit table reference        |
| `'table.*'`      | `` `table`.* ``             | All columns from a table        |
| `'{column}'`     | Deferred resolution         | Used inside Raw expressions     |

This applies to `select()`, `where()`, `orderBy()`, `groupBy()`, `having()`, `in()`, `between()`, `like()`, `isNull()`, and join ON values.

### Examples

```php
/* SELECT `u`.`first_name`, `u`.`last_name`, `profile`.`email` FROM `user` `u`
   INNER JOIN `profile` ON `profile`.`user_id` = `u`.`id`
   WHERE `u`.`status` = ? */
$users = (new ActiveQuery())
    ->from('user u')
    ->select('first_name', 'last_name', 'profile.email')
    ->join('profile', ['user_id' => 'id'])
    ->where('status', 1)
    ->on('mysql')
    ->get();

/* SELECT `u`.* FROM `user` `u`
   LEFT JOIN `profile` AS `p` ON `p`.`user_id` = `u`.`id` */
$users = (new ActiveQuery())
    ->from('user u')
    ->leftJoin('profile p', ['user_id' => 'id'])
    ->on('mysql')
    ->get();

/* Multiple joins — use dot notation to reference specific tables
   SELECT `post`.`title`, `user`.`username`, `category`.`name` FROM `post`
   INNER JOIN `user` ON `user`.`id` = `post`.`user_id`
   INNER JOIN `category` ON `category`.`id` = `post`.`category_id` */
$posts = (new ActiveQuery())
    ->from('post')
    ->select('post.title', 'user.username', 'category.name')
    ->join('user', ['id' => 'post.user_id'])
    ->join('category', ['id' => 'post.category_id'])
    ->on('mysql')
    ->get();
```

### Joining a Sub-query

Pass `[alias => query]` as the table. The sub-query may be an `ActiveQuery` or a
`Raw`; either way its bind values are kept and handed over in the position the
`JOIN` occupies, which is after `FROM` and before `WHERE`:

```php
/* SELECT `u`.`id` FROM `user` `u`
   INNER JOIN (SELECT `post`.`user_id` FROM `post` WHERE `post`.`view_count` > ?) AS `p`
     ON `p`.`user_id` = `u`.`id`
   WHERE `u`.`score` > ?
   — binds [100, 50] */
$popular = (new ActiveQuery())
    ->from('post')
    ->select('user_id')
    ->where('view_count', '>', 100);

$users = (new ActiveQuery())
    ->from('user u')
    ->select('id')
    ->join(['p' => $popular], ['user_id' => 'id'])
    ->where('score', '>', 50)
    ->on('mysql')
    ->get();
```

The same array form works with `leftJoin()`, `rightJoin()` and the rest. The ON
mapping is keyed by the sub-query's own column, as with a plain table.

### Joins Without an ON Clause

A `CROSS JOIN` pairs every row with every row, so it takes no key mapping —
omit the second argument:

```php
/* SELECT `user`.* FROM `user` CROSS JOIN `size` */
$combinations = (new ActiveQuery())
    ->from('user')
    ->crossJoin('size')
    ->on('mysql')
    ->get();

/* Aliases still apply: ... CROSS JOIN `size` AS `s` */
$combinations = (new ActiveQuery())
    ->from('user')
    ->crossJoin('size s')
    ->on('mysql')
    ->get();
```

Any join type called without keys omits the `ON` clause the same way.

### Scoping Conditions to a Joined Table

An unqualified column name resolves against the FROM table, so constraining a
joined table normally means prefixing each column. `withAlias()` swaps the
alias for the duration of a callback so those columns can be named plainly:

```php
/* SELECT `u`.`id` FROM `user` `u`
   INNER JOIN `post` AS `p` ON `p`.`user_id` = `u`.`id`
   WHERE `p`.`view_count` > ? AND `u`.`status_code` = ? */
$users = (new ActiveQuery())
    ->from('user u')
    ->select('u.id')
    ->join('post p', ['p.user_id' => 'u.id'])
    ->withAlias('p', fn($query) => $query->where('view_count', '>', 0))
    ->where('status_code', 1)
    ->on('mysql')
    ->get();
```

The alias applies only inside the callback — `status_code` above still
resolves to `` `u` ``. It covers `select()`, `where()`, `groupBy()`,
`having()`, and `orderBy()`, and is restored even if the callback throws.
Names already carrying a prefix are left alone.

### Raw Expressions in Joins

Use `{attribute}` placeholders inside `Raw` expressions — they resolve to the main table's qualified column:

```php
use Simsoft\DB\Builder\Raw;

/* SELECT `u`.*, `profile`.`bio` FROM `user` `u`
   INNER JOIN `profile` ON `profile`.`user_id` = `u`.`id`
   WHERE `profile`.`verified` = ? */
$users = (new ActiveQuery())
    ->from('user u')
    ->select('*', 'profile.bio')
    ->join('profile', ['user_id' => 'id'])
    ->where('profile.verified', true)
    ->on('mysql')
    ->get();

// {id} resolves to `u`.`id` (the main table alias)
/* SELECT `u`.*, `log`.`action` FROM `user` `u`
   WHERE `log`.`user_id` = `u`.`id` AND `log`.`created_at` > ? */
$users = (new ActiveQuery())
    ->from('user u')
    ->selectRaw('`u`.*, `log`.`action`')
    ->whereRaw('`log`.`user_id` = {id} AND `log`.`created_at` > ?', ['2024-01-01'])
    ->on('mysql')
    ->get();
```

In `{id}`, the `id` resolves to `\`u\`.\`id\`` (the main table alias). This is useful when you need to reference the main table inside raw SQL fragments.

## Aggregation

Methods: `count()`, `sum()`, `avg()`, `min()`, `max()`

Each also has a `Distinct` variant: `countDistinct()`, `sumDistinct()`, etc.

```php
$query = User::find()->where('status', 1);

/* SELECT COUNT(*) FROM `user` WHERE `user`.`status` = ? */
$total = $query->count();

/* SELECT COUNT(`user`.`email`) FROM `user` WHERE `user`.`status` = ? */
$emailCount = $query->count('email');

/* SELECT COUNT(DISTINCT `user`.`role`) FROM `user` WHERE `user`.`status` = ? */
$roles = $query->countDistinct('role');

/* SELECT AVG(`user`.`age`) FROM `user` WHERE `user`.`status` = ? */
$avgAge = $query->avg('age');

/* SELECT SUM(`user`.`salary`) FROM `user` WHERE `user`.`status` = ? */
$totalSalary = $query->sum('salary');

/* SELECT MIN(`user`.`age`) FROM `user` WHERE `user`.`status` = ? */
$minAge = $query->min('age');

/* SELECT MAX(`user`.`salary`) FROM `user` WHERE `user`.`status` = ? */
$maxSalary = $query->max('salary');

/* SELECT SUM(DISTINCT `user`.`score`) FROM `user` WHERE `user`.`status` = ? */
$uniqueScoreSum = $query->sumDistinct('score');
```

Aggregations respect all conditions (WHERE, JOIN, GROUP BY) on the query:

```php
/* SELECT SUM(`order`.`total`) FROM `order`
   INNER JOIN `user` ON `user`.`id` = `order`.`user_id`
   WHERE `user`.`role` = ? */
$vipTotal = Order::find()
    ->join('user', ['id' => 'order.user_id'])
    ->where('user.role', 'vip')
    ->sum('total');
```

### Empty result sets

An aggregate over zero rows returns `0` (`0.0` for `avg()`), not `null`. SQL's
`SUM()`, `MIN()`, `MAX()` and `AVG()` all yield `NULL` when nothing matches; the
builder normalises that so the return type is always numeric.

```php
$noRows = User::find()->where('username', 'nobody');

$noRows->count();  // 0
$noRows->sum('score');  // 0
$noRows->max('score');  // 0
$noRows->avg('score');  // 0.0
```

### `countDistinct('*')`

`COUNT(DISTINCT *)` is not valid SQL, so `countDistinct()` drops the `DISTINCT`
keyword when the attribute is `*` and behaves like a plain `count()`. Pass a
column name to actually count distinct values:

```php
$query->countDistinct();        // COUNT(*) — same as count()
$query->countDistinct('*');     // COUNT(*) — same as count()
$query->countDistinct('role');  // COUNT(DISTINCT `user`.`role`)
```

### Result alias

Every aggregate takes a second argument naming the result column — `'total'` for
the counts, `'sum'`, `'avg'`, `'min'`, `'max'` for the rest. Pass your own, or
`null` to omit the `AS` clause. The return value is the same either way:

```php
$query->count();             // SELECT COUNT(*) AS `total` ...
$query->count('*', 'c');     // SELECT COUNT(*) AS `c` ...
$query->count('*', null);    // SELECT COUNT(*) ...
```

### Total pages

`getTotalPages()` counts the matching rows and divides by the page size,
rounding up. It throws `InvalidArgumentException` if the page size is below 1,
and returns `0` when nothing matches:

```php
User::find()->getTotalPages(20);                  // ceil(count / 20)
User::find()->where('role', 'admin')->getTotalPages(10);
User::find()->getTotalPages(20, 'department_id'); // counts that column instead

User::find()->getTotalPages(0);  // InvalidArgumentException
```

## Sub-queries

Use an array with alias as key for sub-query FROM:

```php
/* FROM (SELECT ... FROM `user` `t` WHERE `t`.`id` > ?) `u` WHERE `u`.`age` > ? */
$users = (new ActiveQuery())
    ->from(['u' => (new ActiveQuery())
        ->from('user t')
        ->select('first_name', 'last_name', 'age')
        ->where('id', '>', 10)
    ])
    ->where('age', '>', 20)
    ->on('mysql')
    ->get();
```

## Collections

`get()` returns a `Collection` object. You can loop through it directly or chain
methods like `filter()`, `map()`, and `groupBy()`:

```php
$users = User::find()->where('status', 1)->get();

// Loop through results
foreach ($users as $user) {
    echo $user->name;
}

// Filter and transform
$adminNames = User::find()->get()
    ->filter(fn($user) => $user->role === 'admin')
    ->map(fn($user) => $user->name);
```

For the full method reference, see the [Collections guide](06-COLLECTIONS.md).

## Raw Expressions

Use raw SQL fragments within the fluent builder for expressions that can't be built with methods.

Every one of these takes an optional second argument holding the values for any
placeholders the expression contains. Put user input there rather than into the
SQL — see [Security](#security-how-your-values-are-protected) below.

### `selectRaw()` — Raw SELECT expression

```php
/* SELECT `user`.`department_id`, COUNT(*) AS total FROM `user`
   GROUP BY `user`.`department_id` */
$users = User::find()
    ->select('department_id')
    ->selectRaw('COUNT(*) AS total')
    ->groupBy('department_id')
    ->get();

/* With a bound value: SELECT IF(`score` > ?, 1, 0) AS passed */
$users = User::find()
    ->selectRaw('IF(`score` > ?, 1, 0) AS passed', [$threshold])
    ->get();
```

Select only the columns you group by, or aggregates of the rest — MySQL's
default `only_full_group_by` and PostgreSQL both reject anything else.

### `orderByRaw()` — Raw ORDER BY expression

```php
/* SELECT `user`.* FROM `user` ORDER BY FIELD(status, 3, 1, 2) */
$users = User::find()
    ->orderByRaw('FIELD(status, 3, 1, 2)')
    ->get();

/* With a bound value */
$users = User::find()
    ->orderByRaw('FIELD(`status`, ?) DESC', [$first])
    ->get();
```

`orderBy()` also accepts a `Raw` expression directly, emitted as written rather
than quoted as a column name. No direction is appended — an expression that
wants one says so itself:

```php
$users = User::find()
    ->orderBy(new Raw('FIELD(`status`, ?) DESC', [$first]))
    ->orderBy('id')
    ->get();
```

### `groupByRaw()` — Raw GROUP BY expression

```php
/* SELECT YEAR(created_at) AS year, COUNT(*) AS total FROM `user` GROUP BY YEAR(created_at) */
$stats = User::find()
    ->selectRaw('YEAR(created_at) AS year, COUNT(*) AS total')
    ->groupByRaw('YEAR(created_at)')
    ->get();
```

### `havingRaw()` — Raw HAVING expression

```php
/* SELECT `user`.`department_id`, COUNT(*) AS cnt FROM `user`
   GROUP BY `user`.`department_id` HAVING COUNT(*) > ? */
$departments = User::find()
    ->select('department_id')
    ->selectRaw('COUNT(*) AS cnt')
    ->groupBy('department_id')
    ->havingRaw('COUNT(*) > ?', [5])
    ->get();
```

### `whereRaw()` — Raw WHERE expression

```php
/* SELECT `user`.* FROM `user` WHERE `user`.`salary` * 12 > ? */
$users = User::find()
    ->whereRaw('{salary} * 12 > ?', [100000])
    ->get();
```

### `whereColumn()` — Compare two columns

```php
/* SELECT `user`.* FROM `user` WHERE `user`.`updated_at` > `user`.`created_at` */
$users = User::find()
    ->whereColumn('updated_at', '>', 'created_at')
    ->get();
```

---

## Security: how your values are protected

If you're new to SQL injection: the danger is a visitor typing something that
stops being *data* and starts being *SQL*. The classic example is a login form
where someone enters `' OR '1'='1` as their username and gets in.

### Values are never pasted into the SQL

FLIQ sends your query and your values to the database **separately**. The query
contains a `?` where each value goes, and the database treats whatever arrives
as plain text — never as SQL:

```php
$username = "' OR '1'='1";   // someone trying their luck

$user = User::find()->where('username', $username)->first();
/* Sent as: SELECT ... WHERE `username` = ?
   with the value: ' OR '1'='1
   Result: no match. Nothing is executed. */
```

You do **not** need to escape, quote, or sanitise values before passing them
in. Doing so usually just stores mangled data. This applies to every method
that takes a value: `where()`, `in()`, `between()`, `like()`, `having()`, the
JSON methods, and join ON values.

### What isn't a value

Some parts of a query can't be sent separately — they *are* the SQL. FLIQ
checks these against fixed lists instead:

| Part                        | Protection                                   |
|-----------------------------|----------------------------------------------|
| Values                      | Sent separately as `?` — always safe         |
| Operators (`>`, `LIKE`, …)  | [Whitelist](#which-operators-can-i-use); invalid ones throw |
| Sort direction              | [`ASC`/`DESC` only](#sort-direction-asc-or-desc-only); anything else becomes `ASC` |
| Limit / offset / page       | [Must be non-negative](#limit-and-page-numbers-must-make-sense); invalid ones throw |
| Table names                 | Validated when the table is set              |
| Column names                | Quoted and escaped, but not checked against a list |

### The part you own: column names

Column names are quoted, and any quote character inside the name is escaped so
it cannot break out and become SQL. But they are **not** validated against a
list of known columns, because they can legitimately be `*`, `user.*`, or a
JSON path — there is no single pattern to check against.

So a hostile column name can't inject SQL, but it can still reach the database
as a bad query. If a column name comes from user input, check it yourself
against the columns you expect:

```php
/* Don't hand a URL parameter straight to the builder */
$sort = $_GET['sort'] ?? 'created_at';

/* Do check it against a list you control */
$allowed = ['created_at', 'username', 'score'];
$sort = in_array($sort, $allowed, true) ? $sort : 'created_at';

$users = User::find()->orderBy($sort, $_GET['dir'] ?? 'ASC')->get();
```

The direction needs no such check — it's already restricted to `ASC`/`DESC`.

### `Raw` hands the responsibility back to you

`Raw` and the `*Raw()` methods insert your SQL text as-is. That's the point of
them — but it means **you** are responsible for anything you interpolate. Bind
values with `?` and never build the string with user input:

```php
/* SAFE — the value is bound */
User::find()->whereRaw('{salary} * 12 > ?', [$_GET['min']])->get();

/* UNSAFE — the value becomes part of the SQL */
User::find()->whereRaw('{salary} * 12 > ' . $_GET['min'])->get();
```

Identifiers cannot be bound — `?` only stands in for values, never for a table
or column name. When one has to come from user input, match it against a list
you control rather than escaping it:

```php
/* SAFE — the input selects a column, it never becomes one */
$columns = ['name' => 'user.name', 'joined' => 'user.created_at'];
$column = $columns[$_GET['sort'] ?? ''] ?? 'user.id';

User::find()->orderByRaw("$column DESC")->get();
```

The same applies to anything else the database parses as syntax rather than
data: sort directions, operators, and SQL keywords. Everything else — every
actual value — belongs in a bind.

### Mass assignment

Writing whole request arrays to a model has its own protections — see
[Mass Assignment Protection](03-ACTIVE-RECORD.md#mass-assignment-protection)
in the Active Record guide.

---

## Date Filters

Filter by date parts using database-specific extraction functions. Works on MySQL, PostgreSQL, and SQLite.

### `whereDate()` — Filter by date (ignores time)

```php
/* MySQL:      WHERE DATE(`order`.`ordered_at`) = ?
   PostgreSQL: WHERE "order"."ordered_at"::date = ?
   SQLite:     WHERE date("order"."ordered_at") = ? */
$orders = Order::find()->whereDate('ordered_at', '=', '2024-01-05')->get();
$orders = Order::find()->whereDate('ordered_at', '>', '2024-06-01')->get();
```

### `whereMonth()` — Filter by month number (1-12)

```php
/* MySQL:      WHERE MONTH(`order`.`ordered_at`) = ?
   PostgreSQL: WHERE EXTRACT(MONTH FROM "order"."ordered_at") = ?
   SQLite:     WHERE CAST(strftime('%m', "order"."ordered_at") AS INTEGER) = ? */
$orders = Order::find()->whereMonth('ordered_at', '=', 1)->get();
```

### `whereYear()` — Filter by year

```php
/* MySQL:      WHERE YEAR(`order`.`ordered_at`) = ?
   PostgreSQL: WHERE EXTRACT(YEAR FROM "order"."ordered_at") = ?
   SQLite:     WHERE CAST(strftime('%Y', "order"."ordered_at") AS INTEGER) = ? */
$orders = Order::find()->whereYear('ordered_at', '=', 2024)->get();
```

### `whereTime()` — Filter by time (ignores date)

```php
/* MySQL:      WHERE TIME(`order`.`ordered_at`) = ?
   PostgreSQL: WHERE "order"."ordered_at"::time = ?
   SQLite:     WHERE time("order"."ordered_at") = ? */
$orders = Order::find()->whereTime('ordered_at', '=', '10:00:00')->get();
$orders = Order::find()->whereTime('ordered_at', '>', '17:00:00')->get();
```

---

## Scopes

The recommended pattern is to extend `ActiveQuery` with custom methods on a per-model basis. See [Active Record — Scopes](03-ACTIVE-RECORD.md#scopes) for the full pattern.

For one-off conditional logic without a custom query class, use `scope()`:

```php
/* Define reusable scopes as closures or functions */
$active = function ($query) {
    $query->where('status', 'active');
};

$recent = function ($query) {
    $query->where('created_at', '>', '2024-01-01');
};

// Apply scopes to any query
$users = (new ActiveQuery())
    ->from('user')
    ->scope($active)
    ->scope($recent)
    ->orderBy('created_at', 'DESC')
    ->on('mysql')
    ->get();
```

Scopes work with Model queries too:

```php
$active = fn($query) => $query->where('status', 'active');
$verified = fn($query) => $query->whereNotNull('verified_at');

$users = User::find()
    ->scope($active)
    ->scope($verified)
    ->get();
```

## Conditional Clauses

Use `when()` to apply conditions only when a boolean is true. This avoids wrapping queries in if-statements:

```php
$search = $_GET['search'] ?? null;
$status = $_GET['status'] ?? null;
$sortDesc = $_GET['sort'] === 'desc';

$users = User::find()
    ->select('first_name', 'last_name', 'email')
    ->when($search !== null, function ($query) use ($search) {
        $query->like('name', "%$search%");
    })
    ->when($status !== null, function ($query) use ($status) {
        $query->where('status', $status);
    })
    ->when($sortDesc, function ($query) {
        $query->orderBy('created_at', 'DESC');
    })
    ->get();
```

When the condition is `false`, the scope is skipped entirely — no conditions are added.

This is useful for building queries from optional filters:

```php
$filters = [
    'country' => 'MY',
    'min_age' => 18,
    'role' => null, // not set
];

$users = User::find()
    ->when($filters['country'] !== null, fn($query) => $query->where('country', $filters['country']))
    ->when($filters['min_age'] !== null, fn($query) => $query->where('age', '>=', $filters['min_age']))
    ->when($filters['role'] !== null, fn($query) => $query->where('role', $filters['role']))
    ->get();

/* Produces: WHERE country = ? AND age >= ? */
// The role condition is skipped because it's null
```

### `when()` with Else Branch

Pass a third argument to handle the false case:

```php
$sortBy = $_GET['sort'] ?? null;

$users = User::find()
    ->when(
        $sortBy !== null,
        fn($query) => $query->orderBy($sortBy),
        fn($query) => $query->orderBy('id')  // default sort when no sort param
    )
    ->get();
```

### `unless()` — Inverse of `when()`

Applies the scope when the condition is **false**:

```php
$isAdmin = $currentUser->role === 'admin';

// Non-admins can only see published posts
$posts = Post::find()
    ->unless($isAdmin, fn($query) => $query->where('published', true))
    ->get();
```

`unless()` also accepts an optional else branch:

```php
$posts = Post::find()
    ->unless(
        $isAdmin,
        fn($query) => $query->where('published', true),   // applied when NOT admin
        fn($query) => $query->where('deleted', '!=', true) // applied when IS admin
    )
    ->get();
```

### `tap()` — Inspect Without Modifying

`tap()` lets you inspect the query mid-chain without affecting it. Useful for debugging:

```php
$users = User::find()
    ->where('status', 'active')
    ->tap(fn($query) => error_log($query->getFullSQL()))
    ->orderBy('name')
    ->get();
```

The return value of the callback is ignored — the query continues unchanged.

### `dd()` — Dump and Die

Outputs the full SQL with values interpolated, then stops execution:

```php
User::find()
    ->where('status', 'active')
    ->orderBy('name')
    ->dd();

// Output:
// SELECT `user`.* FROM `user` WHERE `user`.`status` = 'active' ORDER BY `user`.`name` ASC
// (script exits)
```

Works on any builder — ActiveQuery, Raw, Insert, Update, Delete:

```php
DB::table('orders')
    ->where('total', '>', 100)
    ->join('user', ['id' => 'orders.user_id'])
    ->dd();

// Output:
// SELECT `orders`.* FROM `orders` INNER JOIN `user` ON `user`.`id` = `orders`.`user_id`
// WHERE `orders`.`total` > '100'
```

The `100` comes back quoted because that is how the value is sent. Except on
SQLite, `PDOStatement::execute()` binds every value as a string, so the server
compares `'100'` and not the number — and against a text column those differ.
The rendering shows what ran rather than what you typed, which is the point of
looking at it. SQLite's driver binds by type and its dumps show numbers bare,
for the same reason.

Values are escaped for the connection's own engine, so the output can be pasted
into a client as-is. It is still a debugging aid, not a way to build SQL: use
the builder, or [`Raw`](#raw-expressions) with binds, for anything you intend to
execute.

### `dump()` — Dump Without Stopping

Same as `dd()` but continues execution. Chainable:

```php
$users = User::find()
    ->where('status', 'active')
    ->dump()  // prints full SQL, then continues
    ->orderBy('name')
    ->get();

// Output:
// SELECT `user`.* FROM `user` WHERE `user`.`status` = 'active'
```

### `explain()` — Query Execution Plan

Get the database's query plan for performance analysis. Works on all drivers (
MySQL, PostgreSQL, SQLite).

```php
$plan = User::find()
    ->where('status_code', 1)
    ->orderBy('score', 'DESC')
    ->limit(10)
    ->explain();

/*
  MySQL:      EXPLAIN SELECT ...
  PostgreSQL: EXPLAIN SELECT ...
  SQLite:     EXPLAIN QUERY PLAN SELECT ...
*/
```

#### EXPLAIN ANALYZE (actual execution timings)

```php
/* Actually executes the query and reports real timings */
$plan = User::find()
    ->where('role', 'admin')
    ->explain(analyze: true);
```

#### JSON format (programmatic analysis)

```php
/* PostgreSQL: EXPLAIN (FORMAT JSON) SELECT ... */
/* MySQL:      EXPLAIN FORMAT=JSON SELECT ...   */
$plan = Post::find()
    ->whereFulltext(['title', 'body'], 'optimization')
    ->explain(format: 'json');
```

| Parameter  | Values                    | Default  |
|------------|---------------------------|----------|
| `$analyze` | `true` / `false`          | `false`  |
| `$format`  | depends on the driver     | `'text'` |

Each engine names its own formats, so `$format` is validated against the driver
the query runs on. A format the engine cannot produce raises an
`InvalidArgumentException` rather than silently returning a plan in a different
shape:

| Driver         | `$format`                              | With `analyze: true` |
|----------------|----------------------------------------|----------------------|
| **MySQL**      | `'text'`, `'traditional'`, `'json'`, `'tree'` | `'text'`, `'tree'` |
| **PostgreSQL** | `'text'`, `'json'`, `'yaml'`, `'xml'`  | same                 |
| **SQLite**     | `'text'`                               | not supported        |

MySQL's `EXPLAIN ANALYZE` always reports the tree format and rejects
`FORMAT=JSON` beside it. SQLite has no `EXPLAIN ANALYZE` — `EXPLAIN QUERY PLAN`
describes the plan without executing the statement.

Works on any builder:

```php
use Simsoft\DB\Builder\ActiveQuery;

$plan = (new ActiveQuery())
    ->from('order')
    ->join('user', ['id' => '!order.user_id'])
    ->where('!order.total', '>', 100)
    ->explain(analyze: true);
```
