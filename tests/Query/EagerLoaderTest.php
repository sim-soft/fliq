<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\EagerLoader;
use Simsoft\DB\Model;
use Simsoft\DB\QueryLogger;
use Simsoft\DB\Relation;

/**
 * @property int|null $id
 * @property string|null $name
 * @property array<EagerPost> $posts
 * @property EagerProfile|null $profile
 */
class EagerUser extends Model
{
    protected string $table = 'users';
    protected string $connection = 'eager_test';
    protected array $fillable = ['name'];

    public function posts(): Relation
    {
        return $this->hasMany(EagerPost::class, ['user_id' => 'id']);
    }

    public function profile(): Relation
    {
        return $this->hasOne(EagerProfile::class, ['user_id' => 'id']);
    }
}

/**
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $title
 * @property string|null $status
 */
class EagerPost extends Model
{
    protected string $table = 'posts';
    protected string $connection = 'eager_test';
    protected array $fillable = ['user_id', 'title', 'status'];

    public function comments(): Relation
    {
        return $this->hasMany(EagerComment::class, ['post_id' => 'id']);
    }
}

/**
 * @property int|null $id
 * @property int|null $post_id
 * @property string|null $body
 * @property string|null $approved
 */
class EagerComment extends Model
{
    protected string $table = 'comments';
    protected string $connection = 'eager_test';
    protected array $fillable = ['post_id', 'body', 'approved'];
}

/**
 * @property int|null $id
 * @property int|null $user_id
 * @property string|null $bio
 * @property EagerUser|null $owner
 */
class EagerProfile extends Model
{
    protected string $table = 'profiles';
    protected string $connection = 'eager_test';
    protected array $fillable = ['user_id', 'bio'];

    public function owner(): Relation
    {
        return $this->hasOne(EagerUser::class, ['id' => 'user_id']);
    }
}

/**
 * Tests EagerLoader constraint path matching and nested loading.
 */
class EagerLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('eager_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $driver = Connection::get('eager_test');
        $driver->execute(new Raw('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'));
        $driver->execute(new Raw('CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)'));
        $driver->execute(new Raw('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, status TEXT)'));
        $driver->execute(new Raw('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, body TEXT, approved TEXT)'));

        // Seed data
        $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'Alice']));
        $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [2, 'Bob']));
        $driver->execute(new Raw('INSERT INTO profiles (user_id, bio) VALUES (?, ?)', [1, 'Alice bio']));
        $driver->execute(new Raw('INSERT INTO profiles (user_id, bio) VALUES (?, ?)', [2, 'Bob bio']));
        $driver->execute(new Raw('INSERT INTO posts (id, user_id, title, status) VALUES (?, ?, ?, ?)', [1, 1, 'Post A', 'published']));
        $driver->execute(new Raw('INSERT INTO posts (id, user_id, title, status) VALUES (?, ?, ?, ?)', [2, 1, 'Post B', 'draft']));
        $driver->execute(new Raw('INSERT INTO posts (id, user_id, title, status) VALUES (?, ?, ?, ?)', [3, 2, 'Post C', 'published']));
        $driver->execute(new Raw('INSERT INTO comments (post_id, body, approved) VALUES (?, ?, ?)', [1, 'Comment 1', 'yes']));
        $driver->execute(new Raw('INSERT INTO comments (post_id, body, approved) VALUES (?, ?, ?)', [1, 'Comment 2', 'no']));
        $driver->execute(new Raw('INSERT INTO comments (post_id, body, approved) VALUES (?, ?, ?)', [2, 'Comment 3', 'yes']));
        $driver->execute(new Raw('INSERT INTO comments (post_id, body, approved) VALUES (?, ?, ?)', [3, 'Comment 4', 'yes']));
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function eagerLoadHasMany(): void
    {
        $users = EagerUser::find()->with('posts')->get();
        $items = iterator_to_array($users);

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]->relationLoaded('posts'));

        $alicePosts = $items[0]->posts;
        $this->assertCount(2, $alicePosts);
    }

    #[Test]
    public function eagerLoadHasOne(): void
    {
        $users = EagerUser::find()->with('profile')->get();
        $items = iterator_to_array($users);

        $this->assertTrue($items[0]->relationLoaded('profile'));
        $this->assertInstanceOf(EagerProfile::class, $items[0]->profile);
        $this->assertSame('Alice bio', $items[0]->profile->bio);
    }

    #[Test]
    public function eagerLoadNestedRelation(): void
    {
        $users = EagerUser::find()->with('posts.comments')->get();
        $items = iterator_to_array($users);

        $alicePosts = $items[0]->posts;
        $this->assertTrue($alicePosts[0]->relationLoaded('comments'));
        $this->assertCount(2, $alicePosts[0]->comments); // Post A has 2 comments
        $this->assertCount(1, $alicePosts[1]->comments); // Post B has 1 comment
    }

    #[Test]
    public function eagerLoadWithConstraint(): void
    {
        $users = EagerUser::find()->with(['posts' => fn($q) => $q->where('status', 'published')])->get();
        $items = iterator_to_array($users);

        $alicePosts = $items[0]->posts;
        $this->assertCount(1, $alicePosts); // Only published
        $this->assertSame('Post A', $alicePosts[0]->title);
    }

    #[Test]
    public function eagerLoadNestedConstraintOnlyAppliesAtCorrectLevel(): void
    {
        // Constraint on 'posts.comments' should NOT affect 'posts' loading
        $users = EagerUser::find()
            ->with('posts', ['posts.comments' => fn($q) => $q->where('approved', 'yes')])
            ->get();
        $items = iterator_to_array($users);

        // Alice should have all 2 posts (constraint is on comments, not posts)
        $alicePosts = $items[0]->posts;
        $this->assertCount(2, $alicePosts);

        // Post A should only have approved comments
        $postAComments = $alicePosts[0]->comments;
        $this->assertCount(1, $postAComments);
        $this->assertSame('yes', $postAComments[0]->approved);
    }

    #[Test]
    public function eagerLoadEmptyRelation(): void
    {
        // Create a user with no posts
        $driver = Connection::get('eager_test');
        $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [99, 'NoPostsUser']));

        $user = EagerUser::find()->where('id', 99)->with('posts')->get();
        $items = iterator_to_array($user);

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->relationLoaded('posts'));
        $this->assertEmpty($items[0]->posts);
    }

    #[Test]
    public function eagerLoadHasOneReturnsNullWhenMissing(): void
    {
        $driver = Connection::get('eager_test');
        $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [99, 'NoProfileUser']));

        $user = EagerUser::find()->where('id', 99)->with('profile')->get();
        $items = iterator_to_array($user);

        $this->assertTrue($items[0]->relationLoaded('profile'));
        $this->assertNull($items[0]->profile);
    }

    #[Test]
    public function eagerLoadMultipleRelations(): void
    {
        $users = EagerUser::find()->with('posts', 'profile')->get();
        $items = iterator_to_array($users);

        $this->assertTrue($items[0]->relationLoaded('posts'));
        $this->assertTrue($items[0]->relationLoaded('profile'));
    }

    #[Test]
    public function aNestedRelationLoadsThroughAHasOne(): void
    {
        // Nesting under hasMany was covered; under hasOne it was not, and the
        // two are collected differently — one relation value is a list to walk,
        // the other is a single model. Reaching the second level at all depends
        // on the single-model case being handled.
        $driver = Connection::get('eager_test');
        $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [3, 'Carol']));

        $items = iterator_to_array(EagerUser::find()->with('profile.owner')->orderBy('id')->get());

        $this->assertCount(3, $items);

        // Alice and Bob have a profile, and each profile reaches back to its
        // user; Carol has none, so there is nothing to recurse into.
        $this->assertInstanceOf(EagerProfile::class, $items[0]->profile);
        $this->assertTrue($items[0]->profile->relationLoaded('owner'));
        $this->assertInstanceOf(EagerUser::class, $items[0]->profile->owner);
        $this->assertSame('Alice', $items[0]->profile->owner->name);

        $this->assertInstanceOf(EagerProfile::class, $items[1]->profile);
        $this->assertInstanceOf(EagerUser::class, $items[1]->profile->owner);
        $this->assertSame('Bob', $items[1]->profile->owner->name);

        $this->assertNull($items[2]->profile);
    }

    #[Test]
    public function aNestedPathWhoseFirstSegmentIsNotARelationLoadsNothingAndRaisesNothing(): void
    {
        // A typo in the first segment of a dotted path. The first level declines
        // to load — the name is not a relation — so nothing was assigned to any
        // model, and the second level then has no parents to recurse from.
        $items = iterator_to_array(EagerUser::find()->with('unknown.comments')->orderBy('id')->get());

        $this->assertCount(2, $items);
        $this->assertFalse($items[0]->relationLoaded('unknown'));
        $this->assertFalse($items[1]->relationLoaded('unknown'));

        // The rows themselves loaded normally; only the bad path was ignored.
        $this->assertSame('Alice', $items[0]->name);
    }

    #[Test]
    public function collectingForTheNestedLevelDoesNotLazyLoadWhatItFinds(): void
    {
        // Descending a nested path means gathering the models loaded at this
        // level. Gathering them by reading the property would lazy-load the ones
        // that are not loaded — one query per parent, which is the N+1 eager
        // loading exists to remove, reintroduced by the mechanism meant to
        // remove it. A parent that did not load the relation is passed over,
        // not asked.
        //
        // Whether the level loaded at all is decided from the first model, and
        // the set need not be uniform: a comment carries no 'profile', so a set
        // led by one loads nothing — and then a user sitting behind it must not
        // be quietly asked for its own.
        $comment = EagerComment::find()->where('id', 1)->first();
        $user = EagerUser::find()->where('id', 1)->first();
        $this->assertInstanceOf(EagerComment::class, $comment);
        $this->assertInstanceOf(EagerUser::class, $user);

        $models = [$comment, $user];

        QueryLogger::reset();
        QueryLogger::enable();

        try {
            EagerLoader::loadRelations($models, ['profile.owner']);

            $this->assertSame(0, QueryLogger::getQueryCount());
        } finally {
            QueryLogger::reset();
        }

        // Nothing loaded, and nothing was fetched behind the caller's back.
        $this->assertFalse($comment->relationLoaded('profile'));
        $this->assertFalse($user->relationLoaded('profile'));
    }

    #[Test]
    public function aParentWhoseHasOneIsNullIsNotRecursedInto(): void
    {
        // The nested level collects the models to load from, and a parent whose
        // hasOne came back null has none to give. Treating that null as a model
        // is what the collection has to avoid.
        $driver = Connection::get('eager_test');
        $driver->execute(new Raw('INSERT INTO users (id, name) VALUES (?, ?)', [3, 'Carol']));

        $items = iterator_to_array(EagerUser::find()->with('profile.owner')->orderBy('id')->get());

        $this->assertTrue($items[2]->relationLoaded('profile'));
        $this->assertNull($items[2]->profile);

        // And the parents that did have one are unaffected by the one that did not.
        $this->assertSame('Alice', $items[0]->profile->owner->name);
    }

    #[Test]
    public function anUnsavedParentGetsAnEmptyRelationRatherThanEveryRow(): void
    {
        // docs/04-RELATION.md documents this for reading the property: with no
        // local key there is nothing to match on, so the relation is empty
        // rather than unfiltered. Eager loading has to give the same answer —
        // matching on nothing must not mean matching everything.
        $models = [new EagerUser(['name' => 'Draft']), new EagerUser(['name' => 'Also draft'])];

        EagerLoader::loadRelations($models, ['posts']);

        foreach ($models as $model) {
            $this->assertTrue($model->relationLoaded('posts'));
            $this->assertSame([], $model->posts);
        }
    }

    #[Test]
    public function anUnsavedParentGetsNullForAHasOne(): void
    {
        // The same rule, in the shape a hasOne reports absence: null, not an
        // empty list, and not an arbitrary row.
        $models = [new EagerUser(['name' => 'Draft'])];

        EagerLoader::loadRelations($models, ['profile']);

        $this->assertTrue($models[0]->relationLoaded('profile'));
        $this->assertNull($models[0]->profile);
    }

    #[Test]
    public function anUnsavedParentCostsNoQueryAtAll(): void
    {
        // Not just the right answer — the right answer for free. With no local
        // key there is nothing a batch could ask about, and the query it would
        // otherwise send is `WHERE 1 = 0`: a round trip whose result is known
        // before it leaves. Eager loading exists to remove round trips, so one
        // it can decline outright it should decline.
        QueryLogger::reset();
        QueryLogger::enable();

        try {
            $models = [new EagerUser(['name' => 'Draft'])];
            EagerLoader::loadRelations($models, ['posts']);

            $this->assertSame([], $models[0]->posts);
            $this->assertSame(0, QueryLogger::getQueryCount());
        } finally {
            QueryLogger::reset();
        }
    }

    #[Test]
    public function theEmptyKeyAnswerMatchesTheLazyPath(): void
    {
        // Whichever way the relation is reached, an unsaved parent gets the
        // same answer. The two paths are separate implementations, which is
        // how they came to disagree elsewhere.
        $eager = new EagerUser(['name' => 'Draft']);
        $models = [$eager];
        EagerLoader::loadRelations($models, ['posts']);

        $lazy = new EagerUser(['name' => 'Draft']);

        $this->assertSame([], $eager->posts);
        $this->assertSame([], iterator_to_array($lazy->posts()->fetch()));
    }

    #[Test]
    public function aProjectionThatDropsTheLocalKeyEmptiesTheRelationOnBothPaths(): void
    {
        // Selecting a subset that omits the local key leaves every parent with
        // a null one, which is the documented "nothing to match on" case
        // arrived at through the caller's SELECT rather than through the data.
        // The related side is protected from this — a constraint that omits the
        // foreign key still has it appended — but the parent side is the
        // caller's own projection, and narrowing it is what they asked for.
        $eager = iterator_to_array(
            EagerUser::find()->select('name')->with('posts')->orderBy('name')->get()
        );

        $this->assertCount(2, $eager);
        foreach ($eager as $user) {
            $this->assertNull($user->id);
            $this->assertTrue($user->relationLoaded('posts'));
            $this->assertSame([], $user->posts);
        }

        // Not a divergence between the paths: reading it lazily off the same
        // narrowed row gives the same empty answer.
        $lazy = iterator_to_array(EagerUser::find()->select('name')->orderBy('name')->get());
        $this->assertSame([], iterator_to_array($lazy[0]->posts()->fetch()));
    }
}
