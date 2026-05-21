# FLIQ Quick Reference

A compact cheatsheet for common operations.

## Custom Query Class (Recommended for Scopes)

```php
class UserQuery extends ActiveQuery {
    public function active(): self { return $this->where('status', 'active'); }
    public function admins(): self { return $this->where('role', 'admin'); }
}

class User extends Model {
    public static function find(): UserQuery {
        return new UserQuery(get_called_class());
    }
}

User::find()->active()->admins()->get();
```

## CRUD

| Task                 | Code                                            |
|----------------------|-------------------------------------------------|
| Find by ID           | `User::findByPk(1)`                             |
| Find with conditions | `User::find()->where('status', 1)->get()`       |
| Find or null         | `User::find()->where('email', $email)->first()` |
| Create               | `$user = new User([...]); $user->save();`       |
| Update               | `$user->name = 'new'; $user->save();`           |
| Delete               | `$user->delete()`                               |
| Exists check         | `User::find()->where('email', $e)->exists()`    |

## Query Builder

| Task            | Code                                                |
|-----------------|-----------------------------------------------------|
| Select columns  | `User::find()->select('id', 'name')->get()`         |
| Eager load      | `User::find()->with('posts.comments')->get()`       |
| Conditional     | `->when($filter, fn($query) => $query->where(...))` |
| Scope           | `->scope(fn($query) => $query->where('active', 1))` |
| Count           | `User::find()->where('status', 1)->count()`         |
| Paginate        | `User::find()->page(2, 25)->get()`                  |
| Cursor paginate | `User::find()->cursorPaginate(25, $cursor)`         |
| Pluck column    | `User::find()->pluck('email')`                      |
| Raw SQL         | `DB::raw('SELECT ...', [...])`                      |
| Upsert          | `DB::upsert('users', [...], ['email'])`             |
| Select raw      | `->selectRaw('COUNT(*) AS total')`                  |
| Order by raw    | `->orderByRaw('FIELD(status, 3, 1, 2)')`            |
| Order by desc   | `->orderByDesc('created_at')`                       |
| Group by raw    | `->groupByRaw('YEAR(created_at)')`                  |
| Having raw      | `->havingRaw('COUNT(*) > ?', [5])`                  |
| Where raw       | `->whereRaw('{salary} * 12 > ?', [100000])`         |
| Where column    | `->whereColumn('updated_at', '>', 'created_at')`    |
| Cursor          | `User::find()->cursor()`                            |
| Chunk by ID     | `->chunkById(100, fn($batch) => ...)`               |

## Conditions

| Task              | Code                                               |
|-------------------|----------------------------------------------------|
| Equal             | `->where('status', 'active')`                      |
| Comparison        | `->where('age', '>', 18)`                          |
| Between           | `->between('age', 18, 65)`                         |
| In                | `->in('status', ['active', 'pending'])`            |
| Like              | `->like('name', '%john%')`                         |
| Not null          | `->where('email', '!=', null)`                     |
| Multi-column any  | `->whereAny(['name', 'email'], 'like', '%john%')`  |
| Multi-column all  | `->whereAll(['title', 'body'], 'like', '%php%')`   |
| Multi-column none | `->whereNone(['title', 'body'], 'like', '%spam%')` |

## Date Filters

| Task         | Code                                                  |
|--------------|-------------------------------------------------------|
| By date      | `->whereDate('created_at', '=', '2024-01-05')`       |
| By month     | `->whereMonth('created_at', '=', 1)`                 |
| By year      | `->whereYear('created_at', '=', 2024)`               |
| By time      | `->whereTime('created_at', '>', '17:00:00')`         |
| Not null          | `->where('email', '!=', null)`                     |
| Multi-column any  | `->whereAny(['name', 'email'], 'like', '%john%')`  |
| Multi-column all  | `->whereAll(['title', 'body'], 'like', '%php%')`   |
| Multi-column none | `->whereNone(['title', 'body'], 'like', '%spam%')` |

## JSON Queries

| Task              | Code                                |
|-------------------|-------------------------------------|
| JSON where        | `->where('meta->age', '>', 25)`     |
| JSON contains     | `->jsonContains('tags', 'php')`     |
| JSON not contains | `->jsonNotContains('tags', 'java')` |
| JSON key exists   | `->jsonHas('meta->address')`        |
| JSON key missing  | `->jsonMissing('meta->foo')`        |
| JSON length       | `->jsonLength('tags', '>', 2)`      |

