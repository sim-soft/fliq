<?php

namespace Integration;

use Models\Comment;
use Models\Post;
use Models\Tag;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;
use Simsoft\DB\Relation;

/**
 * Integration tests for the Relation write API.
 *
 * RelationViaTest covers reading through a pivot table; this class covers the
 * write side — attach(), detach(), sync(), saveMany() — plus the accessors and
 * the viaTable guard.
 *
 * Every test mutates real rows. DatabaseTestCase reloads the schema once per
 * class, not per test, so each test restores the fixture state itself in
 * tearDown() to keep the suite order-independent.
 */
class RelationPivotTest extends DatabaseTestCase
{
    /** @var array<int, array{int, int}> The post_tag rows as shipped in resources/sample_db.sql */
    private const FIXTURE_PIVOT = [
        [1, 1], [1, 6],
        [2, 1], [2, 5], [2, 6],
        [3, 1], [3, 2],
        [4, 1], [4, 6],
        [5, 7],
        [6, 7],
        [7, 8],
        [9, 1], [9, 2], [9, 6],
        [10, 5], [10, 6],
        [13, 1], [13, 6],
        [14, 1], [14, 2],
    ];

    protected function tearDown(): void
    {
        if (!static::$dbAvailable) {
            return;
        }

        $this->restorePivot();
        $this->deleteProbeComments();
    }

    /**
     * Reset post_tag to exactly the rows in the fixture file.
     *
     * @return void
     */
    private function restorePivot(): void
    {
        $driver = Connection::get('mysql');
        $driver->execute(new Raw('DELETE FROM `post_tag`'));

        $placeholders = implode(', ', array_fill(0, count(self::FIXTURE_PIVOT), '(?, ?)'));
        $binds = [];
        foreach (self::FIXTURE_PIVOT as [$postId, $tagId]) {
            $binds[] = $postId;
            $binds[] = $tagId;
        }

        $driver->execute(new Raw(
            "INSERT INTO `post_tag` (`post_id`, `tag_id`) VALUES $placeholders",
            $binds
        ));
    }

    /**
     * Remove comments created by saveMany() tests.
     *
     * @return void
     */
    private function deleteProbeComments(): void
    {
        Connection::get('mysql')->execute(
            new Raw('DELETE FROM `comment` WHERE `body` LIKE ?', ['[pivot-test]%'])
        );
    }

    /**
     * Read the tag IDs currently linked to a post, sorted ascending.
     *
     * @param int $postId The post ID.
     * @return array<int, int>
     */
    private function tagIdsOf(int $postId): array
    {
        $query = (new ActiveQuery())
            ->select('tag_id')
            ->from('post_tag')
            ->where('post_id', $postId)
            ->withConnection('mysql');

        $ids = array_map(static fn(array $row): int => (int) $row['tag_id'], $query->query($query));
        sort($ids);

        return $ids;
    }

    /**
     * Count every row in the pivot table.
     *
     * @return int
     */
    private function totalPivotRows(): int
    {
        $query = (new ActiveQuery())->from('post_tag')->withConnection('mysql');

        return count($query->query($query));
    }

    // ------------------------------------------------------------------
    // attach()
    // ------------------------------------------------------------------

    #[Test]
    public function attachAddsPivotRows(): void
    {
        // Post 8 ships with no tags.
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertTrue($post->getTags()->attach([1, 5]));
        $this->assertSame([1, 5], $this->tagIdsOf(8));
    }

    #[Test]
    public function attachAppendsToExistingRows(): void
    {
        // Post 1 already has tags 1 and 6.
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertTrue($post->getTags()->attach([2]));
        $this->assertSame([1, 2, 6], $this->tagIdsOf(1));
    }

    #[Test]
    public function attachWithEmptyArrayIsANoOp(): void
    {
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);

        $before = $this->totalPivotRows();

