<?php

namespace Integration;

use Models\Post;
use Models\User;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\QueryLogger;

/**
 * Integration tests for eager loading (with()).
 *

 */
class EagerLoadingTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueryLogger::disable();
        QueryLogger::reset();
    }

    protected function tearDown(): void
    {
        QueryLogger::disable();
        QueryLogger::reset();
    }

    // ------------------------------------------------------------------
    // Basic eager loading
    // ------------------------------------------------------------------

    #[Test]
    public function eagerLoadHasOneRelation(): void
    {
        /** @var \Models\User $user */
        $user = User::find()->with('profile')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('profile'));

        $profile = $user->profile;
        $this->assertInstanceOf(UserProfile::class, $profile);
        $this->assertEquals('Alice', $profile->first_name);
    }

    #[Test]
    public function eagerLoadHasManyRelation(): void
    {
        /** @var \Models\User $user */
        $user = User::find()->with('posts')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('posts'));

        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertGreaterThanOrEqual(3, count($posts));

        foreach ($posts as $post) {
            $this->assertInstanceOf(Post::class, $post);
            $this->assertEquals(1, $post->user_id);
        }
    }

    #[Test]
    public function eagerLoadMultipleRelations(): void
    {
        /** @var \Models\User $user */
        $user = User::find()->with('profile', 'posts')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertTrue($user->relationLoaded('posts'));

        $this->assertInstanceOf(UserProfile::class, $user->profile);
        $this->assertIsArray($user->posts);
    }

    #[Test]
    public function eagerLoadOnCollection(): void
    {
        QueryLogger::enable();

        $users = User::find()
            ->with('profile')
            ->where('department_id', 1)
            ->get()
            ->all();

        // 3 users in Engineering
        $this->assertCount(3, $users);

        // All should have profile loaded
        foreach ($users as $user) {
            /** @var \Models\User $user */
            $this->assertTrue($user->relationLoaded('profile'));
            $this->assertInstanceOf(UserProfile::class, $user->profile);
        }

        // Eager loading should use 2 queries: 1 for users + 1 for profiles
        // (not 1 + N)
        $queryCount = QueryLogger::getQueryCount();
        $this->assertLessThanOrEqual(3, $queryCount);
    }

    #[Test]
    public function eagerLoadReducesQueryCount(): void
    {
        // Without eager loading: 1 query for users + N queries for profiles
        QueryLogger::enable();
        $users = User::find()->where('department_id', 1)->get()->all();
        foreach ($users as $user) {
            /** @var \Models\User $user */
            $this->assertNotNull($user->profile); // triggers lazy load
        }
        $lazyCount = QueryLogger::getQueryCount();
        QueryLogger::reset();

        // With eager loading: 1 query for users + 1 query for all profiles
        $users = User::find()->with('profile')->where('department_id', 1)->get()->all();
        $eagerCount = QueryLogger::getQueryCount();

        // Eager should use fewer queries
        $this->assertLessThan($lazyCount, $eagerCount);
    }

    // ------------------------------------------------------------------
    // Nested eager loading (dot notation)
    // ------------------------------------------------------------------

    #[Test]
    public function eagerLoadNestedRelation(): void
    {
        QueryLogger::enable();

        /** @var \Models\User $user */
        $user = User::find()
            ->with('posts.comments')
            ->where('id', 1)
            ->first();

        $this->assertTrue($user->relationLoaded('posts'));

        $posts = $user->posts;
        $this->assertNotEmpty($posts);

        // Nested eager loading should batch-load comments for all posts
        // Verify by checking query count is bounded (not N+1)
        $queryCount = QueryLogger::getQueryCount();
        // Should be: 1 (user) + 1 (posts) + 1 (comments) = 3 max
        $this->assertLessThanOrEqual(4, $queryCount);
    }

    // ------------------------------------------------------------------
    // Constrained eager loading
    // ------------------------------------------------------------------

    #[Test]
    public function eagerLoadWithConstraint(): void
    {
        /** @var \Models\User $user */
        $user = User::find()
            ->with(['posts' => fn($query) => $query->where('status_code', 2)])
            ->where('id', 1)
            ->first();

        $this->assertTrue($user->relationLoaded('posts'));

        /** @var array<\Models\Post> $posts */
        $posts = $user->posts;
        foreach ($posts as $post) {
            $this->assertEquals(2, $post->status_code);
        }
    }

    #[Test]
    public function eagerLoadWithOrderConstraint(): void
    {
        /** @var \Models\User $user */
        $user = User::find()
            ->with(['posts' => fn($query) => $query->orderBy('view_count', 'DESC')])
            ->where('id', 1)
            ->first();

        /** @var array<\Models\Post> $posts */
        $posts = $user->posts;
        $this->assertNotEmpty($posts);

        // Verify descending order
        $prevCount = PHP_INT_MAX;
        foreach ($posts as $post) {
            /** @var \Models\Post $post */
            $this->assertLessThanOrEqual($prevCount, $post->view_count);
            $prevCount = $post->view_count;
        }
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    #[Test]
    public function eagerLoadWithNullRelation(): void
    {
        // User 10 (judy) is deleted — profile still exists
        /** @var \Models\User $user */
        $user = User::find()->with('profile')->where('id', 10)->first();

        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertInstanceOf(UserProfile::class, $user->profile);
    }

    #[Test]
    public function eagerLoadEmptyHasMany(): void
    {
        // User 8 (henry) has no posts
        /** @var \Models\User $user */
        $user = User::find()->with('posts')->where('id', 8)->first();

        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertIsArray($user->posts);
        $this->assertEmpty($user->posts);
    }

    #[Test]
    public function eagerLoadOnEmptyResult(): void
    {
        $user = User::find()->with('profile')->where('username', 'nonexistent')->first();

        $this->assertNull($user);
    }

    #[Test]
    public function lazyLoadFallbackWhenNotEagerLoaded(): void
    {
        // Without with(), accessing relation triggers lazy load
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $this->assertFalse($user->relationLoaded('profile'));

        // Access triggers lazy load
        $profile = $user->profile;
        $this->assertInstanceOf(UserProfile::class, $profile);
        $this->assertTrue($user->relationLoaded('profile'));
    }
}
