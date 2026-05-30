# FLIQ Quick Reference

A compact cheatsheet for common operations.

## Custom Query Class (Recommended for Scopes)

```php
class UserQuery extends ActiveQuery
{
    public function active(): self
    {
        return $this->where('status', 'active');
    }

    public function admins(): self
    {
        return $this->where('role', 'admin');
    }
}

class User extends Model
{
    public static function find(): UserQuery
    {
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

| Task     | Code                                           |
|----------|------------------------------------------------|
| By date  | `->whereDate('created_at', '=', '2024-01-05')` |
| By month | `->whereMonth('created_at', '=', 1)`           |
| By year  | `->whereYear('created_at', '=', 2024)`         |
| By time  | `->whereTime('created_at', '>', '17:00:00')`   |

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

| Task            | Code                                                    |
|-----------------|---------------------------------------------------------|
| Has one         | `$this->hasOne(Profile::class, ['user_id' => 'id'])`    |
| Has many        | `$this->hasMany(Post::class, ['user_id' => 'id'])`      |
| Relation filter | `->whereHas('posts', fn($query) => $query->where(...))` |
| Doesn't have    | `->doesntHave('posts')`                                 |

## Collection

| Task               | Code                                                             |
|--------------------|------------------------------------------------------------------|
| Iterate            | `foreach (User::find()->get() as $user) { ... }`                 |
| Count              | `count(User::find()->get())`                                     |
| First              | `User::find()->get()->first()`                                   |
| All as array       | `User::find()->get()->all()`                                     |
| Filter             | `->get()->filter(fn($user) => $user->active)`                    |
| Map                | `->get()->map(fn($user) => $user->name)`                         |
| Reduce             | `->get()->reduce(fn($carry, $user) => $carry + $user->score, 0)` |
| Index by attribute | `->get()->indexBy('email')`                                      |
| Group by           | `->get()->groupBy('role')`                                       |
| Pluck              | `->get()->pluck('email')`                                        |
| Page               | `->get()->page(2, 25)`                                           |
| Batch process      | `foreach ($coll->batch(500) as $batch) { ... }`                  |
| Chunk size         | `->get()->chunk(50)`                                             |

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

### Instance Methods

| Method                                 | Description                                  |
|----------------------------------------|----------------------------------------------|
| `$model->isNew()`                      | True if never saved (will INSERT)            |
| `$model->exists()`                     | True if loaded from DB (will UPDATE)         |
| `$model->wasRecentlyCreated()`         | True if just created in this request         |
| `$model->isDirty()`                    | True if has unsaved changes                  |
| `$model->isDirty('email')`             | True if specific attribute changed           |
| `$model->isDirty('name', 'email')`     | True if any of them changed                  |
| `$model->getDirtyAttributes()`         | List of changed attribute names              |
| `$model->getAttributes()`              | Get all attributes as array                  |
| `$model->fill([...])`                  | Mass assign (respects guarded/fillable)      |
| `$model->only(['name', 'email'])`      | Get only specified attributes                |
| `$model->except(['password'])`         | Get all except specified attributes          |
| `$model->toArray()`                    | Convert to array (includes loaded relations) |
| `$model->toJson()`                     | Convert to JSON string                       |
| `$model->getTable()`                   | Get table name                               |
| `$model->getConnectionName()`          | Get connection name                          |
| `$model->getPrimaryKeyFields()`        | Get PK field name(s)                         |
| `$model->getKey()`                     | Get PK value                                 |
| `$model->validate()`                   | Run validation (override in subclass)        |
| `$model->save()`                       | Insert or update (runs validate + events)    |
| `$model->save(validate: false)`        | Save without validation                      |
| `$model->insert()`                     | Force INSERT                                 |
| `$model->update([...])`                | Force UPDATE with optional extra attributes  |
| `$model->updateAttributes([...])`      | Update specific columns directly             |
| `$model->updateAll([...], $query)`     | Update all matching records                  |
| `$model->updateCounter('views', 1)`    | Atomic increment/decrement                   |
| `$model->increment('views')`           | Atomic increment                             |
| `$model->decrement('stock', 5)`        | Atomic decrement                             |
| `$model->delete()`                     | Delete record                                |
| `$model->deleteAll($condition)`        | Delete all matching condition                |
| `$model->refresh()`                    | Reload from database                         |
| `$model->replicate()`                  | Clone as new unsaved instance                |
| `$model->hasOne(Model::class, [...])`  | Define has-one relation                      |
| `$model->hasMany(Model::class, [...])` | Define has-many relation                     |
| `$model->setRelation('name', $val)`    | Inject preloaded relation                    |
| `$model->relationLoaded('name')`       | Check if relation is loaded                  |
| `$model->saveTogether([...])`          | Save model + nested relations in transaction |

### Static Methods

| Method                                         | Description                                  |
|------------------------------------------------|----------------------------------------------|
| `Model::find()`                                | New query builder (applies global scopes)    |
| `Model::findByPk(1)`                           | Find by primary key                          |
| `Model::findAll(['status' => 1])`              | Find all matching conditions                 |
| `Model::hydrate($row)`                         | Create from DB row (fast, no dirty tracking) |
| `Model::insertBatch($records, 500)`            | Bulk insert in chunks                        |
| `Model::updateBatch($updates, 'id')`           | Bulk update with CASE WHEN                   |
| `Model::transaction(fn() => ...)`              | Execute in transaction                       |
| `Model::addGlobalScope('name', fn($q) => ...)` | Register global scope                        |
| `Model::removeGlobalScope('name')`             | Remove global scope                          |
| `Model::withoutGlobalScopes()`                 | Query without any scopes                     |
| `Model::withoutGlobalScope('name')`            | Query without specific scope                 |
| `Model::on('creating', fn($m) => ...)`         | Register event listener                      |
| `Model::observe($observer)`                    | Register observer                            |
| `Model::flushEvents()`                         | Remove all event listeners                   |

### Error Trait (included by default)

| Method                                              | Description           |
|-----------------------------------------------------|-----------------------|
| `$model->addError('msg')`                           | Add single error      |
| `$model->addErrors(['msg1', ...])`                  | Add multiple errors   |
| `$model->addValidationErrors($validator->errors())` | Import from Validator |
| `$model->getErrors()`                               | Get all errors        |
| `$model->hasError()`                                | True if errors exist  |
| `$model->noError()`                                 | True if no errors     |

### Scenario Trait (opt-in: `use Scenario`)

| Method                             | Description                 |
|------------------------------------|-----------------------------|
| `$model->withScenario('register')` | Set active scenario         |
| `$model->getScenario()`            | Get current scenario        |
| `$model->isScenario('register')`   | Check if matches (strict)   |
| `$model->hasScenario()`            | True if any scenario is set |
| `$model->isAnyScenario('a', 'b')`  | True if matches any         |

### SoftDeletes Trait (opt-in: `use SoftDeletes`)

| Method                  | Description                   |
|-------------------------|-------------------------------|
| `$model->delete()`      | Soft delete (sets deleted_at) |
| `$model->restore()`     | Restore soft-deleted record   |
| `$model->forceDelete()` | Permanently delete            |
| `$model->trashed()`     | True if soft-deleted          |
| `Model::withTrashed()`  | Query including deleted       |
| `Model::onlyTrashed()`  | Query only deleted            |

### Timestamps Trait (opt-in: `use Timestamps`)

| Method                         | Description                         |
|--------------------------------|-------------------------------------|
| `$model->getCreatedAtColumn()` | Column name (default: `created_at`) |
| `$model->getUpdatedAtColumn()` | Column name (default: `updated_at`) |

## Batch Operations

| Task         | Code                                                    |
|--------------|---------------------------------------------------------|
| Batch insert | `User::insertBatch($records, 500)`                      |
| Batch update | `User::updateBatch([['id' => 1, 'score' => 100], ...])` |

## Developer Tools

| Task               | Code                                                   |
|--------------------|--------------------------------------------------------|
| N+1 detection      | `QueryMonitor::enable()`                               |
| Query logging      | `QueryLogger::enable()`                                |
| Get logged queries | `QueryLogger::getQueries()`                            |
| Slowest query      | `QueryLogger::getSlowestQuery()`                       |
| Query cache        | `User::find()->where(...)->cache(60)->get()`           |
| Index suggestions  | `IndexAdvisor::suggestSQL()`                           |
| Dump and die       | `->dd()`                                               |
| Dump (continue)    | `->dump()`                                             |
| Tap (inspect)      | `->tap(fn($query) => error_log($query->getFullSQL()))` |

## Connection

| Task             | Code                                                                 |
|------------------|----------------------------------------------------------------------|
| Add connection   | `Connection::add('mysql', [...])`                                    |
| Read/write split | `Connection::add('mysql', [..., 'read' => [...], 'write' => [...]])` |
| Disconnect       | `Connection::disconnect('mysql')`                                    |
| Reconnect        | `Connection::reconnect('mysql')`                                     |
| Reset all        | `Connection::reset()`                                                |