        // Returns true without issuing a statement — an INSERT with no VALUES
        // would be a syntax error.
        $this->assertTrue($post->getTags()->attach([]));
        $this->assertSame($before, $this->totalPivotRows());
    }

    #[Test]
    public function attachingAnExistingPairThrows(): void
    {
        // post_tag has PRIMARY KEY (post_id, tag_id), so re-attaching collides.
        // attach() does not de-duplicate; callers must use sync() for that.
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $this->expectException(QueryException::class);

        $post->getTags()->attach([1]);
    }

    #[Test]
    public function attachBatchIsAtomicWhenOnePairIsDuplicate(): void
    {
        // Every pair goes into one multi-row INSERT, so a collision on the
        // second pair rolls back the first — no partial write.
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        try {
            $post->getTags()->attach([2, 1]);
            $this->fail('Expected a duplicate-key QueryException.');
        } catch (QueryException) {
            // expected
        }

        // Tag 2 must not have been inserted.
        $this->assertSame([1, 6], $this->tagIdsOf(1));
    }

    #[Test]
    public function attachOnNonPivotRelationThrows(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('attach() can only be used on viaTable (M:N) relations.');

        $post->getComments()->attach([1]);
    }

    // ------------------------------------------------------------------
    // detach()
    // ------------------------------------------------------------------

    #[Test]
    public function detachRemovesOnlyTheGivenIds(): void
    {
        // Post 2 has tags 1, 5, 6.
        $post = Post::findByPk(2);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertTrue($post->getTags()->detach([5]));
        $this->assertSame([1, 6], $this->tagIdsOf(2));
    }

    #[Test]
    public function detachWithNullRemovesEveryTag(): void
    {
        $post = Post::findByPk(2);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertTrue($post->getTags()->detach());
        $this->assertSame([], $this->tagIdsOf(2));
    }

    #[Test]
    public function detachOnlyAffectsTheOwningRow(): void
    {
        // Tag 1 is shared by posts 1, 2, 3, 4, 9, 13, 14.
        $post = Post::findByPk(3);
        $this->assertInstanceOf(Post::class, $post);

        $post->getTags()->detach([1]);

        $this->assertSame([2], $this->tagIdsOf(3));
        $this->assertSame([1, 6], $this->tagIdsOf(1));
        $this->assertSame([1, 2, 6], $this->tagIdsOf(9));
    }

    #[Test]
    public function detachWithEmptyArrayIsANoOp(): void
    {
        $post = Post::findByPk(2);
        $this->assertInstanceOf(Post::class, $post);

        // An empty array must not fall through to "detach everything".
        $this->assertTrue($post->getTags()->detach([]));
        $this->assertSame([1, 5, 6], $this->tagIdsOf(2));
    }

    #[Test]
    public function detachIdsThatAreNotAttachedIsHarmless(): void
    {
        $post = Post::findByPk(2);
        $this->assertInstanceOf(Post::class, $post);

        // Tags 3 and 4 are used by no post at all.
        $this->assertTrue($post->getTags()->detach([3, 4]));
        $this->assertSame([1, 5, 6], $this->tagIdsOf(2));
    }

    #[Test]
    public function detachOnNonPivotRelationThrows(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('detach() can only be used on viaTable (M:N) relations.');

        $post->getComments()->detach([1]);
    }

    // ------------------------------------------------------------------
    // sync()
    // ------------------------------------------------------------------

    #[Test]
    public function syncAttachesMissingIdsOnly(): void
    {
        // Post 1 has tags 1, 6 → syncing [1, 6, 2] should only add 2.
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $result = $post->getTags()->sync([1, 6, 2]);

        $this->assertSame([2], array_values($result['attached']));
        $this->assertSame([], array_values($result['detached']));
        $this->assertSame([1, 2, 6], $this->tagIdsOf(1));
    }

    #[Test]
    public function syncDetachesIdsNotInTheGivenSet(): void
    {
        // Post 2 has tags 1, 5, 6 → syncing [1] should drop 5 and 6.
        $post = Post::findByPk(2);
        $this->assertInstanceOf(Post::class, $post);

        $result = $post->getTags()->sync([1]);

        $this->assertSame([], array_values($result['attached']));

        $detached = array_map(intval(...), array_values($result['detached']));
        sort($detached);
        $this->assertSame([5, 6], $detached);
        $this->assertSame([1], $this->tagIdsOf(2));
    }

    #[Test]
    public function syncAttachesAndDetachesInOneCall(): void
    {
        // Post 2 has tags 1, 5, 6 → syncing [1, 2] adds 2 and drops 5 and 6.
        $post = Post::findByPk(2);
        $this->assertInstanceOf(Post::class, $post);

        $result = $post->getTags()->sync([1, 2]);

        $this->assertSame([2], array_values($result['attached']));

        $detached = array_map(intval(...), array_values($result['detached']));
        sort($detached);
        $this->assertSame([5, 6], $detached);
        $this->assertSame([1, 2], $this->tagIdsOf(2));
    }

    #[Test]
    public function syncWithEmptyArrayDetachesEverything(): void
    {
        $post = Post::findByPk(2);
        $this->assertInstanceOf(Post::class, $post);

        $result = $post->getTags()->sync([]);

        $this->assertSame([], array_values($result['attached']));
        $this->assertCount(3, $result['detached']);
        $this->assertSame([], $this->tagIdsOf(2));
    }

    #[Test]
    public function syncOnAnEmptyRelationAttachesAll(): void
    {
        // Post 8 has no tags.
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);

        $result = $post->getTags()->sync([2, 7]);

        $this->assertSame([2, 7], array_values($result['attached']));
        $this->assertSame([], array_values($result['detached']));
        $this->assertSame([2, 7], $this->tagIdsOf(8));
    }

    #[Test]
    public function syncIsIdempotent(): void
    {
        // Re-syncing the same set must report no work and change nothing —
        // in particular it must not collide with the existing pivot rows.
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $result = $post->getTags()->sync([1, 6]);

        $this->assertSame([], array_values($result['attached']));
        $this->assertSame([], array_values($result['detached']));
        $this->assertSame([1, 6], $this->tagIdsOf(1));
    }

    #[Test]
    public function syncAcceptsStringIds(): void
    {
        // IDs arriving from a request body are strings; they must compare
        // equal to the integer IDs read back from the pivot table.
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $result = $post->getTags()->sync(['1', '6']);

        $this->assertSame([], array_values($result['attached']));
        $this->assertSame([], array_values($result['detached']));
        $this->assertSame([1, 6], $this->tagIdsOf(1));
    }

    #[Test]
    public function syncOnNonPivotRelationThrows(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sync() can only be used on viaTable (M:N) relations.');

        $post->getComments()->sync([1]);
    }

    // ------------------------------------------------------------------
    // saveMany()
    // ------------------------------------------------------------------

    #[Test]
    public function saveManyCreatesEachRelatedModel(): void
    {
        // Post 8 ships with no comments.
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);

        $saved = $post->getComments()->saveMany([
            ['user_id' => 1, 'body' => '[pivot-test] first', 'status_code' => 1],
            ['user_id' => 2, 'body' => '[pivot-test] second', 'status_code' => 1],
        ]);

        $this->assertCount(2, $saved);
        $this->assertCount(2, $post->getComments()->fetch());
    }

    #[Test]
    public function saveManySetsTheForeignKeyOnEveryModel(): void
    {
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);

        $saved = $post->getComments()->saveMany([
            ['user_id' => 1, 'body' => '[pivot-test] fk check', 'status_code' => 1],
        ]);

        $this->assertInstanceOf(Comment::class, $saved[0]);
        $this->assertSame(8, (int) $saved[0]->post_id);
        $this->assertNotNull($saved[0]->id);
    }

    #[Test]
    public function saveManyAcceptsModelInstances(): void
    {
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);

        $comment = new Comment();
        $comment->user_id = 3;
        $comment->body = '[pivot-test] instance';
        $comment->status_code = 1;

        $saved = $post->getComments()->saveMany([$comment]);

        $this->assertCount(1, $saved);
        $this->assertInstanceOf(Comment::class, $saved[0]);
        $this->assertSame(8, (int) $saved[0]->post_id);
    }

    #[Test]
    public function saveManyWithEmptyArrayReturnsEmptyArray(): void
    {
        $post = Post::findByPk(8);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertSame([], $post->getComments()->saveMany([]));
    }

    // ------------------------------------------------------------------
    // Accessors and configuration
    // ------------------------------------------------------------------

    #[Test]
    public function getQueryExposesTheUnderlyingActiveQuery(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertInstanceOf(ActiveQuery::class, $post->getTags()->getQuery());
    }

    #[Test]
    public function viaTableAccessorsReturnTheConfiguredJunction(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);
        $relation = $post->getTags();

        $this->assertSame('post_tag', $relation->getViaTable());
        $this->assertSame(['post_id' => 'id'], $relation->getViaLink());
    }

    #[Test]
    public function viaTableAccessorsAreNullOnDirectRelations(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);
        $relation = $post->getComments();

        $this->assertNull($relation->getViaTable());
        $this->assertNull($relation->getViaLink());
        $this->assertNull($relation->getViaRelation());
    }

    #[Test]
    public function viaSetsTheIntermediateRelationNameAndChains(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $relation = $post->getComments();
        $returned = $relation->via('user');

        $this->assertSame($relation, $returned);
        $this->assertSame('user', $relation->getViaRelation());
    }

    #[Test]
    public function relationAccessorsReportKeysAndCardinality(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $tags = $post->getTags();
        $this->assertSame('tag_id', $tags->getForeignKey());
        $this->assertSame('id', $tags->getLocalKey());
        $this->assertSame(Tag::class, $tags->getRelatedClass());
        $this->assertTrue($tags->isMultiple());

        $this->assertFalse($post->getUser()->isMultiple());
    }

    #[Test]
    public function detachOnAnUnsavedModelTouchesNothing(): void
    {
        // An unsaved parent has no local key value. detach() must not widen
        // into "delete every row in the pivot table".
        $before = $this->totalPivotRows();

        $post = new Post();
        $post->getTags()->detach();

        $this->assertSame($before, $this->totalPivotRows());
    }

    #[Test]
    public function attachOnAnUnsavedModelDoesNotInsert(): void
    {
        $before = $this->totalPivotRows();

        $post = new Post();

        try {
            $post->getTags()->attach([1]);
            $this->fail('Expected attach() on an unsaved parent to fail.');
        } catch (QueryException) {
            // post_id is NOT NULL, so the driver rejects the row.
        }

        $this->assertSame($before, $this->totalPivotRows());
    }

    #[Test]
    public function fetchOnAnUnsavedModelReturnsNothing(): void
    {
        // Regression guard: with no local key value there is nothing to match,
        // so the relation must be empty rather than unconstrained. Returning
        // the whole related table here would leak every row.
        $post = new Post();

        $this->assertCount(0, $post->getComments()->fetch());
        $this->assertCount(0, $post->getTags()->fetch());
        $this->assertNull($post->getUser()->fetch());
    }

    #[Test]
    public function fetchOnASavedRowWithANullForeignKeyReturnsNothing(): void
    {
        // The more serious form of the same bug: the parent is persisted and
        // real, but its foreign key is NULL. An unconstrained query here
        // returned whichever category happened to sort first, so the post
        // appeared to belong to a category it has no link to.
        $driver = Connection::get('mysql');
        $driver->execute(new Raw('ALTER TABLE `post` MODIFY `category_id` INT UNSIGNED NULL'));

        try {
            $driver->execute(new Raw('UPDATE `post` SET `category_id` = NULL WHERE `id` = ?', [1]));

            $post = Post::findByPk(1);
            $this->assertInstanceOf(Post::class, $post);
            $this->assertNull($post->category_id);

            $this->assertNull($post->getCategory()->fetch());
        } finally {
            $driver->execute(new Raw('UPDATE `post` SET `category_id` = ? WHERE `id` = ?', [1, 1]));
            $driver->execute(
                new Raw('ALTER TABLE `post` MODIFY `category_id` INT UNSIGNED NOT NULL DEFAULT 0')
            );
        }
    }

    #[Test]
    public function relationIsAnInstanceOfRelation(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $this->assertInstanceOf(Relation::class, $post->getTags());
    }
}
