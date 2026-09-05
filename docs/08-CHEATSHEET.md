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

### Rules worth knowing

| Rule                                                                      | If broken                    |
|---------------------------------------------------------------------------|------------------------------|
| Operators must be on the [whitelist](02-QUERY-BUILDER.md#which-operators-can-i-use) (`=`, `LIKE`, `IN`, `BETWEEN`, …) | `InvalidArgumentException`   |
| `limit()` / `offset()` must not be negative                               | `InvalidArgumentException`   |
| `page()` starts at `1`, not `0`                                           | `InvalidArgumentException`   |
| Sort direction is `ASC` or `DESC`                                         | Silently falls back to `ASC` |

Clamp anything that comes from a URL: `$page = max(1, (int)($_GET['page'] ?? 1));`

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

## Array Columns

| Task                 | Code                                             |
|----------------------|--------------------------------------------------|
| Array contains       | `->arrayContains('tags', 'php')`                 |
| Array contains (int) | `->arrayContains('role_ids', 5, 'int')`          |
| Array overlaps       | `->arrayOverlaps('tags', ['php', 'go'])`         |
| Or array contains    | `->orArrayContains('tags', 'python')`            |
| Or array overlaps    | `->orArrayOverlaps('skills', ['docker', 'k8s'])` |

Aliases: `whereArrayContains`, `whereArrayOverlaps`, `orWhereArrayContains`,
`orWhereArrayOverlaps`

## CASE WHEN Expressions

| Task              | Code                                                                     |
|-------------------|--------------------------------------------------------------------------|
| Value comparison  | `CaseExpression::when('score', '>', 90)->then('A')`                      |
| Multiple WHENs    | `->andWhen('score', '>', 70)->then('B')`                                 |
| Column comparison | `CaseExpression::whenColumn('score', '>', 'min_score')->then('pass')`    |
| Raw condition     | `CaseExpression::whenRaw('age BETWEEN ? AND ?', [18,30])->then('young')` |
| ELSE value        | `->else('F')`                                                            |
| Alias (SELECT)    | `->as('grade')`                                                          |
| In select()       | `User::find()->select('name', CaseExpression::when(...)->as('grade'))`   |
| In orderByRaw     | `->orderByRaw((string) CaseExpression::when(...)->then(1)->else(2))`     |

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
| `Model::requireAssignmentRules()`      | Reject models declaring neither (bootstrap)  |
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
| Log retention      | `QueryLogger::setLimit(1000)` (`0` = unlimited)        |
| SQL in exceptions  | `QueryException::enableDebug()` (dev only)             |
| Query cache        | `User::find()->where(...)->cache(60)->get()`           |
| Index suggestions  | `IndexAdvisor::suggestSQL()`                           |
| Dump and die       | `->dd()`                                               |
| Dump (continue)    | `->dump()`                                             |
| Tap (inspect)      | `->tap(fn($query) => error_log($query->getFullSQL()))` |
| Explain            | `->explain()`                                          |
| Explain analyze    | `->explain(analyze: true)`                             |
| Explain JSON       | `->explain(format: 'json')`                            |

## Row-Level Locking

| Task                   | Code                      |
|------------------------|---------------------------|
| FOR UPDATE             | `->forUpdate()`           |
| FOR SHARE              | `->forShare()`            |
| FOR UPDATE NOWAIT      | `->forUpdateNoWait()`     |
| FOR UPDATE SKIP LOCKED | `->forUpdateSkipLocked()` |

## Full-Text Search

| Task                | Code                                                       |
|---------------------|------------------------------------------------------------|
| MySQL MATCH AGAINST | `MatchAgainst(['title'])->mustHave(['PHP'])`               |
| PG plain search     | `->whereFulltext(['title', 'body'], 'database')`           |
| PG phrase search    | `->whereFulltext('title', 'query builder', 'phrase')`      |
| PG websearch        | `->whereFulltext('body', '"exact" -exclude', 'websearch')` |
| Or fulltext         | `->orWhereFulltext('body', 'optimization')`                |

## RETURNING (PostgreSQL)

| Task              | Code                                         |
|-------------------|----------------------------------------------|
| Insert returning  | `(new Insert(...))->returning('id')`         |
| Update returning  | `(new Update(...))->returning('id', 'name')` |
| Delete returning  | `(new Delete(...))->returning('id')`         |
| Get returned rows | `$builder->getReturningResult()`             |

## Advisory Locks (PostgreSQL)

| Task                        | Code                                      |
|-----------------------------|-------------------------------------------|
| Session lock (blocking)     | `$driver->advisoryLock(12345)`            |
| Session lock (try)          | `$driver->advisoryLockTry(12345)`         |
| Session unlock              | `$driver->advisoryUnlock(12345)`          |
| Transaction lock (blocking) | `$driver->advisoryLockTransaction(99)`    |
| Transaction lock (try)      | `$driver->advisoryLockTransactionTry(99)` |

## LISTEN / NOTIFY (PostgreSQL)

| Task              | Code                                        |
|-------------------|---------------------------------------------|
| Subscribe         | `$driver->listen('channel_name')`           |
| Unsubscribe       | `$driver->unlisten('channel_name')`         |
| Send notification | `$driver->notify('channel_name', $payload)` |
| Poll for message  | `$driver->getNotification(1000)`            |

## Connection

| Task             | Code                                                                 |
|------------------|----------------------------------------------------------------------|
| Add connection   | `Connection::add('mysql', [...])`                                    |
| Read/write split | `Connection::add('mysql', [..., 'read' => [...], 'write' => [...]])` |
| Disconnect       | `Connection::disconnect('mysql')`                                    |
| Reconnect        | `Connection::reconnect('mysql')`                                     |
| Reset all        | `Connection::reset()`                                                |

## Code Generators (CLI)

| Task                   | Command                                                          |
|------------------------|------------------------------------------------------------------|
| Generate one model     | `vendor/bin/fliq make:model User --config=config/db.php`         |
| Generate all models    | `vendor/bin/fliq make:model --all --config=config/db.php`        |
| Custom class name      | `vendor/bin/fliq make:model Profile --table=user_profile`        |
| Exclude tables         | `vendor/bin/fliq make:model --all --exclude=migrations,sessions` |
| Dry run                | `vendor/bin/fliq make:model --all --dry-run`                     |
| Preview code           | `vendor/bin/fliq make:model User --preview`                      |
| Force overwrite        | `vendor/bin/fliq make:model User --force`                        |
| Generate one observer  | `vendor/bin/fliq make:observer User`                             |
| Generate all observers | `vendor/bin/fliq make:observer --all`                            |
| Specific events only   | `vendor/bin/fliq make:observer Order --events=creating,deleting` |
| Programmatic model     | `ModelGenerator::fromTable('user')->generate()`                  |
| Programmatic observer  | `ObserverGenerator::forModel('User')->generate()`                |
| List all tables        | `ModelGenerator::listTables('mysql')`                            |
