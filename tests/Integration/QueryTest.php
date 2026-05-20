<?php

namespace Integration;

use Models\Order;
use Models\Post;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Conditions\MatchAgainst;

/**
 * Integration tests for complex queries, aggregations, joins, and unions.
 *

 */
class QueryTest extends DatabaseTestCase
{
    // ------------------------------------------------------------------
    // WHERE conditions
    // ------------------------------------------------------------------

    #[Test]
    public function whereEquals(): void
    {
        $users = User::find()->where('role', 'admin')->get()->all();
        $this->assertCount(2, $users); // alice, judy
    }

    #[Test]
    public function whereNotEquals(): void
    {
        $users = User::find()->not('role', 'member')->get()->all();

        foreach ($users as $user) {
            $this->assertNotEquals('member', $user->role);
        }
    }

    #[Test]
    public function whereGreaterThan(): void
    {
        $users = User::find()->where('score', '>', 80)->get()->all();

        foreach ($users as $user) {
            $this->assertGreaterThan(80, $user->score);
        }
    }

    #[Test]
    public function whereIn(): void
    {
        $users = User::find()->in('id', [1, 3, 5])->get()->all();
        $this->assertCount(3, $users);
    }

    #[Test]
    public function whereNotIn(): void
    {
        $users = User::find()->notIn('role', ['admin'])->get()->all();

        foreach ($users as $user) {
            $this->assertNotEquals('admin', $user->role);
        }
    }

    #[Test]
    public function whereBetween(): void
    {
        $users = User::find()->between('score', 50, 80)->get()->all();

        foreach ($users as $user) {
            $this->assertGreaterThanOrEqual(50, $user->score);
            $this->assertLessThanOrEqual(80, $user->score);
        }
    }

    #[Test]
    public function whereLike(): void
    {
        $users = User::find()->like('email', '%@example.com')->get()->all();
        $this->assertCount(10, $users);
    }

    #[Test]
    public function whereIsNull(): void
    {
        $users = User::find()->isNull('deleted_at')->get()->all();
        $this->assertCount(9, $users); // All except judy (id=10)
    }

    #[Test]
    public function whereNotNull(): void
    {
        $users = User::find()->notNull('deleted_at')->get()->all();
        $this->assertCount(1, $users);
        $this->assertEquals('judy', $users[0]->username);
    }

    #[Test]
    public function whereAnyMatchesAnyColumn(): void
    {
        // Find users where username OR email contains 'alice'
        $users = User::find()
            ->whereAny(['username', 'email'], 'like', '%alice%')
            ->get()
            ->all();

        $this->assertCount(1, $users);
        $this->assertEquals('alice', $users[0]->username);
    }

    #[Test]
    public function whereAnyMultipleMatches(): void
    {
        // Find users where username OR email contains 'bob'
        $users = User::find()
            ->whereAny(['username', 'email'], 'like', '%bob%')
            ->get()
            ->all();

        $this->assertCount(1, $users);
        $this->assertEquals('bob', $users[0]->username);
    }

    #[Test]
    public function whereAllMatchesAllColumns(): void
    {
        // Find users where both username and email contain 'alice'
        $users = User::find()
            ->whereAll(['username', 'email'], 'like', '%alice%')
            ->get()
            ->all();

        // alice's username is 'alice' and email is 'alice@example.com' — both match
        $this->assertCount(1, $users);
    }

    #[Test]
    public function whereAllNoMatch(): void
    {
        // Find users where both username and email equal 'alice' — email won't match
        $users = User::find()
            ->whereAll(['username', 'email'], '=', 'alice')
            ->get()
            ->all();

        $this->assertEmpty($users);
    }

    #[Test]
    public function whereNoneExcludesAllColumns(): void
    {
        // Find users where NONE of username/email contain 'alice'
        $users = User::find()
            ->whereNone(['username', 'email'], 'like', '%alice%')
            ->get()
            ->all();

        // All users except alice (9 users)
        $this->assertCount(9, $users);
        foreach ($users as $user) {
            $this->assertNotEquals('alice', $user->username);
        }
    }

    #[Test]
    public function whereAnyWithOtherConditions(): void
    {
        // Active users where username or email contains 'e'
        $users = User::find()
            ->where('status_code', 1)
            ->whereAny(['username', 'email'], 'like', '%eve%')
            ->get()
            ->all();

        $this->assertGreaterThanOrEqual(1, count($users));
    }

    #[Test]
    public function groupedWhereConditions(): void
    {
        // Users who are admin OR have score > 85
        $users = User::find()
            ->where(function ($query) {
                $query->where('role', 'admin')
                    ->orWhere('score', '>', 85);
            })
            ->get()
            ->all();

        // alice (admin, 95), eve (score 88), judy (admin, 91)
        $this->assertGreaterThanOrEqual(3, count($users));
    }

