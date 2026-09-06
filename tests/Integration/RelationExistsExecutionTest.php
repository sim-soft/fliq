<?php

namespace Integration;

use Models\Category;
use Models\Post;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\DB;

/**
 * Executes the relation-existence filters against a live database and compares
 * every result against the answer the server gives for the equivalent SQL.
 *
 * A filter that builds plausible SQL but selects the wrong rows is invisible in
 * a unit test, so each case here is checked against ground truth rather than
 * against an expected string.
 */
class RelationExistsExecutionTest extends DatabaseTestCase
{
    /**
     * Count rows the server returns for a hand-written statement.
     *
     * @param string $sql The statement, returning a single column named c.
     * @return int
     */
    private function truth(string $sql): int
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = DB::query($sql, [], 'mysql');

        return (int)$rows[0]['c'];
    }

    /**
     * Primary keys selected by a query, in ascending order.
     *
     * @param ActiveQuery $query The query to run.
     * @return array<int, int>
     */
    private function ids(ActiveQuery $query): array
    {
        $ids = [];
        foreach ($query->all() as $row) {
            $ids[] = (int)$row->id;
        }

        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------
    // Direct one-to-many
    // ------------------------------------------------------------------

    #[Test]
    public function hasSelectsExactlyTheParentsWithAChild(): void
    {
        $this->assertSame(
            $this->truth('SELECT COUNT(*) c FROM user u WHERE EXISTS (SELECT 1 FROM post p WHERE p.user_id = u.id)'),
            count($this->ids(User::find()->has('posts')))
        );
    }

    #[Test]
    public function doesntHaveSelectsExactlyTheComplement(): void
    {
        $with = $this->ids(User::find()->has('posts'));
        $without = $this->ids(User::find()->doesntHave('posts'));

        $union = array_unique(array_merge($with, $without));
        sort($union);

        $this->assertSame([], array_intersect($with, $without));
        $this->assertSame($this->ids(User::find()), $union);
    }

    #[Test]
    public function aCallbackNarrowsTheMatchedParents(): void
    {
        $query = User::find()
            ->whereHas('posts', fn(ActiveQuery $q): ActiveQuery => $q->where('view_count', '>', 100));

        $this->assertSame(
            $this->truth(
                'SELECT COUNT(*) c FROM user u WHERE EXISTS '
                . '(SELECT 1 FROM post p WHERE p.user_id = u.id AND p.view_count > 100)'
            ),
            count($this->ids($query))
        );
    }

    // ------------------------------------------------------------------
    // The parent's alias
    // ------------------------------------------------------------------

    #[Test]
    public function anAliasedParentRunsAndAgreesWithTheUnaliasedQuery(): void
    {
        // The sub-query correlated to `user`.`id` under FROM `user` `u`, so
        // this raised "Unknown column 'user.id' in 'where clause'".
        $this->assertSame(
            $this->ids(User::find()->has('posts')),
            $this->ids(User::find()->alias('u')->has('posts'))
        );
    }

    #[Test]
    public function everyRelationFilterSurvivesAnAlias(): void
    {
        foreach (['has', 'doesntHave'] as $method) {
            $this->assertSame(
                $this->ids(User::find()->{$method}('posts')),
                $this->ids(User::find()->alias('u')->{$method}('posts')),
                "$method() disagreed with itself under an alias"
            );
        }

        $constrained = fn(ActiveQuery $q): ActiveQuery => $q->whereNotNull('published_at');

        foreach (['whereHas', 'whereDoesntHave'] as $method) {
            $this->assertSame(
                $this->ids(User::find()->{$method}('posts', $constrained)),
                $this->ids(User::find()->alias('u')->{$method}('posts', $constrained)),
                "$method() disagreed with itself under an alias"
            );
        }
    }

    #[Test]
    public function aParentAliasedToTheRelatedTableNameStillWorks(): void
    {
        $this->assertSame(
            $this->ids(User::find()->has('posts')),
            $this->ids(User::find()->alias('post')->has('posts'))
        );
    }

    // ------------------------------------------------------------------
    // Self-referencing relations
    // ------------------------------------------------------------------

    #[Test]
    public function aSelfReferencingRelationFindsTheParentRows(): void
    {
        // Both sides were called `category`, so the correlation resolved against
        // the inner FROM and this answered 0 where the truth is 3 — the wrong
        // rows, with no error to show for it.
        $this->assertSame(
            $this->truth('SELECT COUNT(*) c FROM category p WHERE EXISTS (SELECT 1 FROM category k WHERE k.parent_id = p.id)'),
            count($this->ids(Category::find()->has('getChildren')))
        );
    }

    #[Test]
    public function aSelfReferencingRelationFindsTheLeafRows(): void
    {
        $this->assertSame(
            $this->truth('SELECT COUNT(*) c FROM category p WHERE NOT EXISTS (SELECT 1 FROM category k WHERE k.parent_id = p.id)'),
            count($this->ids(Category::find()->doesntHave('getChildren')))
        );
    }

    #[Test]
    public function aSelfReferencingCallbackConstrainsTheChildren(): void
    {
        $query = Category::find()
            ->whereHas('getChildren', fn(ActiveQuery $q): ActiveQuery => $q->where('status_code', 1));

        $this->assertSame(
            $this->truth(
                'SELECT COUNT(*) c FROM category p WHERE EXISTS '
                . '(SELECT 1 FROM category k WHERE k.parent_id = p.id AND k.status_code = 1)'
            ),
            count($this->ids($query))
        );
    }

    #[Test]
    public function theTwoSelfReferencingSidesPartitionTheTable(): void
    {
        $parents = $this->ids(Category::find()->has('getChildren'));
        $leaves = $this->ids(Category::find()->doesntHave('getChildren'));

        $union = array_unique(array_merge($parents, $leaves));
        sort($union);

        $this->assertSame([], array_intersect($parents, $leaves));
        $this->assertSame($this->ids(Category::find()), $union);
    }

    // ------------------------------------------------------------------
    // Many-to-many through a junction table
    // ------------------------------------------------------------------

    #[Test]
    public function aViaTableRelationSelectsTheLinkedParents(): void
    {
        // Reading the foreign key off the related table produced
        // `tag`.`tag_id`, so this raised "Unknown column" instead of answering.
        $this->assertSame(
            $this->truth('SELECT COUNT(*) c FROM post p WHERE EXISTS (SELECT 1 FROM post_tag pt WHERE pt.post_id = p.id)'),
            count($this->ids(Post::find()->has('getTags')))
        );
    }

    #[Test]
    public function aViaTableRelationSelectsTheUnlinkedParents(): void
    {
        $this->assertSame(
            $this->truth('SELECT COUNT(*) c FROM post p WHERE NOT EXISTS (SELECT 1 FROM post_tag pt WHERE pt.post_id = p.id)'),
            count($this->ids(Post::find()->doesntHave('getTags')))
        );
    }

    #[Test]
    public function aViaTableCallbackConstrainsTheJunctionRows(): void
    {
        $query = Post::find()
            ->whereHas('getTags', fn(ActiveQuery $q): ActiveQuery => $q->where('tag_id', '<', 3));

        $this->assertSame(
            $this->truth(
                'SELECT COUNT(*) c FROM post p WHERE EXISTS '
                . '(SELECT 1 FROM post_tag pt WHERE pt.post_id = p.id AND pt.tag_id < 3)'
            ),
            count($this->ids($query))
        );
    }

    // ------------------------------------------------------------------
    // Binds and composition
    // ------------------------------------------------------------------

    #[Test]
    public function bindsStayInOrderAcrossSeveralFilters(): void
    {
        $query = User::find()
            ->where('status_code', 1)
            ->whereHas('posts', fn(ActiveQuery $q): ActiveQuery => $q->where('view_count', '>', 100))
            ->whereHas('comments', fn(ActiveQuery $q): ActiveQuery => $q->where('id', '>', 0));

        $this->assertSame(
            $this->truth(
                'SELECT COUNT(*) c FROM user u WHERE u.status_code = 1 '
                . 'AND EXISTS (SELECT 1 FROM post p WHERE p.user_id = u.id AND p.view_count > 100) '
                . 'AND EXISTS (SELECT 1 FROM comment c WHERE c.user_id = u.id AND c.id > 0)'
            ),
            count($this->ids($query))
        );
    }

    #[Test]
    public function anOrFilterWidensRatherThanNarrows(): void
    {
        $query = User::find()
            ->where('status_code', 99)
            ->whereHas('posts', fn(ActiveQuery $q): ActiveQuery => $q->where('view_count', '>', 100), 'OR');

        $this->assertSame(
            $this->truth(
                'SELECT COUNT(*) c FROM user u WHERE u.status_code = 99 '
                . 'OR EXISTS (SELECT 1 FROM post p WHERE p.user_id = u.id AND p.view_count > 100)'
            ),
            count($this->ids($query))
        );
    }
}
