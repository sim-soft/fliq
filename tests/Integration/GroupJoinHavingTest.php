<?php

namespace Integration;

use Models\Post;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;

/**
 * Integration tests for GROUP BY, HAVING, JOINs, and relationship filters
 * (has, doesntHave, whereHas, whereDoesntHave).
 *

 */
class GroupJoinHavingTest extends DatabaseTestCase
{
    // ------------------------------------------------------------------
    // GROUP BY variations
    // ------------------------------------------------------------------

    #[Test]
    public function groupBySingleColumn(): void
    {
        $query = (new ActiveQuery())
            ->select('!user.role')
            ->selectRaw('COUNT(*) as total')
            ->from('user')
            ->groupBy('role')
            ->withConnection('mysql');

        $results = $query->query($query);

        // 3 roles: admin, editor, member
        $this->assertCount(3, $results);
    }

    #[Test]
    public function groupByMultipleColumns(): void
    {
        $query = (new ActiveQuery())
            ->select('!user.department_id', '!user.role')
            ->selectRaw('COUNT(*) as total')
            ->from('user')
            ->groupBy('department_id', 'role')
            ->withConnection('mysql');

        $results = $query->query($query);

        // Multiple combinations of dept + role
        $this->assertGreaterThanOrEqual(5, count($results));
    }

    #[Test]
    public function groupByWithAggregate(): void
    {
        $query = (new ActiveQuery())
            ->select('!user.department_id')
            ->selectRaw('AVG(`score`) as avg_score')
            ->selectRaw('MAX(`score`) as max_score')
            ->selectRaw('MIN(`score`) as min_score')
            ->from('user')
            ->groupBy('department_id')
            ->orderBy('department_id')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertCount(5, $results); // 5 departments
        // Dept 1 (Engineering): alice=95, bob=82, ivan=77
        $this->assertEquals(1, $results[0]['department_id']);
        $this->assertEquals(95, (int) $results[0]['max_score']);
    }

    // ------------------------------------------------------------------
    // HAVING variations
    // ------------------------------------------------------------------