Aliases: `whereJsonContains`, `whereJsonDoesntContain`, `whereJsonContainsKey`, `whereJsonDoesntContainKey`, `whereJsonLength`

## Relations

| Task            | Code                                                 |
|-----------------|------------------------------------------------------|
| Has one         | `$this->hasOne(Profile::class, ['user_id' => 'id'])` |
| Has many        | `$this->hasMany(Post::class, ['user_id' => 'id'])`   |
| Relation filter | `->whereHas('posts', fn($query) => $query->where(...))`      |
| Doesn't have    | `->doesntHave('posts')`                              |

## Collection

| Task              | Code                                                         |
|-------------------|--------------------------------------------------------------|
| Iterate           | `foreach (User::find()->get() as $user) { ... }`             |
| Count             | `count(User::find()->get())`                                 |
| First             | `User::find()->get()->first()`                               |
| All as array      | `User::find()->get()->all()`                                 |
| Filter            | `->get()->filter(fn($user) => $user->active)`                      |
| Map               | `->get()->map(fn($user) => $user->name)`                           |
| Reduce            | `->get()->reduce(fn($carry, $user) => $carry + $user->score, 0)`           |
| Index by attribute  | `->get()->indexBy('email')`                                    |
| Group by          | `->get()->groupBy('role')`                                   |
| Pluck             | `->get()->pluck('email')`                                    |
| Page              | `->get()->page(2, 25)`                                       |
| Batch process     | `foreach ($coll->batch(500) as $batch) { ... }`              |
| Chunk size        | `->get()->chunk(50)`                                         |

## Aggregations

| Task    | Code                            |
|---------|---------------------------------|
| Count   | `User::find()->count()`         |
| Sum     | `Order::find()->sum('total')`   |
| Average | `Product::find()->avg('price')` |
| Min     | `Product::find()->min('price')` |
| Max     | `Product::find()->max('price')` |

## Transactions

```php
// Via Model
User::transaction(function () {
    $user = new User(['name' => 'John']);
    $user->save();

    $post = new Post(['user_id' => $user->id, 'title' => 'Hello']);
    $post->save();

    return true; // commit (return false or omit to roll back)
});
```

## Model Features

| Task            | Code                                                               |
|-----------------|--------------------------------------------------------------------|
| Soft delete     | `$user->delete()` (sets deleted_at)                                |
| Restore         | `$user->restore()`                                                 |
| Force delete    | `$user->forceDelete()`                                             |
| With trashed    | `User::withTrashed()->get()`                                       |
| Only trashed    | `User::onlyTrashed()->get()`                                       |
| Model events    | `User::on('creating', fn($user) => ...)`                              |
| Observer        | `User::observe(new AuditObserver())`                               |
| Global scope    | `User::addGlobalScope('active', fn($query) => $query->where('active', 1))` |
| Replicate       | `$clone = $user->replicate()`                                      |
| Increment       | `$post->increment('views')`                                        |
| Decrement       | `$post->decrement('stock', 5)`                                     |
| Refresh from DB | `$user->refresh()`                                                 |
| Find all        | `User::findAll(['status' => 1, 'role' => 'admin'])`                |

## Batch Operations

| Task         | Code                                                    |
|--------------|---------------------------------------------------------|
| Batch insert | `User::insertBatch($records, 500)`                      |
| Batch update | `User::updateBatch([['id' => 1, 'score' => 100], ...])` |

## Developer Tools

| Task               | Code                                         |
|--------------------|----------------------------------------------|
| N+1 detection      | `QueryMonitor::enable()`                     |
| Query logging      | `QueryLogger::enable()`                      |
| Get logged queries | `QueryLogger::getQueries()`                  |
| Slowest query      | `QueryLogger::getSlowestQuery()`             |
| Query cache        | `User::find()->where(...)->cache(60)->get()` |
| Index suggestions  | `IndexAdvisor::suggestSQL()`                 |

## Connection

| Task             | Code                                                                 |
|------------------|----------------------------------------------------------------------|
| Add connection   | `Connection::add('mysql', [...])`                                    |
| Read/write split | `Connection::add('mysql', [..., 'read' => [...], 'write' => [...]])` |
| Disconnect       | `Connection::disconnect('mysql')`                                    |
| Reconnect        | `Connection::reconnect('mysql')`                                     |
| Reset all        | `Connection::reset()`                                                |
| Read/write split | `Connection::add('mysql', [..., 'read' => [...], 'write' => [...]])` |