    // ------------------------------------------------------------------
    // ORDER BY, LIMIT, OFFSET
    // ------------------------------------------------------------------

    #[Test]
    public function orderByAsc(): void
    {
        $query = User::find()->orderBy('score', 'ASC')->limit(3);
        $results = $query->query($query);

        $this->assertCount(3, $results);
        // Verify ascending order: each score <= next score
        $this->assertLessThanOrEqual((int) $results[1]['score'], (int) $results[0]['score']);
    }

    #[Test]
    public function orderByDesc(): void
    {
        /** @var \Models\User $user */
        $user = User::find()->orderBy('score', 'DESC')->limit(1)->first();
        $this->assertEquals(95, $user->score); // alice
    }

    #[Test]
    public function limitAndOffset(): void
    {
        $q1 = User::find()->orderBy('id')->limit(3);
        $page1 = $q1->query($q1);

        $q2 = User::find()->orderBy('id')->limit(3, 3);
        $page2 = $q2->query($q2);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertNotEquals($page1[0]['id'], $page2[0]['id']);
    }

    // ------------------------------------------------------------------
    // Aggregations
    // ------------------------------------------------------------------

    #[Test]
    public function countAll(): void
    {
        $count = User::find()->count();
        $this->assertEquals(10, $count);
    }

    #[Test]
    public function countWithCondition(): void
    {
        $count = User::find()->where('status_code', 1)->count();
        $this->assertEquals(8, $count); // Excludes henry (0) and judy (999)
    }

    #[Test]
    public function sumAggregation(): void
    {
        $total = Order::find()->where('status_code', 4)->sum('total');
        $this->assertGreaterThan(0, $total);
    }

    #[Test]
    public function avgAggregation(): void
    {
        $avg = User::find()->where('status_code', 1)->avg('score');
        $this->assertGreaterThan(0, $avg);
        $this->assertLessThan(100, $avg);
    }

    #[Test]
    public function minAggregation(): void
    {
        // henry has 33 but status 0; among status=1, frank has 40
        $min = User::find()->where('status_code', 1)->min('score');
        $this->assertEquals(40, $min);
    }

    #[Test]
    public function maxAggregation(): void
    {
        $max = User::find()->where('status_code', 1)->max('score');
        $this->assertEquals(95, $max); // alice
    }

    // ------------------------------------------------------------------
    // Joins
    // ------------------------------------------------------------------

    #[Test]
    public function innerJoin(): void
    {
        $query = (new ActiveQuery())
            ->select('!post.title', '!user.username')
            ->from('post')
            ->join('user', ['id' => '!post.user_id'])
            ->where('!user.username', 'alice')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(3, count($results));
        foreach ($results as $row) {
            $this->assertEquals('alice', $row['username']);
        }
    }

