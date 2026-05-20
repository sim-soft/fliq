<?php

namespace Integration;

use Models\Post;
use Models\Tag;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;

/**
 * Integration tests for M:N relationships via junction/pivot tables.
 *
 * Tests both viaTable() relation methods and direct pivot table queries.
 */
class RelationViaTest extends DatabaseTestCase
{
    // ------------------------------------------------------------------
    // viaTable() relation methods (Bug #3 fix verification)
    // ------------------------------------------------------------------

    #[Test]
    public function postHasTagsViaRelation(): void
    {
        // Post 1 ("Getting Started with PHP 8.1") has tags: PHP, Tutorial
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);
        $tags = $post->getTags()->fetch();

        $this->assertGreaterThanOrEqual(2, count($tags));

        $names = [];
        foreach ($tags as $tag) {
            $this->assertInstanceOf(Tag::class, $tag);
            $names[] = $tag->name;
        }
        $this->assertContains('PHP', $names);
        $this->assertContains('Tutorial', $names);
    }

    #[Test]
    public function postWithManyTagsViaRelation(): void
    {
        // Post 9 has tags: PHP, MySQL, Tutorial (3 tags)
        $post = Post::findByPk(9);
        $this->assertInstanceOf(Post::class, $post);
        $tags = $post->getTags()->fetch();

        $this->assertCount(3, $tags);

        $names = [];
        foreach ($tags as $tag) {
            $names[] = $tag->name;
        }
        $this->assertContains('PHP', $names);
        $this->assertContains('MySQL', $names);
        $this->assertContains('Tutorial', $names);
    }

    #[Test]
    public function tagHasPostsViaRelation(): void
    {
        // Tag 1 (PHP) is used on 7 posts
        $tag = Tag::findByPk(1);
        $this->assertInstanceOf(Tag::class, $tag);
        $posts = $tag->getPosts()->fetch();

        $this->assertCount(7, $posts);

        foreach ($posts as $post) {
            $this->assertInstanceOf(Post::class, $post);
        }
    }

    #[Test]
    public function postWithNoTagsViaRelation(): void
    {
        // Post 8 has no tags
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);
        $tags = $post->getTags()->fetch();

        $this->assertEmpty($tags);
    }

    // ------------------------------------------------------------------
    // Direct pivot table queries
    // ------------------------------------------------------------------

    #[Test]
    public function pivotQueryDirectly(): void
    {
        // Query the pivot table directly to verify data
        $query = (new ActiveQuery())
            ->from('post_tag')
            ->where('post_id', 1)
            ->withConnection('mysql');

        $results = $query->query($query);

        // Post 1 has 2 tags (PHP=1, Tutorial=6)
        $this->assertCount(2, $results);
    }

    #[Test]
    public function pivotQueryForTag(): void
    {
        // Find all post IDs for the PHP tag (id=1)
        $query = (new ActiveQuery())
            ->from('post_tag')
            ->where('tag_id', 1)
            ->withConnection('mysql');

        $results = $query->query($query);

        // PHP tag is on posts: 1, 2, 3, 4, 9, 13, 14
        $this->assertCount(7, $results);
    }

    #[Test]
    public function pivotCountTagsPerPost(): void
    {
        // Count tags per post using GROUP BY on pivot table
        $query = (new ActiveQuery())
            ->select('post_id')
            ->selectRaw('COUNT(*) as tag_count')
            ->from('post_tag')
            ->groupBy('post_id')
            ->havingRaw('tag_count > ?', [2])
            ->withConnection('mysql');

        $results = $query->query($query);

        // Posts with more than 2 tags: post 2 (3 tags), post 9 (3 tags)
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    #[Test]
    public function pivotCountPostsPerTag(): void
    {
        // Count posts per tag
        $query = (new ActiveQuery())
            ->select('tag_id')
            ->selectRaw('COUNT(*) as post_count')
            ->from('post_tag')
            ->groupBy('tag_id')
            ->orderByRaw('post_count DESC')
            ->withConnection('mysql');

        $results = $query->query($query);

        // PHP tag (id=1) should have the most posts
        $this->assertEquals(1, $results[0]['tag_id']);
        $this->assertGreaterThanOrEqual(7, (int) $results[0]['post_count']);
    }

    #[Test]
    public function joinThroughPivotTable(): void
    {
        // Get post titles with their tag names via JOIN through pivot
        $query = (new ActiveQuery())
            ->select('!post.title', '!tag.name')
            ->from('post')
            ->join('post_tag', ['post_id' => '!post.id'])
            ->join('tag', ['id' => '!post_tag.tag_id'])
            ->where('!tag.name', 'PHP')
            ->withConnection('mysql');

        $results = $query->query($query);

        // All posts tagged with PHP
        $this->assertCount(7, $results);
        foreach ($results as $row) {
            $this->assertEquals('PHP', $row['name']);
        }
    }

    #[Test]
    public function findPostsWithSpecificTagCombination(): void
    {
        // Find posts that have BOTH "PHP" and "MySQL" tags
        $query = (new ActiveQuery())
            ->select('!post.id', '!post.title')
            ->from('post')
            ->join('post_tag pt1', ['post_id' => '!post.id'])
            ->join('post_tag pt2', ['post_id' => '!post.id'])
            ->where('!pt1.tag_id', 1)  // PHP
            ->where('!pt2.tag_id', 2)  // MySQL
            ->withConnection('mysql');

        $results = $query->query($query);

        // Posts with both PHP and MySQL: post 3, post 9, post 14
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    #[Test]
    public function getTagsForPostViaSubquery(): void
    {
        // Get tags for post 9 using IN subquery on pivot
        $tagIds = (new ActiveQuery())
            ->select('tag_id')
            ->from('post_tag')
            ->where('post_id', 9);

        $tags = Tag::find()->in('id', $tagIds)->get()->all();

        // Post 9 has tags: PHP(1), MySQL(2), Tutorial(6)
        $this->assertCount(3, $tags);

        $names = array_map(fn($tag) => $tag->name, $tags);
        $this->assertContains('PHP', $names);
        $this->assertContains('MySQL', $names);
        $this->assertContains('Tutorial', $names);
    }

    #[Test]
    public function getPostsForTagViaSubquery(): void
    {
        // Get posts for the "Docker" tag (id=5) using IN subquery
        $postIds = (new ActiveQuery())
            ->select('post_id')
            ->from('post_tag')
            ->where('tag_id', 5);

        $posts = Post::find()->in('id', $postIds)->get()->all();

        // Docker tag is on posts: 2, 10
        $this->assertCount(2, $posts);
    }

    #[Test]
    public function postsWithoutAnyTags(): void
    {
        // Find posts that have NO tags — use direct query approach
        $taggedQuery = (new ActiveQuery())
            ->select('post_id')
            ->from('post_tag')
            ->withConnection('mysql');

        $taggedResults = $taggedQuery->query($taggedQuery);
        $taggedIds = array_unique(array_column($taggedResults, 'post_id'));

        $query = Post::find()->notIn('id', $taggedIds);
        $results = $query->query($query);

        // Posts without tags: 8, 11, 12, 15
        $this->assertGreaterThanOrEqual(4, count($results));
    }

    #[Test]
    public function tagsNotUsedByAnyPost(): void
    {
        // Find tags that are not used by any post — use direct query approach
        $usedQuery = (new ActiveQuery())
            ->select('tag_id')
            ->from('post_tag')
            ->withConnection('mysql');

        $usedResults = $usedQuery->query($usedQuery);
        $usedIds = array_unique(array_column($usedResults, 'tag_id'));

        $query = Tag::find()->notIn('id', $usedIds);
        $results = $query->query($query);

        // Tags 3 (Laravel) and 4 (JavaScript) are not used
        $this->assertCount(2, $results);
    }
}
