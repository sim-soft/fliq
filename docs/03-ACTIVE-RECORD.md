# Active Record

## Table of Contents
- [Declare Model Class](#declare-model-class)
- [Find Records](#find-records)
- [CRUD](#crud)
- [Mass Assignment Protection](#mass-assignment-protection)
- [Composite Primary Keys](#composite-primary-keys)
- [Attribute Casting](#attribute-casting)
- [Lifecycle Hooks](#lifecycle-hooks)
- [Model Events & Observers](#model-events--observers)
- [Transactions](#transactions)
- [Serialization](#serialization)
- [Soft Deletes](#soft-deletes)
- [Timestamps](#timestamps)
- [Scenarios](#scenarios)
- [Scopes](#scopes)
- [Conditional Queries](#conditional-queries)

## Declare Model Class

```php
namespace Models;

use Simsoft\DB\Model;

class User extends Model
{
    protected string $connection = 'mysql';
    protected string $table = 'user';
    protected string|array $primaryKey = 'id';

    /** @var array Mass-assignable attributes */
    protected array $fillable = ['name', 'email', 'status'];

    /** @var array Attribute type casts */
    protected array $casts = [
        'status' => 'int',
    ];
}
```

## Find Records

Get user by primary key:

```php
use Models\User;

/* SELECT * FROM `user` WHERE `user`.`id` = ? LIMIT 1 */
$user = User::findByPk(123);

echo $user->first_name;
echo $user->last_name;
```

Get all users:

```php
/* SELECT * FROM `user` LIMIT 10 */
$users = User::find()->limit(10)->get();

foreach ($users as $user) {
    echo $user->first_name . ' ' . $user->last_name . PHP_EOL;
}
```

Get first matching record:

```php
/* SELECT * FROM `user` WHERE `user`.`status` = ? LIMIT 1 */
$user = User::find()->where('status', 1)->first();
```

Get all users with filters:

```php
$users = User::find()
    ->select('first_name', 'last_name', 'email')
    ->where('email', 'johndoe@email.com')
    ->not('email', 'xyz@email.com')
    ->where('age', '>', 20)
    ->orWhere('age', '<', 30)
    ->where(function ($query) {
        $query
            ->like('username', 'abc%')
            ->orLike('username', '%efg%')
            ->notLike('username', '%xyz');
    })
    ->orWhere(function ($query) {
        $query
            ->isNull('contact_number')
            ->orNotNull('mobile_number');
            /* Aliases also work: ->whereNull('contact_number')
               ->orWhereNotNull('mobile_number') */
    })
    ->in('country', ['MY', 'SG', 'ID'])
    ->orderBy('id', 'DESC')
    ->orderBy([
        'first_name' => 'ASC',
        'last_name' => 'DESC',
    ])
    ->get();

foreach ($users as $user) {
    echo $user->first_name . ' ' . $user->last_name . PHP_EOL;
}
```

Index results by a column:

```php
$users = User::find()
    ->select('first_name', 'last_name', 'email')
    ->where('age', '>', 20)
    ->indexBy('id')
    ->get();

foreach ($users as $pk => $user) {
    echo $pk . ' ' . $user->first_name . ' ' . $user->last_name . PHP_EOL;
}
```

## CRUD

### Create New Record

```php
$values = [
    'name' => 'John',
    'email' => 'johndoe@email.com',
];

// Using constructor
$user = new User($values);
$user->save(); // INSERT INTO `user` (`name`, `email`) VALUES (?, ?)

// Using fill (mass assignment)
$user = new User();
$user->fill($values);
$user->save();
```

### Update Record

```php
$user = User::findByPk(2);
$user->name = 'Jack';
$user->email = 'jack@email.com';
$user->save(); // UPDATE `user` SET `name` = ?, `email` = ? WHERE `user`.`id` = ?

// Using fill
$user->fill(['name' => 'Jack', 'email' => 'jack@email.com']);
$user->save();
```

### Delete Record

```php
$user = User::findByPk(2);
$user->delete(); // DELETE FROM `user` WHERE `user`.`id` = ?
```

### Update Counter

Atomically increment/decrement a column:

```php
$post = Post::findByPk(1);
$post->updateCounter('view_count', 1);   // +1
$post->updateCounter('stock', -5);       // -5
```

### Update Attributes (without dirty tracking)

```php
$user = User::findByPk(1);
$user->updateAttributes(['last_login' => date('Y-m-d H:i:s')]);
```

### Bulk Operations

```php
$user = new User();

/* Update all matching records */
$user->updateAll(['status' => 0], User::find()->where('last_login', '<', '2024-01-01'));

/* Delete all matching records */
$user->deleteAll(User::find()->where('status', 0));
```

### Refresh from Database

Reload the model's attributes from the database:

```php
$user = User::findByPk(1);
$user->name = 'temp';
$user->refresh(); // reloads from DB, discards unsaved changes
echo $user->name; // original value from DB
```

## Mass Assignment Protection

The `$fillable` array defines which attributes can be mass-assigned via `fill()`. The `$guarded` array defines attributes that cannot be mass-assigned (primary keys are always guarded).

```php
class User extends Model
{
    protected string $table = 'user';

    // Only these can be filled
    protected array $fillable = ['name', 'email', 'status'];

    // These are always protected (in addition to primary key)
    protected array $guarded = ['role', 'is_admin'];
}
```

## Composite Primary Keys

For tables with composite primary keys, define `$primaryKey` as an array:

```php
class OrderItem extends Model
{
    protected string $table = 'order_item';
    protected string|array $primaryKey = ['order_id', 'item_id'];
    protected array $fillable = ['quantity', 'price'];
}
```

Usage:

```php
// Find by composite key
$item = OrderItem::findByPk(['order_id' => 1, 'item_id' => 5]);

// Create
$item = new OrderItem([
    'order_id' => 1,
    'item_id' => 5,
    'quantity' => 3,
    'price' => 29.99,
]);
$item->save();

/* Update — generates WHERE order_id = ? AND item_id = ? */
$item->quantity = 10;
$item->save();

/* Delete — generates WHERE order_id = ? AND item_id = ? */
$item->delete();
```

Both key columns are automatically guarded (cannot be mass-assigned via `fill()`).

## Attribute Casting

Define `$casts` to automatically cast attribute values:

```php
class User extends Model
{
    protected string $table = 'user';

    protected array $casts = [
        'age' => 'int',
        'is_active' => 'bool',
        'salary' => 'float',
        'name' => 'string',
    ];
}
```

Supported cast types: `int`, `integer`, `bool`, `boolean`, `float`, `double`, `real`, `string`, `binary`, `array`.

## Lifecycle Hooks

Override these methods to hook into the model lifecycle:

```php
class User extends Model
{
    protected string $table = 'user';

    protected function init(): void
    {
        // Called after constructor
    }

    protected function afterFind(): void
    {
        /* Called after a record is loaded from DB */
    }

    protected function beforeSave(): void
    {
        /* Called before insert or update */
    }

    protected function afterSave(): void
    {
        /* Called after successful insert or update */
    }

    public function validate(): bool
    {
        // Return false to prevent save
        return true;
    }
}
```

## Model Events & Observers

Events let external code hook into model lifecycle moments without modifying the model class.

### Available Events

| Event | When | Can cancel? |
|-------|------|-------------|
| `saving` | Before INSERT or UPDATE | Yes (return false) |
| `creating` | Before INSERT | Yes |
| `updating` | Before UPDATE | Yes |
| `created` | After INSERT | No |
| `updated` | After UPDATE | No |
| `saved` | After INSERT or UPDATE | No |
| `deleting` | Before DELETE | Yes |
| `deleted` | After DELETE | No |

### Register Event Listeners

```php
// Listen to a specific event
User::on('creating', function (User $user) {
    $user->slug = strtolower($user->name);
});

// Cancel an operation by returning false
User::on('deleting', function (User $user) {
    if ($user->role === 'admin') {
        return false; // prevents deletion
    }
});

// Log after save
User::on('saved', function (User $user) {
    AuditLog::record('user_saved', $user->id);
});
```

### Observer Class

Group all event handlers for a model into one class:

```php
class UserObserver
{
    public function creating(User $user): void
    {
        $user->slug = strtolower($user->name);
    }

    public function created(User $user): void
    {
        EmailService::sendWelcome($user->email);
    }

    public function deleting(User $user): bool|null
    {
        if ($user->hasActiveSubscription()) {
            return false; // cancel
        }
        return null;
    }

    public function deleted(User $user): void
    {
        Cache::forget("user:{$user->id}");
    }
}

// Register the observer (typically in bootstrap/app setup)
User::observe(new UserObserver());
```

### Execution Order

On `save()` (new model):
1. `saving` → can cancel
2. `creating` → can cancel
3. `beforeSave()` (internal hook)
4. INSERT executes
5. `afterSave()` (internal hook)
6. `created` fires
7. `saved` fires

On `save()` (existing model):
1. `saving` → can cancel
2. `updating` → can cancel
3. `beforeSave()` (internal hook)
4. UPDATE executes
5. `afterSave()` (internal hook)
6. `updated` fires
7. `saved` fires

### Remove Listeners

```php
/* Remove all listeners for a model */
User::flushEvents();
```

### Events vs Lifecycle Hooks

| | `beforeSave()`/`afterSave()` | Events/Observers |
|---|---|---|
| Where | Inside the model class | External (any file) |
| Use case | Core model logic | Side effects (logging, notifications) |
| Can cancel | No | Yes (before events) |

Use hooks for model internals. Use events for app-level concerns.

### Methods That Do NOT Fire Events

> **Warning:** The following methods bypass the model lifecycle entirely. No events, hooks, or dirty tracking will run.

| Method | What it does | Skips |
|--------|-------------|-------|
| `updateAttributes([...])` | Direct UPDATE on a single record | Events, hooks, dirty tracking, validation |
| `updateAll([...], $query)` | Bulk UPDATE on multiple records | Events, hooks, dirty tracking, validation |
| `updateCounter('col', 1)` | Atomic increment/decrement | Events, hooks, dirty tracking, validation |
| `deleteAll($condition)` | Bulk DELETE | Events, hooks |
| `insertBatch([...])` | Bulk INSERT | Events, hooks, dirty tracking, validation |
| `updateBatch([...])` | Bulk CASE WHEN UPDATE | Events, hooks, dirty tracking, validation |

Only `save()` and `delete()` fire events. If you need events on bulk operations, iterate and call `save()`/`delete()` on each model individually (at the cost of performance).

## Transactions

```php
use Models\User;

User::transaction(function () {
    $user = User::findByPk(1);
    $user->status = 0;
    $user->save();

    $user2 = User::findByPk(2);
    $user2->status = 1;
    $user2->save();

    return true; // commit
    // return false; // rollback
});
```

## Serialization

Convert models to arrays or JSON for API responses:

```php
$user = User::findByPk(1);

// All attributes
$array = $user->toArray();

// Specific fields only
$array = $user->toArray(['id', 'name', 'email']);

// JSON
$json = $user->toJson();
$json = $user->toJson(JSON_PRETTY_PRINT);
```

When relations are loaded (via eager loading or lazy access), they're included in the output:

```php
$user = User::find()->with('posts', 'profile')->first();
$array = $user->toArray();
// ['id' => 1, 'name' => 'John', 'profile' => [...], 'posts' => [[...], [...]]]
```

## Soft Deletes

Add the `SoftDeletes` trait to mark records as deleted without removing them from the database. Requires a `deleted_at` column (nullable datetime/timestamp).

```php
use Simsoft\DB\Model;
use Simsoft\DB\Traits\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected string $table = 'user';
}
```

Usage:

```php
$user = User::findByPk(1);

$user->delete();        // sets deleted_at = current timestamp
$user->trashed();       // true
$user->restore();       // sets deleted_at = NULL
$user->forceDelete();   // permanently removes from database

/* Queries auto-exclude soft-deleted records */
$users = User::find()->get(); // only non-deleted users

/* Include soft-deleted records */
$all = User::withTrashed()->get();

/* Only soft-deleted records */
$trashed = User::onlyTrashed()->get();
```

Override the column name if needed:

```php
class User extends Model
{
    use SoftDeletes;

    public function getDeletedAtColumn(): string
    {
        return 'removed_at';
    }
}
```

## Timestamps

Add the `Timestamps` trait to automatically set `created_at` and `updated_at` columns on save.

```php
use Simsoft\DB\Model;
use Simsoft\DB\Traits\Timestamps;

class Post extends Model
{
    use Timestamps;

    protected string $table = 'post';
    protected array $fillable = ['title', 'content'];
}
```

Usage:

```php
$post = new Post();
$post->title = 'Hello';
$post->save();
// created_at = '2025-05-11 10:30:00'
/* updated_at = '2025-05-11 10:30:00' */

$post->title = 'Updated';
$post->save();
/* updated_at = '2025-05-11 10:35:00' (auto-updated) */
// created_at unchanged
```

Override column names or disable one:

```php
class Post extends Model
{
    use Timestamps;

    public function getCreatedAtColumn(): ?string
    {
        return 'date_created'; // custom name
    }

    public function getUpdatedAtColumn(): ?string
    {
        return null; // disable updated_at
    }
}
```

## Scenarios

The `Scenario` trait lets a model behave differently based on context — useful when the same model needs different validation rules, fillable attributes, or save logic for create vs update vs admin operations.

```php
use Simsoft\DB\Model;
use Simsoft\DB\Traits\Scenario;

class User extends Model
{
    use Scenario;

    public const SCENARIO_REGISTER = 'register';
    public const SCENARIO_PROFILE_UPDATE = 'profile_update';
    public const SCENARIO_ADMIN = 'admin';

    protected string $table = 'user';

    public function validate(): bool
    {
        return match ($this->getScenario()) {
            self::SCENARIO_REGISTER => $this->validateRegistration(),
            self::SCENARIO_PROFILE_UPDATE => $this->validateProfile(),
            self::SCENARIO_ADMIN => $this->validateAdminFields(),
            default => true,
        };
    }

    protected function beforeSave(): void
    {
        /* Hash password only when registering or admin sets it */
        if ($this->isAnyScenario(self::SCENARIO_REGISTER, self::SCENARIO_ADMIN)) {
            if (isset($this->dirtyAttributes['password'])) {
                $this->password = password_hash($this->password, PASSWORD_BCRYPT);
            }
        }
    }

    private function validateRegistration(): bool { /* email + password required */ return true; }
    private function validateProfile(): bool { /* only profile fields */ return true; }
    private function validateAdminFields(): bool { /* role + status required */ return true; }
}
```

### Usage

```php
// Registration flow (chainable)
$user = (new User($_POST))->withScenario(User::SCENARIO_REGISTER);
$user->save(); // runs validateRegistration() + hashes password

/* Profile self-update */
$user = User::findByPk($currentUserId)
    ->withScenario(User::SCENARIO_PROFILE_UPDATE);
$user->fill($_POST);
$user->save(); // runs validateProfile()

/* Admin update (allows role/status changes) */
$user = User::findByPk(5)->withScenario(User::SCENARIO_ADMIN);
$user->fill($_POST);
$user->save(); // runs validateAdminFields()

// Clear scenario after operation
$user->withScenario(null);
```

### Combining with Mass Assignment

Override `fill()` to make `$fillable` scenario-aware:

```php
class User extends Model
{
    use Scenario;

    protected array $fillable = ['name', 'email']; // default

    public function fill(array $attributes): static
    {
        $this->fillable = match ($this->getScenario()) {
            self::SCENARIO_REGISTER => ['name', 'email', 'password'],
            self::SCENARIO_ADMIN => ['name', 'email', 'role', 'status', 'password'],
            self::SCENARIO_PROFILE_UPDATE => ['name', 'avatar', 'bio'],
            default => $this->fillable,
        };
        return parent::fill($attributes);
    }
}
```

### API Summary

| Method | Returns | Purpose |
|--------|---------|---------|
| `withScenario(int\|string\|null $name)` | `static` | Set or clear the active scenario (chainable) |
| `getScenario()` | `int\|string\|null` | Get the current scenario |
| `hasScenario()` | `bool` | Check if any scenario is active |
| `isScenario(int\|string $name)` | `bool` | Check if a specific scenario is active (strict comparison) |
| `isAnyScenario(int\|string ...$names)` | `bool` | Check if the current scenario matches any of the given scenarios |

## Scopes

Define reusable query fragments by extending `ActiveQuery` with custom methods. This is the recommended pattern — it gives you full IDE autocomplete and type safety:

```php
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Model;

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

    public function recentSignups(int $days = 30): self
    {
        return $this->where('created_at', '>', date('Y-m-d', strtotime("-$days days")));
    }

    public function inCountry(string $code): self
    {
        return $this->where('country', $code);
    }
}

class User extends Model
{
    protected string $table = 'user';

    public static function find(): UserQuery
    {
        return new UserQuery(get_called_class());
    }
}
```

Usage — chain scope methods like any other query builder method:

```php
/* SELECT `user`.* FROM `user`
   WHERE `user`.`status` = ? AND `user`.`role` = ?
   AND `user`.`created_at` > ? AND `user`.`country` = ?
   ORDER BY `user`.`name` ASC */
$users = User::find()
    ->active()
    ->admins()
    ->recentSignups(7)
    ->inCountry('MY')
    ->orderBy('name')
    ->get();
```

### Inline Scopes (Alternative)

For one-off scope logic without creating a custom query class, use `scope()`:

```php
$users = User::find()
    ->scope(fn($query) => $query->where('status', 'active'))
    ->scope(fn($query) => $query->where('role', 'admin'))
    ->get();
```

Or define reusable closures:

```php
$active = fn($query) => $query->where('status', 'active');
$verified = fn($query) => $query->whereNotNull('verified_at');

$users = User::find()
    ->scope($active)
    ->scope($verified)
    ->get();
```

### When to Use Which

| Use case | Recommended approach |
|----------|---------------------|
| Reusable named scopes for a model | Custom `ActiveQuery` subclass |
| One-off conditional logic | `->scope(fn($query) => ...)` |
| Always-applied conditions | [Global scopes](05-ADVANCED-FEATURES.md#global-scopes) |

## Conditional Queries

Use `when()` to apply conditions only when a value is truthy. Ideal for optional filters from user input:

```php
$search = $request['search'] ?? null;
$role = $request['role'] ?? null;
$country = $request['country'] ?? null;

$users = User::find()
    ->select('first_name', 'last_name', 'email')
    ->when($search !== null, function ($query) use ($search) {
        $query->like('name', "%$search%");
    })
    ->when($role !== null, function ($query) use ($role) {
        $query->where('role', $role);
    })
    ->when($country !== null, fn($query) => $query->where('country', $country))
    ->orderBy('name')
    ->get();
```

When the condition is `false`, the callback is skipped — no conditions are added to the query.

This replaces verbose if-statement patterns:

```php
// Before (verbose)
$query = User::find();
if ($search) {
    $query->like('name', "%$search%");
}
if ($role) {
    $query->where('role', $role);
}
$users = $query->get();

// After (clean)
$users = User::find()
    ->when((bool)$search, fn($query) => $query->like('name', "%$search%"))
    ->when((bool)$role, fn($query) => $query->where('role', $role))
    ->get();
```

### `when()` with Default (Else Branch)

Pass a third argument to handle the false case:

```php
$sortBy = $request['sort'] ?? null;

$users = User::find()
    ->when(
        $sortBy !== null,
        fn($query) => $query->orderBy($sortBy),
        fn($query) => $query->orderBy('created_at', 'DESC')  // default
    )
    ->get();
```

### `unless()` — Apply When Condition is False

```php
$isAdmin = $currentUser->role === 'admin';

// Non-admins only see their own records
$users = User::find()
    ->unless($isAdmin, fn($query) => $query->where('owner_id', $currentUser->id))
    ->get();
```

### `tap()` — Debug Without Breaking the Chain

```php
$users = User::find()
    ->where('status', 'active')
    ->tap(fn($query) => error_log('SQL: ' . $query->getFullSQL()))
    ->orderBy('name')
    ->get();
```

### Method Aliases

For users coming from Eloquent, these aliases are available:

| Alias | Delegates to |
|-------|-------------|
| `whereNot()` | `not()` |
| `orWhereNot()` | `orNot()` |
| `whereNull()` | `isNull()` |
| `orWhereNull()` | `orIsNull()` |
| `whereNotNull()` | `notNull()` |
| `orWhereNotNull()` | `orNotNull()` |
| `whereIn()` | `in()` |
| `orWhereIn()` | `orIn()` |
| `whereNotIn()` | `notIn()` |
| `orWhereNotIn()` | `orNotIn()` |