    #[Test]
    public function leftJoinWithNull(): void
    {
        // Left join to find users without orders
        $query = (new ActiveQuery())
            ->select('!user.username')
            ->selectRaw('`order`.`id` AS order_id')
            ->from('user')
            ->leftJoin('order', ['user_id' => '!user.id'])
            ->isNull('!order.id')
            ->withConnection('mysql');

        $results = $query->query($query);

        // At least user 10 (judy) has no orders
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    // ------------------------------------------------------------------
    // Subqueries
    // ------------------------------------------------------------------

    #[Test]
    public function whereInSubquery(): void
    {
        // Users who have at least one published post
        $subquery = Post::find()
            ->select('user_id')
            ->where('status_code', 2);

        $users = User::find()->in('id', $subquery)->get()->all();

        $this->assertGreaterThanOrEqual(5, count($users));
    }

    #[Test]
    public function existsCondition(): void
    {
        // Users who have comments — using whereRaw EXISTS
        $query = User::find()
            ->whereRaw('EXISTS (SELECT 1 FROM `comment` WHERE `comment`.`user_id` = `user`.`id`)');
        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(5, count($results));
    }

    // ------------------------------------------------------------------
    // GROUP BY + HAVING
    // ------------------------------------------------------------------

    #[Test]
    public function groupByWithHavingRaw(): void
    {
        $query = (new ActiveQuery())
            ->select('!post.user_id')
            ->selectRaw('COUNT(*) as post_count')
            ->from('post')
            ->where('status_code', 2)
            ->groupBy('user_id')
            ->havingRaw('post_count > ?', [1])
            ->withConnection('mysql');

        $results = $query->query($query);

        // alice (3 published), bob (2), charlie (2), ivan (2)
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    // ------------------------------------------------------------------
    // UNION
    // ------------------------------------------------------------------

    #[Test]
    public function unionQueries(): void
    {
        // Admins UNION editors — use raw queries to avoid Collection count issue
        $query = (new ActiveQuery())
            ->select('id', 'username', 'role')
            ->from('user')
            ->where('role', 'admin')
            ->withConnection('mysql');

        $editorsQuery = (new ActiveQuery())
            ->select('id', 'username', 'role')
            ->from('user')
            ->where('role', 'editor')
            ->withConnection('mysql');

        $results = $query->union($editorsQuery)->query($query);

        // 2 admins + 2 editors = 4
        $this->assertCount(4, $results);
    }

    // ------------------------------------------------------------------
    // Complex / combined queries
    // ------------------------------------------------------------------

    #[Test]
    public function complexQueryWithMultipleConditions(): void
    {
        // Active users in Engineering dept with score > 70
        $users = User::find()
            ->where('status_code', 1)
            ->where('department_id', 1)
            ->where('score', '>', 70)
            ->orderBy('score', 'DESC')
            ->get()
            ->all();

        // alice (95), bob (82), ivan (77)
        $this->assertCount(3, $users);
        $this->assertEquals('alice', $users[0]->username);
    }

    #[Test]
    public function selectSpecificColumns(): void
    {
        /** @var \Models\User $user */
        $user = User::find()
            ->select('id', 'username')
            ->where('id', 1)
            ->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('alice', $user->username);
    }

    #[Test]
    public function countPostsPerCategory(): void
    {
        $query = (new ActiveQuery())
            ->select('!post.category_id')
            ->selectRaw('COUNT(*) as total')
            ->from('post')
            ->where('status_code', 2)
            ->groupBy('category_id')
            ->orderByRaw('total DESC')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
        // Programming (category 4) should have the most published posts
        $this->assertEquals(4, $results[0]['category_id']);
    }

    #[Test]
    public function orderTotalsByUser(): void
    {
        $query = (new ActiveQuery())
            ->select('!order.user_id')
            ->selectRaw('SUM(`total`) as total_spent')
            ->selectRaw('COUNT(*) as order_count')
            ->from('order')
            ->where('status_code', 4) // completed
            ->groupBy('user_id')
            ->orderByRaw('total_spent DESC')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertGreaterThan(0, (float) $results[0]['total_spent']);
    }

    #[Test]
    public function betweenDates(): void
    {
        $orders = Order::find()
            ->between('ordered_at', '2024-01-01 00:00:00', '2024-03-31 23:59:59')
            ->get()
            ->all();

        $this->assertGreaterThanOrEqual(5, count($orders));
    }

    #[Test]
    public function firstReturnsNullWhenEmpty(): void
    {
        $result = User::find()->where('username', 'nonexistent_user_xyz')->first();
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // FULLTEXT SEARCH (MATCH...AGAINST)
    // ------------------------------------------------------------------

    #[Test]
    public function fulltextBooleanMustHave(): void
    {
        // Search posts where title+body must contain "PHP"
        $match = (new MatchAgainst(['title', 'body']))
            ->mustHave(['PHP'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('post')
            ->where($match)
            ->withConnection('mysql');

        $results = $query->query($query);

        // Multiple posts mention PHP in title/body
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    #[Test]
    public function fulltextBooleanMustNot(): void
    {
        // Search posts with "query" but NOT "Docker"
        $match = (new MatchAgainst(['title', 'body']))
            ->mustHave(['query'])
            ->mustNot(['Docker'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('post')
            ->where($match)
            ->withConnection('mysql');

        $results = $query->query($query);

        foreach ($results as $row) {
            $this->assertStringNotContainsStringIgnoringCase('Docker', $row['title']);
        }
    }

    #[Test]
    public function fulltextBooleanWildcard(): void
    {
        // Search with wildcard: "develop*" matches developer, developers, development
        $match = (new MatchAgainst(['title', 'body']))
            ->wildcard(['develop'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('post')
            ->where($match)
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function fulltextBooleanContainsPhrase(): void
    {
        // Exact phrase search: "query builder"
        $match = (new MatchAgainst(['title', 'body']))
            ->contains(['query builder'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('post')
            ->where($match)
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function fulltextNaturalLanguageMode(): void
    {
        // Natural language mode search
        $match = (new MatchAgainst(['title', 'body']))
            ->optional(['PHP', 'MySQL', 'database'])
            ->naturalLanguageMode();

        $query = (new ActiveQuery())
            ->from('post')
            ->where($match)
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function fulltextOnSingleColumn(): void
    {
        // Search only in title column
        $match = (new MatchAgainst('title'))
            ->mustHave(['PHP'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('post')
            ->where($match)
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $row) {
            $this->assertStringContainsStringIgnoringCase('PHP', $row['title']);
        }
    }

    #[Test]
    public function fulltextWithAdditionalConditions(): void
    {
        // Fulltext search combined with other WHERE conditions
        $match = (new MatchAgainst(['title', 'body']))
            ->mustHave(['PHP'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('post')
            ->where($match)
            ->where('status_code', 2) // published only
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $row) {
            $this->assertEquals(2, $row['status_code']);
        }
    }
}
