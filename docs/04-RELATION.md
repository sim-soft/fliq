# Active Record Relations

## Table of Contents
- [Declare Relations](#declare-relations)
- [Accessing Related Records](#accessing-related-records)
- [Filtering Related Records](#filtering-related-records)
- [Relation Types](#relation-types)
- [Eager Loading](#eager-loading)
- [Filtering by Relations](#filtering-by-relations)
- [Key Mapping Explained](#key-mapping-explained)
- [Saving Related Records](#saving-related-records)
  - [save()](#save--createupdate-a-related-model)
  - [saveMany()](#savemany--createupdate-multiple-related-models)
  - [saveTogether()](#savetogether--save-model--all-relations-in-one-call)
  - [attach() / detach() / sync()](#attach--add-pivot-table-entries-mn-only)

## Declare Relations

Relations are defined as methods on the model. Only two methods are needed: `hasOne()` and `hasMany()`. Many-to-many is handled by chaining `viaTable()`.

```php
namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

class User extends Model
{
    protected string $table = 'user';
    protected string|array $primaryKey = 'id';

    /**
     * User has one profile.
     * profile.user_id → user.id
     */
    public function profile(): Relation
    {
        return $this->hasOne(Profile::class, ['user_id' => 'id']);
    }

    /**
     * User has many posts.
     * post.user_id → user.id
     */
    public function posts(): Relation
    {
        return $this->hasMany(Post::class, ['user_id' => 'id']);
    }

    /**
     * User has many roles (many-to-many via junction table).
     * user_roles.user_id → user.id / user_roles.role_id → role.id
     */
    public function roles(): Relation
    {
        return $this->hasMany(Role::class, ['role_id' => 'id'])
            ->viaTable('user_roles', ['user_id' => 'id']);
    }
}

class Post extends Model
{
    protected string $table = 'post';

    /**
     * Post belongs to a user (inverse of hasMany).
     * Just use hasOne with the keys pointing from child → parent.
     */
    public function author(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Post has many comments.
     */
    public function comments(): Relation
    {
        return $this->hasMany(Comment::class, ['post_id' => 'id']);
    }

    /**
     * Post has many tags via junction table.
     * post_tag.post_id → post.id / post_tag.tag_id → tag.id
     */
    public function tags(): Relation
    {
        return $this->hasMany(Tag::class, ['tag_id' => 'id'])
            ->viaTable('post_tag', ['post_id' => 'id']);
    }
}
```

## Accessing Related Records

Related records are loaded lazily when accessed as properties:

```php
$user = User::findByPk(1);

// hasOne: returns a single Model or null
echo $user->profile->title;

// hasMany: returns a Collection
foreach ($user->posts as $post) {
    echo $post->title;
}

// Inverse (belongsTo pattern):
$post = Post::findByPk(1);
echo $post->author->name; // loads the User
```

## Filtering Related Records

Call the relation method directly to get the `Relation` object, then chain conditions:

```php
$user = User::findByPk(1);

// Filter with additional conditions
$recentPosts = $user->posts()
    ->where('status', 'active')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->fetch();
```

## Relation Types

### hasOne — One-to-One

```php
// User has one Profile (profile.user_id → user.id)
$this->hasOne(Profile::class, ['user_id' => 'id']);
```

### hasMany — One-to-Many

```php
// User has many Posts (post.user_id → user.id)
$this->hasMany(Post::class, ['user_id' => 'id']);
```

### Inverse (belongsTo pattern)

No special method needed. Just use `hasOne()` with the keys pointing from the child's foreign key to the parent's primary key:

```php
// Post belongs to User (user.id ← post.user_id)
$this->hasOne(User::class, ['id' => 'user_id']);
```

### Many-to-Many via Junction Table (viaTable)

Use `hasMany()->viaTable()` for many-to-many through a pivot table:

```php
// User has many Roles via user_roles pivot table
// user_roles.user_id → user.id / user_roles.role_id → role.id
$this->hasMany(Role::class, ['role_id' => 'id'])
    ->viaTable('user_roles', ['user_id' => 'id']);
```

The `hasMany()` array specifies how the pivot table connects to the **related** model:
- key = pivot column referencing the related model (e.g., `role_id`)
- value = related model's primary key (e.g., `id` on `role`)

The `viaTable()` parameters:
1. Junction table name (e.g., `'user_roles'`)
2. Array specifying how the pivot connects to **this** (parent) model:
   - key = pivot column referencing this model (e.g., `user_id`)
   - value = this model's primary key (e.g., `id` on `user`)

### Has-Through (Distant Relations)

For distant relations like "user has many comments through posts", use eager loading with dot notation instead:

```php
// Get comments via the posts relation
$user = User::find()->with('posts.comments')->findByPk(1);

foreach ($user->posts as $post) {
    foreach ($post->comments as $comment) {
        echo $comment->body;
    }
}
```

The `via()` method exists on the `Relation` class but is not yet wired up for direct access — use eager loading with `with('posts.comments')` for the same result.

## Eager Loading

Use `with()` to batch-load relations and prevent N+1 queries:

```php
// 3 queries total instead of 1 + N
$users = User::find()
    ->with('posts', 'profile')
    ->where('status', 'active')
    ->get();

foreach ($users as $user) {
    echo $user->profile->title;  // no extra query
    foreach ($user->posts as $post) {
        echo $post->title;       // no extra query
    }
}
```

Works with `first()` too:

```php
$user = User::find()->with('posts')->first();
```

### Nested Eager Loading

Use dot notation to load nested relations:

```php
// Loads: users → posts → comments → author
// 4 queries total (one per level), regardless of record count
$users = User::find()
    ->with('posts.comments.author', 'profile')
    ->get();

foreach ($users as $user) {
    foreach ($user->posts as $post) {
        foreach ($post->comments as $comment) {
            echo $comment->author->name; // no extra query at any level
        }
    }
}
```

Shorthand: `'posts.comments'` automatically loads `posts` first, then `comments` on those posts. You don't need to specify both `'posts'` and `'posts.comments'` separately.

```php
// These are equivalent:
User::find()->with('posts', 'posts.comments')->get();
User::find()->with('posts.comments')->get(); // 'posts' is loaded implicitly
```

### Constrained Eager Loading

Filter or sort the eager-loaded relations using a callback:

```php
// Load only active posts, ordered by date
$users = User::find()
    ->with(['posts' => fn($query) => $query->where('status', 'active')->orderBy('created_at', 'DESC')])
    ->get();

// Multiple constrained relations
$users = User::find()
    ->with([
        'posts' => fn($query) => $query->where('published', true)->limit(5),
        'profile' => fn($query) => $query->select('user_id', 'avatar', 'bio'),
    ])
    ->get();

// Mix constrained and simple
$users = User::find()
    ->with(['posts' => fn($query) => $query->where('status', 'active')], 'profile')
    ->get();
```

The callback receives the `ActiveQuery` for the related model — use any query builder method.

## Filtering by Relations

### `has()` — Records that have related records

```php
// Users who have at least one post
// WHERE EXISTS (SELECT 1 FROM post WHERE post.user_id = user.id)
$users = User::find()->has('posts')->get();
```

### `doesntHave()` — Records without related records

```php
// Users with no posts
$users = User::find()->doesntHave('posts')->get();
```

### `whereHas()` — Filter by relation with conditions

```php
// Users who have at least one active post
$users = User::find()
    ->whereHas('posts', fn($query) => $query->where('status', 'active'))
    ->get();
```

### `whereDoesntHave()` — Exclude by relation conditions

```php
// Users who have no rejected posts
$users = User::find()
    ->whereDoesntHave('posts', fn($query) => $query->where('status', 'rejected'))
    ->get();
```

### Combining relation filters

```php
$users = User::find()
    ->where('status', 'active')
    ->whereHas('posts', fn($query) => $query->where('published', true))
    ->has('profile')
    ->orderBy('name')
    ->get();
```

## Key Mapping Explained

### Direct hasOne / hasMany

The array in `hasOne`/`hasMany` is always `['column_on_related_table' => 'column_on_this_model']`:

```php
// "The related table's user_id column matches this model's id column"
$this->hasMany(Post::class, ['user_id' => 'id']);

// "The related table's id column matches this model's user_id column" (inverse)
$this->hasOne(User::class, ['id' => 'user_id']);
```

### Many-to-Many with viaTable

For M:N relations, the `hasMany()` array describes how the **pivot connects to the related model** (not directly to this model). The `viaTable()` second argument describes how the **pivot connects to this model**:

```php
// Post has many Tags via post_tag pivot
//   post_tag.post_id → post.id (this side)
//   post_tag.tag_id → tag.id (related side)
$this->hasMany(Tag::class, ['tag_id' => 'id'])      // pivot.tag_id = tag.id
    ->viaTable('post_tag', ['post_id' => 'id']);    // pivot.post_id = post.id
```

Pattern:
| Array | Meaning |
|-------|---------|
| `hasMany(Tag::class, ['tag_id' => 'id'])` | `pivot.tag_id = tag.id` |
| `viaTable('post_tag', ['post_id' => 'id'])` | `pivot.post_id = post.id` |

## Saving Related Records

### `save()` — Create or update a related model

Accepts a Model instance or an array of attributes. Automatically sets the foreign key.

```php
$user = User::findByPk(1);

// Create a new post (array → auto-creates model, sets user_id = 1)
$post = $user->posts()->save(['title' => 'New Post', 'body' => 'Content']);

// Create from model instance
$post = $user->posts()->save(new Post(['title' => 'Another', 'body' => '...']));

// Update an existing related model
$post = Post::findByPk(5);
$post->title = 'Updated Title';
$user->posts()->save($post); // saves with user_id = 1
```

**Required fields:** All fields in the related model's `$fillable` array (when passing an array). The foreign key is set automatically — don't include it.

### `saveMany()` — Create/update multiple related models

Accepts any mix of: arrays with PK (update), arrays without PK (insert), existing Model instances (update), new Model instances (insert).

```php
$user = User::findByPk(1);

// Load an existing post to modify
$existingPost = Post::findByPk(3);
$existingPost->title = 'Modified title';

// Save all at once — mix of create and update
$posts = $user->posts()->saveMany([
    ['id' => 5, 'title' => 'Updated Title'],           // UPDATE — array has 'id', loads from DB
    ['id' => 8, 'body' => 'New body content'],         // UPDATE — array has 'id', loads from DB
    ['title' => 'Brand New Post', 'body' => 'Content'],// INSERT — no 'id' in array
    $existingPost,                                      // UPDATE — Model loaded from DB (exists)
    new Post(['title' => 'Another New', 'body' => '...']), // INSERT — new Model instance
]);

// Returns array of saved Model instances (all have user_id set)
foreach ($posts as $post) {
    echo $post->id . ': ' . $post->title . PHP_EOL;
}
```

**How it decides INSERT vs UPDATE:**

| Input | Has primary key? | Action |
|-------|-----------------|--------|
| Array with `id` | Yes → loads from DB | UPDATE |
| Array without `id` | No | INSERT |
| Model from `findByPk()` | Yes (`exists()` = true) | UPDATE |
| `new Model([...])` | No (`isNew()` = true) | INSERT |

**Foreign key is always set automatically** — you don't need to include `user_id` in the arrays or models.

### `attach()` — Add pivot table entries (M:N only)

Inserts rows into the junction table. Does not check for duplicates.

```php
$post = Post::findByPk(1);

// Attach tags by ID
$post->tags()->attach([1, 2, 3]);
// INSERT INTO post_tag (post_id, tag_id) VALUES (1,1), (1,2), (1,3)
```

### `detach()` — Remove pivot table entries (M:N only)

```php
$post = Post::findByPk(1);

// Detach specific tags
$post->tags()->detach([2, 3]);
// DELETE FROM post_tag WHERE post_id = 1 AND tag_id IN (2, 3)

// Detach ALL tags for this post
$post->tags()->detach();
// DELETE FROM post_tag WHERE post_id = 1
```

### `sync()` — Make pivot match exactly the given IDs

Adds missing, removes extra. Returns what changed.

```php
$post = Post::findByPk(1);

// Current tags: [1, 2, 3]
// Desired tags: [2, 4, 5]
$changes = $post->tags()->sync([2, 4, 5]);

// Result:
// $changes = ['attached' => [4, 5], 'detached' => [1, 3]]
// Final tags: [2, 4, 5]
```

### `saveTogether()` — Save Model + All Relations in One Call

Saves the model and all nested relations recursively, wrapped in a transaction. If any part fails, everything rolls back.

**Create a new model with relations:**

```php
$user = new User();
$user->saveTogether([
    'username' => 'john',
    'email' => 'john@example.com',
    'password' => 'secret',
    'role' => 'member',
    'score' => 0,
    'department_id' => 1,
    'status_code' => 1,
    'profile' => [                                    // hasOne → INSERT
        'first_name' => 'John',
        'last_name' => 'Doe',
        'bio' => 'Hello world',
    ],
    'posts' => [                                      // hasMany → INSERT all
        ['title' => 'First Post', 'slug' => 'first', 'body' => 'Content', 'category_id' => 1, 'status_code' => 1],
        ['title' => 'Second Post', 'slug' => 'second', 'body' => 'More', 'category_id' => 1, 'status_code' => 1],
    ],
]);
// User, profile, and 2 posts all saved in one transaction
```

**Update an existing model with relations (mix of insert/update):**

```php
$user = User::findByPk(1);
$existingPost = Post::findByPk(3);
$existingPost->title = 'Modified externally';

$user->saveTogether([
    'score' => 99,                                    // UPDATE user.score
    'profile' => ['bio' => 'Updated bio'],            // UPDATE profile (loads existing)
    'posts' => [
        ['id' => 5, 'title' => 'Updated Title'],     // UPDATE post 5 (has id)
        ['id' => 8, 'body' => 'New body'],            // UPDATE post 8 (has id)
        ['title' => 'Brand New', 'slug' => 'new', 'body' => '...', 'category_id' => 1, 'status_code' => 1], // INSERT (no id)
        $existingPost,                                // UPDATE (Model instance, exists)
        new Post(['title' => 'Another', 'slug' => 'another', 'body' => '...', 'category_id' => 1, 'status_code' => 1]), // INSERT (new Model)
    ],
]);
```

**Deep nesting (posts with comments):**

```php
$user = new User();
$user->saveTogether([
    'username' => 'jane',
    'email' => 'jane@example.com',
    'password' => 'secret',
    'role' => 'editor',
    'score' => 50,
    'department_id' => 2,
    'status_code' => 1,
    'posts' => [
        [
            'title' => 'Post with comments',
            'slug' => 'post-comments',
            'body' => 'Content here',
            'category_id' => 4,
            'status_code' => 2,
            'comments' => [                           // nested hasMany
                ['body' => 'Great post!', 'user_id' => 1, 'status_code' => 1],
                ['body' => 'Thanks for sharing', 'user_id' => 2, 'status_code' => 1],
            ],
        ],
    ],
]);
// Creates: user → post → 2 comments (all FKs set automatically)
```

**How it decides INSERT vs UPDATE:**

| Input | Has primary key? | Action |
|-------|-----------------|--------|
| Array with `id` | Yes → loads from DB | UPDATE |
| Array without `id` | No | INSERT |
| Model from `findByPk()` | Yes (`exists()` = true) | UPDATE |
| `new Model([...])` | No (`isNew()` = true) | INSERT |

**Transaction safety:** If any save fails (FK violation, unique constraint, etc.), the entire operation rolls back — no partial saves.

### Using Manual Transactions

For more control, use `transaction()` with individual `save()`/`saveMany()` calls:

```php
User::transaction(function () {
    $user = new User(['name' => 'John', 'email' => 'john@test.com']);
    $user->save();

    $user->profile()->save(['bio' => 'Hello world']);
    $user->posts()->saveMany([
        ['title' => 'First', 'slug' => 'first', 'body' => 'Content', 'category_id' => 1, 'status_code' => 1],
    ]);

    return true; // commit (return false to rollback)
});
```

### Summary

| Method | Relation type | What it does |
|--------|--------------|--------------|
| `saveTogether(array)` | Any | Saves model + all nested relations in one transaction |
| `save(Model\|array)` | hasOne / hasMany | Sets FK, saves one model |
| `saveMany(array)` | hasMany | Sets FK, saves multiple models |
| `attach(array $ids)` | M:N (viaTable) | INSERT into pivot |
| `detach(array\|null)` | M:N (viaTable) | DELETE from pivot |
| `sync(array $ids)` | M:N (viaTable) | Add missing + remove extra from pivot |
