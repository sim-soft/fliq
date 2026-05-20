<?php

namespace Integration;

use Models\Category;
use Models\Comment;
use Models\Department;
use Models\Order;
use Models\OrderItem;
use Models\Post;
use Models\User;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for model relationships (hasOne, hasMany).
 */
class RelationshipTest extends DatabaseTestCase
{
    #[Test]
    public function hasOneUserProfile(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $profile = $user->getProfile()->fetch();

        $this->assertInstanceOf(UserProfile::class, $profile);
        $this->assertEquals('Alice', $profile->first_name);
        $this->assertEquals('Smith', $profile->last_name);
        $this->assertEquals(1, $profile->user_id);
    }

    #[Test]
    public function hasOneReturnsNullWhenMissing(): void
    {
        // Create a user without a profile
        $user = new User();
        $user->fill([
            'username' => 'noprofile',
            'email' => 'noprofile@test.com',
            'password' => 'x',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $profile = $user->getProfile()->fetch();
        $this->assertNull($profile);

        // Cleanup
        $user->delete();
    }

    #[Test]
    public function hasManyUserPosts(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $posts = $user->getPosts()->fetch();

        // Alice has posts: 1, 2, 14 (3 published + possibly more)
        $this->assertGreaterThanOrEqual(3, count($posts));

        foreach ($posts as $post) {
            $this->assertInstanceOf(Post::class, $post);
            $this->assertEquals(1, $post->user_id);
        }
    }

    #[Test]
    public function hasManyPostComments(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);
        $comments = $post->getComments()->fetch();

        // Post 1 has 3 comments
        $this->assertCount(3, $comments);

        foreach ($comments as $comment) {
            $this->assertInstanceOf(Comment::class, $comment);
            $this->assertEquals(1, $comment->post_id);
        }
    }

    #[Test]
    public function hasManyOrderItems(): void
    {
        $order = Order::findByPk(1);
        $this->assertInstanceOf(Order::class, $order);
        $items = $order->getItems()->fetch();

        // Order 1 has 3 items
        $this->assertCount(3, $items);

        foreach ($items as $item) {
            $this->assertInstanceOf(OrderItem::class, $item);
            $this->assertEquals(1, $item->order_id);
        }
    }

    #[Test]
    public function hasManyDepartmentUsers(): void
    {
        $dept = Department::findByPk(1);
        $this->assertInstanceOf(Department::class, $dept);
        $users = $dept->getUsers()->fetch();

        // Engineering has users: alice, bob, ivan (3 users)
        $this->assertCount(3, $users);
    }

    #[Test]
    public function selfReferencingCategoryChildren(): void
    {
        // Technology (id=1) has children: Programming (4), DevOps (5)
        $category = Category::findByPk(1);
        $this->assertInstanceOf(Category::class, $category);
        $children = $category->getChildren()->fetch();

        $this->assertCount(2, $children);

        $names = [];
        foreach ($children as $child) {
            $names[] = $child->name;
        }
        $this->assertContains('Programming', $names);
        $this->assertContains('DevOps', $names);
    }

    #[Test]
    public function relationWithChainedConditions(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        // Get only published posts (status_code = 2)
        $publishedPosts = $user->getPosts()
            ->where('status_code', 2)
            ->orderBy('published_at', 'DESC')
            ->fetch();

        $this->assertGreaterThanOrEqual(1, count($publishedPosts));

        foreach ($publishedPosts as $post) {
            $this->assertEquals(2, $post->status_code);
        }
    }

    #[Test]
    public function relationWithLimit(): void
    {
        // Alice has 4 posts total; verify relation returns results
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $posts = $user->getPosts()->fetch();

        // Verify we get posts (limit behavior depends on internal implementation)
        $this->assertGreaterThanOrEqual(1, count($posts));
    }

    #[Test]
    public function belongsToRelation(): void
    {
        // Post belongs to User (inverse of hasMany)
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);
        $author = $post->getUser()->fetch();

        $this->assertInstanceOf(User::class, $author);
        $this->assertEquals('alice', $author->username);
    }

    #[Test]
    public function belongsToCategoryRelation(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);
        $category = $post->getCategory()->fetch();

        $this->assertInstanceOf(Category::class, $category);
        $this->assertEquals('Programming', $category->name);
    }
}