    #[Test]
    public function havingWithOperator(): void
    {
        $query = (new ActiveQuery())
            ->select('!post.user_id')
            ->selectRaw('COUNT(*) as post_count')
            ->from('post')
            ->groupBy('user_id')
            ->havingRaw('post_count >= ?', [3])
            ->withConnection('mysql');

        $results = $query->query($query);

        // alice has 4 posts, ivan has 2, bob has 2... only alice has >= 3
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $row) {
            $this->assertGreaterThanOrEqual(3, (int) $row['post_count']);
        }
    }

    #[Test]
    public function havingWithSum(): void
    {
        $query = (new ActiveQuery())
            ->select('!order.user_id')
            ->selectRaw('SUM(`total`) as total_spent')
            ->from('order')
            ->groupBy('user_id')
            ->havingRaw('total_spent > ?', [400])
            ->withConnection('mysql');

        $results = $query->query($query);

        foreach ($results as $row) {
            $this->assertGreaterThan(400, (float) $row['total_spent']);
        }
    }

    #[Test]
    public function havingWithAvg(): void
    {
        $query = (new ActiveQuery())
            ->select('!order.user_id')
            ->selectRaw('AVG(`total`) as avg_order')
            ->selectRaw('COUNT(*) as order_count')
            ->from('order')
            ->groupBy('user_id')
            ->havingRaw('avg_order > ? AND order_count > ?', [150, 1])
            ->withConnection('mysql');

        $results = $query->query($query);

        foreach ($results as $row) {
            $this->assertGreaterThan(150, (float) $row['avg_order']);
            $this->assertGreaterThan(1, (int) $row['order_count']);
        }
    }

    // ------------------------------------------------------------------
    // JOIN variations
    // ------------------------------------------------------------------

    #[Test]
    public function innerJoinWithGroupBy(): void
    {
        // Count posts per department via join
        $query = (new ActiveQuery())
            ->select('!department.name')
            ->selectRaw('COUNT(`post`.`id`) as post_count')
            ->from('department')
            ->join('user', ['department_id' => '!department.id'])
            ->join('post', ['user_id' => '!user.id'])
            ->groupBy('!department.id')
            ->orderByRaw('post_count DESC')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
        // Engineering should have the most posts (alice + bob + ivan)
        $this->assertEquals('Engineering', $results[0]['name']);
    }

    #[Test]
    public function leftJoinWithGroupBy(): void
    {
        // Count orders per user, including users with zero orders
        $query = (new ActiveQuery())
            ->select('!user.username')
            ->selectRaw('COUNT(`order`.`id`) as order_count')
            ->from('user')
            ->leftJoin('order', ['user_id' => '!user.id'])
            ->groupBy('!user.id')
            ->orderByRaw('order_count ASC')
            ->withConnection('mysql');

        $results = $query->query($query);

        // All 10 users should appear (LEFT JOIN)
        $this->assertCount(10, $results);
        // First result should have 0 orders (judy has none)
        $this->assertEquals(0, (int) $results[0]['order_count']);
    }

    #[Test]
    public function rightJoin(): void
    {
        // Right join: all departments even if no users
        $query = (new ActiveQuery())
            ->select('!department.name')
            ->selectRaw('COUNT(`user`.`id`) as user_count')
            ->from('user')
            ->rightJoin('department', ['id' => '!user.department_id'])
            ->groupBy('!department.id')
            ->withConnection('mysql');

        $results = $query->query($query);

        // All 5 departments should appear
        $this->assertCount(5, $results);
    }

    #[Test]
    public function joinWithHaving(): void
    {
        // Departments with more than 2 users
        $query = (new ActiveQuery())
            ->select('!department.name')
            ->selectRaw('COUNT(`user`.`id`) as user_count')
            ->from('department')
            ->join('user', ['department_id' => '!department.id'])
            ->groupBy('!department.id')
            ->havingRaw('user_count > ?', [2])
            ->withConnection('mysql');

        $results = $query->query($query);

        // Engineering has 3 users
        $this->assertGreaterThanOrEqual(1, count($results));
        $names = array_column($results, 'name');
        $this->assertContains('Engineering', $names);
    }

    #[Test]
    public function joinWithMultipleConditions(): void
    {
        // Posts by active users in Engineering department
        $query = (new ActiveQuery())
            ->select('!post.title', '!user.username')
            ->from('post')
            ->join('user', ['id' => '!post.user_id'])
            ->where('!user.department_id', 1)
            ->where('!user.status_code', 1)
            ->where('!post.status_code', 2)
            ->orderBy('!post.view_count', 'DESC')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(5, count($results));
        foreach ($results as $row) {
            $this->assertContains($row['username'], ['alice', 'bob', 'ivan']);
        }
    }

    // ------------------------------------------------------------------
    // Relationship filters: has(), doesntHave(), whereHas(), whereDoesntHave()
    // NOTE: These use manual subquery approach because the ORM's has()/whereHas()
    // methods have a known parameter binding issue with EXISTS subqueries.
    // ------------------------------------------------------------------

    #[Test]
    public function usersWhoHavePosts(): void
    {
        // Users with at least one post — using has() relation filter
        $users = User::find()->has('posts')->get()->all();

        $this->assertGreaterThanOrEqual(7, count($users));
    }

    #[Test]
    public function usersWhoHaveNoPosts(): void
    {
        // Users who have NO posts — using doesntHave() relation filter
        $users = User::find()->doesntHave('posts')->get()->all();

        // Verify none of these users have posts
        foreach ($users as $user) {
            $postCount = Post::find()->where('user_id', $user->id)->count();
            $this->assertEquals(0, $postCount);
        }
    }

    #[Test]
    public function usersWithPublishedPosts(): void
    {
        // Users who have at least one published post — using whereHas()
        $users = User::find()
            ->whereHas('posts', function ($query) {
                $query->where('status_code', 2);
            })
            ->get()
            ->all();

        $this->assertGreaterThanOrEqual(5, count($users));

        foreach ($users as $user) {
            $publishedCount = Post::find()
                ->where('user_id', $user->id)
                ->where('status_code', 2)
                ->count();
            $this->assertGreaterThan(0, $publishedCount);
        }
    }

    #[Test]
    public function usersWithoutHighViewPosts(): void
    {
        // Users who have NO high-view posts — using whereDoesntHave()
        $users = User::find()
            ->whereDoesntHave('posts', function ($query) {
                $query->where('view_count', '>', 200);
            })
            ->get()
            ->all();

        foreach ($users as $user) {
            $highViewCount = Post::find()
                ->where('user_id', $user->id)
                ->where('view_count', '>', 200)
                ->count();
            $this->assertEquals(0, $highViewCount);
        }
    }

    #[Test]
    public function adminUsersWithPosts(): void
    {
        // Admin users who have at least one post — using has() with conditions
        $users = User::find()
            ->where('role', 'admin')
            ->has('posts')
            ->get()
            ->all();

        foreach ($users as $user) {
            $this->assertEquals('admin', $user->role);
        }
    }

    // ------------------------------------------------------------------
    // Complex GROUP BY + JOIN + HAVING combinations
    // ------------------------------------------------------------------

    #[Test]
    public function complexGroupJoinHaving(): void
    {
        // Find categories with more than 2 published posts, showing avg view count
        $query = (new ActiveQuery())
            ->select('!category.name')
            ->selectRaw('COUNT(`post`.`id`) as post_count')
            ->selectRaw('AVG(`post`.`view_count`) as avg_views')
            ->from('category')
            ->join('post', ['category_id' => '!category.id'])
            ->where('!post.status_code', 2)
            ->groupBy('!category.id')
            ->havingRaw('post_count > ?', [2])
            ->orderByRaw('avg_views DESC')
            ->withConnection('mysql');

        $results = $query->query($query);

        // Programming (cat 4) has 6 published posts
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $row) {
            $this->assertGreaterThan(2, (int) $row['post_count']);
        }
    }

    #[Test]
    public function groupByWithMultipleHaving(): void
    {
        // Users with total order spend > 200 AND at least 2 orders
        $query = (new ActiveQuery())
            ->select('!order.user_id')
            ->selectRaw('SUM(`total`) as total_spent')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('AVG(`total`) as avg_order')
            ->from('order')
            ->where('status_code', 4) // completed only
            ->groupBy('user_id')
            ->havingRaw('total_spent > ? AND order_count >= ?', [200, 2])
            ->orderByRaw('total_spent DESC')
            ->withConnection('mysql');

        $results = $query->query($query);

        foreach ($results as $row) {
            $this->assertGreaterThan(200, (float) $row['total_spent']);
            $this->assertGreaterThanOrEqual(2, (int) $row['order_count']);
        }
    }
}
