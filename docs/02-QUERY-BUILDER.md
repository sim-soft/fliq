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
- [Multi-Column Conditions](#multi-column-conditions)
- [Like Clauses](#like-clauses)
- [Ordering, Grouping, Limit & Offset](#ordering-grouping-limit--offset)
- [Join Clauses](#join-clauses)
- [Aggregation](#aggregation)
- [Sub-queries](#sub-queries)
- [Collections](#collections)
- [Raw Expressions](#raw-expressions)
- [Date Filters](#date-filters)
- [Scopes](#scopes)
- [Conditional Clauses](#conditional-clauses)

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

### Between Date Interval

Methods: `betweenDateInterval()`, `notBetweenDateInterval()`, `orBetweenDateInterval()`

```php
/* WHERE created_at >= ? AND created_at < ? + INTERVAL 7 DAY */
$users = (new ActiveQuery())
    ->from('user')
    ->betweenDateInterval('created_at', '2024-01-01', 7)
    ->on('mysql')
    ->get();
```

## Exists Clauses

Methods: `exists()`, `notExists()`, `orExists()`, `orNotExists()`

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
   SQLite:     WHERE EXISTS (SELECT 1 FROM json_each("user"."tags") WHERE json_each.value = ?) */
User::find()->jsonContains('tags', 'php')->get();

// Nested path
/* MySQL: WHERE JSON_CONTAINS(`user`.`meta`, ?, '$.skills') */
User::find()->jsonContains('meta->skills', 'docker')->get();
```

### `jsonNotContains()` — Value NOT in JSON array

```php
/* MySQL: WHERE NOT JSON_CONTAINS(`user`.`tags`, ?, '$') */
User::find()->jsonNotContains('tags', 'spam')->get();
```

### `jsonHas()` — JSON path exists

```php
/* MySQL:      WHERE JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.address')
   PostgreSQL: WHERE "user"."meta" -> 'address' IS NOT NULL */
User::find()->jsonHas('meta->address')->get();
```

### `jsonMissing()` — JSON path does not exist

```php
/* MySQL: WHERE NOT JSON_CONTAINS_PATH(`user`.`meta`, 'one', '$.phone') */
User::find()->jsonMissing('meta->phone')->get();
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

## Ordering, Grouping, Limit & Offset

```php
/* SELECT `t`.`first_name`, `t`.`last_name`, COUNT(*) AS count FROM `user` `t`
   GROUP BY `t`.`first_name`, `t`.`last_name`
   HAVING `count` > ?
   ORDER BY `t`.`first_name` ASC, `t`.`last_name` DESC
   LIMIT 30, 20 */
$users = (new ActiveQuery())
    ->from('user t')
    ->select('first_name', 'last_name', new Raw('COUNT(*) AS count'))
    ->groupBy('first_name', 'last_name')
    ->having('count', '>', 1)
    ->orderBy('first_name')
    ->orderBy('last_name', 'DESC')
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

### `selectRaw()` — Raw SELECT expression

```php
/* SELECT `user`.`name`, COUNT(*) AS total FROM `user` GROUP BY `user`.`department_id` */
$users = User::find()
    ->select('name')
    ->selectRaw('COUNT(*) AS total')
    ->groupBy('department_id')
    ->get();
```

### `orderByRaw()` — Raw ORDER BY expression

```php
/* SELECT `user`.* FROM `user` ORDER BY FIELD(status, 3, 1, 2) */
$users = User::find()
    ->orderByRaw('FIELD(status, 3, 1, 2)')
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
```

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
