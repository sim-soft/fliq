<?php

namespace Integration;

use Models\Post;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Collection;

/**
 * Integration tests for Collection methods (lazy iteration, chunking, pagination, pluck).
 */
class CollectionTest extends DatabaseTestCase
{
    #[Test]
    public function getReturnsCollection(): void
    {
        $collection = User::find()->get();

        $this->assertInstanceOf(Collection::class, $collection);
    }

    #[Test]
    public function countReturnsTotal(): void
    {
        $collection = User::find()->get();

        $this->assertEquals(10, $collection->count());
    }

    #[Test]
    public function countWithCondition(): void
    {
        $collection = User::find()->where('status_code', 1)->get();

        $this->assertEquals(8, $collection->count());
    }

    #[Test]
    public function iterateWithForeach(): void
    {
        $collection = User::find()->where('role', 'admin')->get();
        $users = [];

        foreach ($collection as $user) {
            $users[] = $user;
        }

        $this->assertCount(2, $users);
    }

    #[Test]
    public function allReturnsArray(): void
    {
        $collection = User::find()->where('department_id', 1)->get();
        $users = $collection->all();

        $this->assertNotEmpty($users);
        $this->assertCount(3, $users); // alice, bob, ivan
    }

    #[Test]
    public function firstReturnsOneRecord(): void
    {
        $collection = User::find()->orderBy('id')->get();
        $first = $collection->first();

        $this->assertNotNull($first);
        $this->assertEquals('alice', $first->username);
    }

    #[Test]
    public function firstReturnsNullWhenEmpty(): void
    {
        $collection = User::find()->where('username', 'nonexistent')->get();
        $first = $collection->first();

        $this->assertNull($first);
    }

    #[Test]
    public function isEmptyAndIsNotEmpty(): void
    {
        $empty = User::find()->where('username', 'nonexistent')->get();
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->isNotEmpty());

        $notEmpty = User::find()->get();
        $this->assertFalse($notEmpty->isEmpty());
        $this->assertTrue($notEmpty->isNotEmpty());
    }

    #[Test]
    public function pageReturnsPaginatedResults(): void
    {
        $collection = User::find()->orderBy('id')->get();

        $page1 = $collection->page(1, 3);
        $page2 = $collection->page(2, 3);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertNotEquals($page1[0]->id, $page2[0]->id);
    }

    #[Test]
    public function chunkIteratesInBatches(): void
    {
        $collection = User::find()->orderBy('id')->get()->chunk(3);
        $allUsers = [];

        foreach ($collection as $user) {
            $allUsers[] = $user;
        }

        $this->assertCount(10, $allUsers);
    }

    #[Test]
    public function batchYieldsArraysOfRecords(): void
    {
        $collection = User::find()->orderBy('id')->get();
        $batches = [];

        foreach ($collection->batch(4) as $batch) {
            $batches[] = $batch;
        }

        // 10 users / 4 per batch = 3 batches (4, 4, 2)
        $this->assertCount(3, $batches);
        $this->assertCount(4, $batches[0]);
        $this->assertCount(4, $batches[1]);
        $this->assertCount(2, $batches[2]);
    }

    #[Test]
    public function pluckReturnsSingleColumn(): void
    {
        $usernames = User::find()
            ->orderBy('id')
            ->where('department_id', 1)
            ->pluck('username')
            ->all();

        $this->assertCount(3, $usernames);
        $this->assertContains('alice', $usernames);
        $this->assertContains('bob', $usernames);
        $this->assertContains('ivan', $usernames);
    }

    #[Test]
    public function pluckWithIndexBy(): void
    {
        $usernames = User::find()
            ->where('department_id', 1)
            ->pluck('username', 'id')
            ->all();

        $this->assertCount(3, $usernames);
        $this->assertEquals('alice', $usernames[1]);
        $this->assertEquals('bob', $usernames[2]);
        $this->assertEquals('ivan', $usernames[9]);
    }

    #[Test]
    public function toArrayReturnsRawArrays(): void
    {
        $collection = User::find()->where('id', 1)->get()->toArray();
        $results = $collection->all();

        $this->assertCount(1, $results);
        $this->assertIsArray($results[0]);
        $this->assertEquals('alice', $results[0]['username']);
    }

    #[Test]
    public function eachIteratesOneAtATime(): void
    {
        $collection = Post::find()->where('status_code', 2)->each(5);
        $posts = [];

        foreach ($collection as $post) {
            $posts[] = $post;
        }

        // 11 published posts
        $this->assertCount(11, $posts);
    }

    #[Test]
    public function chunkByIdProcessesInChunks(): void
    {
        $chunks = [];

        User::find()->orderBy('id')->chunkById(4, function ($records) use (&$chunks) {
            $chunks[] = count($records);
            return true; // continue
        });

        // 10 users / 4 per chunk = 3 chunks (4, 4, 2)
        $this->assertCount(3, $chunks);
        $this->assertEquals(4, $chunks[0]);
        $this->assertEquals(4, $chunks[1]);
        $this->assertEquals(2, $chunks[2]);
    }

    #[Test]
    public function chunkByIdStopsOnFalse(): void
    {
        $chunks = [];

        User::find()->orderBy('id')->chunkById(4, function ($records) use (&$chunks) {
            $chunks[] = count($records);
            return false; // stop after first chunk
        });

        $this->assertCount(1, $chunks);
        $this->assertEquals(4, $chunks[0]);
    }

    #[Test]
    public function indexByString(): void
    {
        $collection = User::find()
            ->where('department_id', 1)
            ->indexBy('username')
            ->get()
            ->all();

        $this->assertArrayHasKey('alice', $collection);
        $this->assertArrayHasKey('bob', $collection);
        $this->assertArrayHasKey('ivan', $collection);
    }
}
