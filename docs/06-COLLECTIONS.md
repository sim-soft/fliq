# Collections

The `Collection` class is returned by `get()` and provides a fluent, lazy iterator over query results with chunked fetching for memory efficiency. It implements `IteratorAggregate` and `Countable`, and supports chainable transformations like `map()`, `filter()`, `reduce()`, `indexBy()`, and `groupBy()`.

## Table of Contents
- [Getting a Collection](#getting-a-collection)
- [Basic Operations](#basic-operations)
- [Filter](#filter)
- [Map](#map)
- [Chaining Filter and Map](#chaining-filter-and-map)
- [Reduce](#reduce)
- [Index By](#index-by)
- [Group By](#group-by)
- [Pluck](#pluck)
- [Page](#page)
- [Chunked Iteration](#chunked-iteration)
- [Batch Iteration](#batch-iteration)
- [Each with Custom Chunk Size](#each-with-custom-chunk-size)
- [Real-World Examples](#real-world-examples)
- [Method Reference](#method-reference)

## Getting a Collection

Every call to `get()` returns a `Collection` object — never an array directly:

```php
$collection = User::find()->where('status', 1)->get();

// $collection is an instance of Simsoft\DB\Collection
```

Throughout this guide, `$collection` is used to emphasize that the variable holds a `Collection` object. In your real code, name it whatever makes sense (`$users`, `$activeUsers`, etc.) — all examples below work the same.

## Basic Operations

```php
$collection = User::find()->where('status', 1)->get();

// Iterate (fetches in chunks of 100 internally)
foreach ($collection as $user) {
    echo $user->name;
}

// Total count (runs SELECT COUNT(*) — cached)
$total = $collection->count();

// Use with PHP's count() function
$total = count($collection);

// Check emptiness
$collection->isEmpty();
$collection->isNotEmpty();

// Get first record (or null)
$first = $collection->first();

// Eager load into array (use with caution on large datasets)
$array = $collection->all();
```

## Filter

Lazy filter — runs during iteration, the original collection is not mutated:

```php
$collection = User::find()->get();

$adults = $collection->filter(fn($user) => $user->age >= 18);

foreach ($adults as $user) {
    echo $user->name;
}
```

The callback receives `(value, key)` and should return `true` to keep the record:

```php
$collection = User::find()->get();

$verified = $collection->filter(
    fn($user, $key) => $user->verified_at !== null && $key < 100
);
```

`filter()` returns a **new** collection — the original is unaffected:

```php
$collection = User::find()->get();
$active = $collection->filter(fn($user) => $user->status === 'active');

count($collection); // 100 (still all records)
count($active);     // 60 (note: count() is the DB total, not filtered)

iterator_to_array($collection); // 100 items
iterator_to_array($active);     // 60 items
```

> **Note:** `count()` always returns the DB COUNT(*), not the filtered count. Use `count(iterator_to_array($collection))` to get the filtered count.

## Map

Transform each record into something else. Returns a new collection (lazy):

```php
$collection = User::find()->get();

// Extract just names
$names = $collection->map(fn($user) => $user->name);

// Build DTO objects
$dtos = $collection->map(fn($user) => new UserDto($user->id, $user->name, $user->email));

// Format for API response
$apiData = $collection->map(fn($user) => [
    'id' => $user->id,
    'name' => $user->name,
    'avatar' => $user->avatar_url ?? '/default.png',
]);
```

## Chaining Filter and Map

Both are lazy — they only run during iteration:

```php
$collection = User::find()->get();

$activeAdminNames = $collection
    ->filter(fn($user) => $user->role === 'admin')
    ->filter(fn($user) => $user->status === 'active')
    ->map(fn($user) => $user->name);

foreach ($activeAdminNames as $name) {
    echo $name;
}
```

## Reduce

Combine all records into a single result by processing them one at a time. The callback receives the running result (`$carry`) and the current record, and returns the updated result:

```php
$collection = User::find()->get();

// Sum total scores
$totalScore = $collection->reduce(fn($carry, $user) => $carry + $user->score, 0);
// Starts at 0, adds each user's score → final total

// Concatenate names
$csv = $collection->reduce(fn($carry, $u) => $carry . $user->name . ',', '');

// Build a lookup
$byEmail = $collection->reduce(function ($carry, $u) {
    $carry[$user->email] = $u;
    return $carry;
}, []);

// Average score
$avg = $collection->reduce(fn($carry, $user) => $carry + $user->score, 0) / $collection->count();
```

## Index By

Convert the collection to an associative array indexed by a specific field:

```php
$collection = User::find()->get();

// Index by email
$byEmail = $collection->indexBy('email');
echo $byEmail['alice@example.com']->name;

// Index using a callback (e.g., composite key)
$byCompositeKey = $collection->indexBy(fn($user) => "{$user->department_id}:{$user->id}");
```

Useful for fast lookups:

```php
$collection = User::find()->where('status', 1)->get();
$byId = $collection->indexBy('id');

foreach ($postIds as $id) {
    if (isset($byId[$id])) {
        // O(1) lookup, no extra DB query
    }
}
```

## Group By

Group records into buckets:

```php
$collection = User::find()->get();

// Group users by role
$byRole = $collection->groupBy('role');
// ['admin' => [User, User, ...], 'editor' => [...], 'member' => [...]]

foreach ($byRole as $role => $usersInGroup) {
    echo "$role: " . count($usersInGroup) . " users\n";
}

// Group by callback (custom key)
$byScoreRange = $collection->groupBy(fn($user) => match (true) {
    $user->score >= 90 => 'top',
    $user->score >= 50 => 'middle',
    default => 'bottom',
});
```

## Pluck

Returns a new collection containing only the specified attribute:

```php
$collection = User::find()->where('status', 1)->get();

$emails = $collection->pluck('email');

foreach ($emails as $email) {
    sendEmail($email);
}

// Convert to array
$emailArray = $emails->all();
```

For an indexed result with a different key, use the query builder's `pluck()` directly:

```php
// [1 => 'alice@example.com', 2 => 'bob@example.com', ...]
$emailsById = User::find()->pluck('email', 'id');
```

## Page

Get a specific page (applies any filter/map callbacks):

```php
$collection = User::find()->orderBy('name')->get();

$page1 = $collection->page(1, 25); // page 1, 25 per page
$page2 = $collection->page(2, 25);

// With filter and map applied
$page = User::find()->orderBy('name')->get()
    ->filter(fn($user) => $user->status === 'active')
    ->map(fn($user) => $user->name)
    ->page(1, 50);
```

## Chunked Iteration

Control the internal fetch size for memory tuning:

```php
$collection = User::find()->get();

// Fetch 50 records at a time internally
foreach ($collection->chunk(50) as $user) {
    // processes one user at a time, fetches in batches of 50
}
```

## Batch Iteration

Iterate in batches (yields arrays of records). Useful for bulk operations:

```php
$collection = User::find()->where('needs_processing', true)->get();

// Process 1000 records at a time
foreach ($collection->batch(1000) as $batch) {
    // $batch is an array of up to 1000 User models
    sendBatchEmail($batch);
}
```

## Each with Custom Chunk Size

Returns a generator that iterates one record at a time using a specific chunk size for fetching:

```php
$collection = User::find()->get();

// Fetch 200 at a time, yield one at a time
foreach ($collection->each(200) as $user) {
    processUser($user);
}
```

## Real-World Examples

### Generate a Monthly Report

Combining `filter`, `map`, and `groupBy`:

```php
$collection = Order::find()
    ->where('created_at', '>', '2024-01-01')
    ->get();

$report = $collection
    ->filter(fn($order) => $order->status !== 'cancelled')
    ->map(fn($order) => [
        'id' => $order->id,
        'customer' => $order->customer_name,
        'total' => $order->total,
        'month' => date('Y-m', strtotime($order->created_at)),
    ])
    ->groupBy('month');

foreach ($report as $month => $orders) {
    $total = array_sum(array_column($orders, 'total'));
    echo "$month: " . count($orders) . " orders, total $total\n";
}
```

### Bulk Email with Throttling

```php
$collection = User::find()->where('newsletter', true)->get();

// Process 100 users at a time, sleep between batches
foreach ($collection->batch(100) as $batch) {
    foreach ($batch as $user) {
        sendNewsletter($user->email);
    }
    sleep(1); // throttle to avoid rate limits
}
```

### Build a Lookup Table

```php
$collection = User::find()->get();

// Fetch users once, then look them up many times
$byId = $collection->indexBy('id');

foreach ($comments as $comment) {
    $user = $byId[$comment->user_id] ?? null;
    echo $user ? $user->name : 'Unknown';
}
```

### Aggregate by Group

```php
$collection = Order::find()->where('status', 'paid')->get();

// Total revenue per customer
$grouped = $collection->groupBy('customer_id');

foreach ($grouped as $customerId => $orders) {
    $total = array_sum(array_map(fn($order) => $order->total, $orders));
    echo "Customer $customerId: $total\n";
}
```

### Export to CSV

```php
$file = fopen('users.csv', 'w');
fputcsv($file, ['ID', 'Name', 'Email']);

$collection = User::find()->get();

// Memory-efficient: yields one row at a time
foreach ($collection->each(500) as $user) {
    fputcsv($file, [$user->id, $user->name, $user->email]);
}

fclose($file);
```

## Method Reference

| Method | Returns | Lazy? | Purpose |
|--------|---------|:-----:|---------|
| `count()` | `int` | ❌ | Total record count (DB query, cached) |
| `countBy(string $field)` | `int` | ❌ | Count by specific field |
| `isEmpty()` / `isNotEmpty()` | `bool` | ❌ | Emptiness check |
| `first()` | `mixed\|null` | ✅ | First record (or first matching after filter) |
| `all()` | `array` | ❌ | Eager-load all into array |
| `page(int $page, int $perPage)` | `array` | ❌ | Get a specific page (applies filter/map) |
| `chunk(int $size)` | `static` | ✅ | Set internal fetch chunk size |
| `each(int $size)` | `Generator` | ✅ | One-at-a-time with custom chunk |
| `batch(int $size)` | `Generator` | ✅ | Yield arrays of N records |
| `filter(callable)` | `static` | ✅ | Keep records where callback returns true |
| `map(callable)` | `static` | ✅ | Transform each record |
| `reduce(callable, $initial)` | `mixed` | ❌ | Combine all records into a single result |
| `indexBy(string\|callable)` | `array` | ❌ | Index by attribute or callback |
| `groupBy(string\|callable)` | `array` | ❌ | Group by attribute or callback |
| `pluck(string)` | `static` | ✅ | Extract single attribute |
| `toArray()` | `static` | ✅ | Yield raw arrays instead of models |
| `lazy(?int $size)` | `Generator` | ✅ | Manual lazy iterator with optional chunk size |
