<?php

namespace Integration;

use Models\Post;
use Models\Tag;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\QueryLogger;

/**
 * Eager loading through a junction table, checked against the junction itself.
 *
 * Every assertion here compares against `post_tag` rather than against a
 * hand-written list, because the defect this covers was not a wrong answer but
 * an empty one: `with()` on a viaTable relation returned no related models at
 * all, for every parent, while reading the same relation lazily returned the
 * right rows. A test that only asserted "is an array" passed throughout.
 *
 * The cause was that the batch selects `tag.*` and groups by the foreign key,
 * but through a junction the parent's id is on `post_tag` and not on `tag`, so
 * the grouping key was read from rows that never carried it.
 */
class EagerLoadingManyToManyTest extends DatabaseTestCase
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

    /**
     * The junction, read directly, as a map of parent id to sorted child ids.
     *
     * @param string $parentColumn The junction column naming the parent.
     * @param string $childColumn The junction column naming the child.
     * @return array<int, array<int, int>>
     */
    private function junction(string $parentColumn, string $childColumn): array
    {
        $map = [];

        foreach ((new Raw("SELECT $parentColumn, $childColumn FROM post_tag"))->fetchAll() as $row) {
            $map[(int)$row[$parentColumn]][] = (int)$row[$childColumn];
        }

        foreach ($map as &$children) {
            sort($children);
        }

        return $map;
    }

    /**
     * The ids of a loaded relation, sorted.
     *
     * @param mixed $related The relation value as the model reports it.
     * @return array<int, int>
     */
    private function idsOf(mixed $related): array
    {
        $ids = [];

        if (is_iterable($related)) {
            foreach ($related as $model) {
                $ids[] = (int)$model->id;
            }
        }

        sort($ids);

        return $ids;
    }

    #[Test]
    public function eagerLoadingTagsMatchesTheJunctionForEveryPost(): void
    {
        $truth = $this->junction('post_id', 'tag_id');

        $seen = 0;

        foreach (Post::find()->with('getTags')->orderBy('id')->get() as $post) {
            $this->assertTrue($post->relationLoaded('getTags'));
            $this->assertSame(
                $truth[(int)$post->id] ?? [],
                $this->idsOf($post->getTags),
                "post {$post->id} has the wrong tags"
            );
            ++$seen;
        }

        // Guard against the assertion loop passing because it never ran, and
        // against a fixture that quietly stopped having tagged posts.
        $this->assertSame(15, $seen);
        $this->assertNotSame([], $truth);
    }

    #[Test]
    public function eagerLoadingIsNotSilentlyEmpty(): void
    {
        // The defect's exact signature: every parent gets an empty list. It is
        // worth asserting on its own, because it is the shape a test that only
        // checks types cannot see.
        $withTags = 0;

        foreach (Post::find()->with('getTags')->get() as $post) {
            if ($this->idsOf($post->getTags) !== []) {
                ++$withTags;
            }
        }

        $this->assertSame(11, $withTags);
    }

    #[Test]
    public function eagerLoadingAgreesWithLazyLoadingForEveryPost(): void
    {
        $eager = [];
        foreach (Post::find()->with('getTags')->orderBy('id')->get() as $post) {
            $eager[(int)$post->id] = $this->idsOf($post->getTags);
        }

        $lazy = [];
        foreach (Post::find()->orderBy('id')->get() as $post) {
            $lazy[(int)$post->id] = $this->idsOf($post->getTags()->fetch());
        }

        // The two paths answer the same question and disagreed completely.
        $this->assertSame($lazy, $eager);
    }

    #[Test]
    public function theReverseDirectionAlsoMatchesTheJunction(): void
    {
        $truth = $this->junction('tag_id', 'post_id');

        foreach (Tag::find()->with('getPosts')->orderBy('id')->get() as $tag) {
            $this->assertSame(
                $truth[(int)$tag->id] ?? [],
                $this->idsOf($tag->getPosts),
                "tag {$tag->id} has the wrong posts"
            );
        }
    }

    #[Test]
    public function aTagWithNoPostsGetsAnEmptyArrayRatherThanNothing(): void
    {
        $tagged = array_keys($this->junction('tag_id', 'post_id'));

        $untagged = 0;

        foreach (Tag::find()->with('getPosts')->get() as $tag) {
            if (in_array((int)$tag->id, $tagged, true)) {
                continue;
            }

            $this->assertIsArray($tag->getPosts);
            $this->assertSame([], $tag->getPosts);
            ++$untagged;
        }

        $this->assertSame(2, $untagged);
    }

    #[Test]
    public function theJunctionKeyIsNotLeftOnTheRelatedModels(): void
    {
        // The parent id has to be fetched to group by, and it is not a column
        // of the related table. Leaving it behind would put a column `tag` does
        // not have into toArray(), toJson() and getAttributes().
        $columns = array_column((new Raw('SHOW COLUMNS FROM tag'))->fetchAll(), 'Field');

        $checked = 0;

        foreach (Post::find()->with('getTags')->limit(4)->get() as $post) {
            foreach ($post->getTags as $tag) {
                $this->assertSame([], array_diff(array_keys($tag->toArray()), $columns));
                $this->assertNull($tag->__fliq_via_key);
                $this->assertFalse($tag->isDirty());
                ++$checked;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    #[Test]
    public function eagerLoadingRunsOneQueryForTheWholeRelation(): void
    {
        // The point of with() is the query count; a relation that comes back
        // empty is cheap for the wrong reason, so this is asserted beside the
        // correctness above rather than instead of it.
        QueryLogger::reset();
        QueryLogger::enable();

        foreach (Post::find()->with('getTags')->get() as $post) {
            $this->idsOf($post->getTags);
        }

        QueryLogger::disable();

        $this->assertCount(2, QueryLogger::getQueries());
    }

    #[Test]
    public function aConstraintFiltersTheJunctionBatch(): void
    {
        $truth = array_map(
            static fn(array $row): int => (int)$row['id'],
            (new Raw(
                'SELECT t.id FROM tag t JOIN post_tag pt ON pt.tag_id = t.id'
                . " WHERE pt.post_id = 2 AND t.name LIKE '%o%' ORDER BY t.id"
            ))->fetchAll()
        );

        $post = Post::find()->where('id', 2)
            ->with(['getTags' => fn($query) => $query->like('name', '%o%')])
            ->first();

        $this->assertInstanceOf(Post::class, $post);
        $this->assertSame($truth, $this->idsOf($post->getTags));
        $this->assertNotSame([], $truth);
    }

    #[Test]
    public function aConstraintMayNarrowTheColumnsWithoutLosingTheGrouping(): void
    {
        // Narrowing the select was ignored on a junction batch, because the
        // wildcard was added after the constraint ran. Now the caller's
        // projection stands and only the grouping key is appended to it — and
        // that key is stripped again before the models are handed back, so the
        // caller sees exactly the columns they asked for.
        $post = Post::find()->where('id', 2)
            ->with(['getTags' => fn($query) => $query->select('!tag.name')])
            ->first();

        $this->assertInstanceOf(Post::class, $post);

        $names = [];
        foreach ((array)$post->getTags as $tag) {
            $this->assertSame(['name'], array_keys($tag->toArray()));
            $names[] = $tag->name;
        }

        sort($names);
        $this->assertSame(['Docker', 'PHP', 'Tutorial'], $names);
    }

    #[Test]
    public function nestedEagerLoadingReachesAJunctionAtTheDeeperLevel(): void
    {
        $truth = [];
        foreach ((new Raw(
            'SELECT pt.post_id, pt.tag_id FROM post_tag pt'
            . ' JOIN post p ON p.id = pt.post_id WHERE p.user_id = 1'
        ))->fetchAll() as $row) {
            $truth[(int)$row['post_id']][] = (int)$row['tag_id'];
        }
        foreach ($truth as &$tags) {
            sort($tags);
        }

        $user = User::find()->where('id', 1)->with('posts.getTags')->first();
        $this->assertInstanceOf(User::class, $user);

        $seen = 0;
        foreach ((array)$user->posts as $post) {
            $this->assertSame(
                $truth[(int)$post->id] ?? [],
                $this->idsOf($post->getTags),
                "post {$post->id} has the wrong tags"
            );
            ++$seen;
        }

        $this->assertSame(3, $seen);
        $this->assertNotSame([], $truth);
    }
}
