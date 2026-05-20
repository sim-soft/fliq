<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * @property int|null $id
 * @property string|null $name
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
 */
class EagerProfile extends Model
{
    protected string $table = 'profiles';
    protected string $connection = 'eager_test';
    protected array $fillable = ['user_id', 'bio'];
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
}
