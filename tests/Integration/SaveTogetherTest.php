<?php

namespace Integration;

use Models\Comment;
use Models\Post;
use Models\User;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Exceptions\QueryException;

/**
 * Integration tests for Model::saveTogether().
 */
class SaveTogetherTest extends DatabaseTestCase
{
    #[Test]
    public function saveNewModelWithHasManyChildren(): void
    {
        $user = new User();
        $result = $user->saveTogether([
            'username' => 'together_new',
            'email' => 'together_new@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
            'posts' => [
                ['title' => 'First post', 'slug' => 'first-post', 'body' => 'Content 1', 'category_id' => 1, 'status_code' => 1],
                ['title' => 'Second post', 'slug' => 'second-post', 'body' => 'Content 2', 'category_id' => 1, 'status_code' => 1],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertTrue($user->exists());

        $posts = $user->getPosts()->fetch()->all();
        $this->assertCount(2, $posts);
        $this->assertEquals('First post', $posts[0]->title);
        $this->assertEquals('Second post', $posts[1]->title);
        $this->assertEquals($user->id, $posts[0]->user_id);

        // Cleanup
        foreach ($posts as $post) {
            $post->delete();
        }
        $user->delete();
    }

    #[Test]
    public function updateExistingModelWithHasManyMixInsertUpdate(): void
    {
        // Create a user with a post first
        $user = new User();
        $user->fill([
            'username' => 'together_update',
            'email' => 'together_update@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 10,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $existingPost = new Post();
        $existingPost->fill([
            'title' => 'Original title',
            'slug' => 'original',
            'body' => 'Original body',
            'category_id' => 1,
            'status_code' => 1,
        ]);
        $existingPost->user_id = $user->id;
        $existingPost->save();
        $postId = $existingPost->id;

        // Now saveTogether: update an existing post + insert a new one
        $result = $user->saveTogether([
            'score' => 99,
            'posts' => [
                ['id' => $postId, 'title' => 'Updated title'],
                ['title' => 'Brand new', 'slug' => 'brand-new', 'body' => 'New body', 'category_id' => 1, 'status_code' => 1],
            ],
        ]);

        $this->assertTrue($result);

        $refreshed = User::findByPk($user->id);
        $this->assertNotNull($refreshed);
        $this->assertEquals(99, $refreshed->score);

        $updatedPost = Post::findByPk($postId);
        $this->assertNotNull($updatedPost);
        $this->assertEquals('Updated title', $updatedPost->title);

        $allPosts = $refreshed->getPosts()->fetch();
        $this->assertCount(2, $allPosts);

        // Cleanup
        foreach ($allPosts as $post) {
            $post->delete();
        }
        $refreshed->delete();
    }

    #[Test]
    public function saveWithHasOneRelation(): void
    {
        $user = new User();
        $result = $user->saveTogether([
            'username' => 'together_hasone',
            'email' => 'together_hasone@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
            'profile' => ['first_name' => 'John', 'last_name' => 'Doe', 'bio' => 'Hello world'],
        ]);

        $this->assertTrue($result);
        $this->assertTrue($user->exists());

        $profile = $user->getProfile()->fetch();
        $this->assertInstanceOf(UserProfile::class, $profile);
        $this->assertEquals('John', $profile->first_name);
        $this->assertEquals('Doe', $profile->last_name);
        $this->assertEquals($user->id, $profile->user_id);

        // Cleanup
        $profile->delete();
        $user->delete();
    }

    #[Test]
    public function saveWithNestedRelations(): void
    {
        $user = new User();
        $result = $user->saveTogether([
            'username' => 'together_nested',
            'email' => 'together_nested@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
            'posts' => [
                [
                    'title' => 'Post with comments',
                    'slug' => 'post-with-comments',
                    'body' => 'Body here',
                    'category_id' => 1,
                    'status_code' => 1,
                    'comments' => [
                        ['body' => 'First comment', 'user_id' => 1, 'status_code' => 1],
                        ['body' => 'Second comment', 'user_id' => 1, 'status_code' => 1],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result);

        $posts = $user->getPosts()->fetch()->all();
        $this->assertCount(1, $posts);

        $comments = $posts[0]->getComments()->fetch()->all();
        $this->assertCount(2, $comments);
        $this->assertEquals('First comment', $comments[0]->body);
        $this->assertEquals('Second comment', $comments[1]->body);
        $this->assertEquals($posts[0]->id, $comments[0]->post_id);

        // Cleanup
        foreach ($comments as $comment) {
            $comment->delete();
        }
        $posts[0]->delete();
        $user->delete();
    }

    #[Test]
    public function transactionRollbackOnFailure(): void
    {
        $user = new User();
        $user->fill([
            'username' => 'together_rollback',
            'email' => 'together_rollback@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();
        $userId = $user->id;

        // Attempt saveTogether with a comment referencing non-existent post_id (FK violation)
        $threw = false;
        try {
            $user->saveTogether([
                'score' => 777,
                'comments' => [
                    ['body' => 'Will fail', 'post_id' => 99999, 'status_code' => 1],
                ],
            ]);
        } catch (QueryException) {
            $threw = true;
        }

        $this->assertTrue($threw);

        // Verify the score was NOT updated (rolled back)
        $refreshed = User::findByPk($userId);
        $this->assertNotNull($refreshed);
        $this->assertEquals(0, $refreshed->score);

        // Cleanup
        $refreshed->delete();
    }

    #[Test]
    public function modelInstancesInArray(): void
    {
        $user = new User();
        $user->fill([
            'username' => 'together_instance',
            'email' => 'together_instance@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $post = new Post(['title' => 'Instance post', 'slug' => 'instance', 'body' => 'Body', 'category_id' => 1, 'status_code' => 1]);

        $result = $user->saveTogether([
            'score' => 50,
            'posts' => [$post],
        ]);

        $this->assertTrue($result);

        $posts = $user->getPosts()->fetch()->all();
        $this->assertCount(1, $posts);
        $this->assertEquals('Instance post', $posts[0]->title);
        $this->assertEquals($user->id, $posts[0]->user_id);

        // Cleanup
        foreach ($posts as $p) {
            $p->delete();
        }
        $user->delete();
    }
}
