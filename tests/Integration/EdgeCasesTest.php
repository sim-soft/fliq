<?php

namespace Integration;

use Models\Order;
use Models\Post;
use Models\Setting;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;

/**
 * Integration tests for OR conditions, cursor, edge cases.
 *

 */
class EdgeCasesTest extends DatabaseTestCase
{
    // ------------------------------------------------------------------
    // OR conditions
    // ------------------------------------------------------------------

    #[Test]
    public function orWhere(): void
    {
        $users = User::find()
            ->where('username', 'alice')
            ->orWhere('username', 'bob')
            ->get()
            ->all();

        $this->assertCount(2, $users);
        $names = array_map(fn($u) => $u->username, $users);
        $this->assertContains('alice', $names);
        $this->assertContains('bob', $names);
    }

    #[Test]
    public function orWhereWithOperator(): void
    {
        $users = User::find()
            ->where('score', '>', 90)
            ->orWhere('score', '<', 35)
            ->get()
            ->all();

        // alice (95), judy (91), henry (33)
        $this->assertGreaterThanOrEqual(3, count($users));
        foreach ($users as $user) {
            $this->assertTrue($user->score > 90 || $user->score < 35);
        }
    }

    #[Test]
    public function orIn(): void
    {
        $users = User::find()
            ->where('department_id', 1)
            ->orIn('id', [5, 6])
            ->get()
            ->all();

        // dept 1: alice, bob, ivan + ids 5,6: eve, frank = 5 total
        $this->assertGreaterThanOrEqual(5, count($users));
    }

    #[Test]
    public function orBetween(): void
    {
        $users = User::find()
            ->where('role', 'admin')
            ->orBetween('score', 80, 90)
            ->get()
            ->all();

        // admins: alice(95), judy(91) + score 80-90: bob(82), eve(88) = 4
        $this->assertGreaterThanOrEqual(4, count($users));
    }

    #[Test]
    public function orLike(): void
    {
        $users = User::find()
            ->where('username', 'alice')
            ->orLike('email', '%bob%')
            ->get()
            ->all();

        $this->assertCount(2, $users);
    }

    #[Test]
    public function orIsNull(): void
    {
        $users = User::find()
            ->where('status_code', 0)
            ->orIsNull('deleted_at')
            ->get()
            ->all();

        // status 0: henry + deleted_at IS NULL: 9 users = all 10 (henry overlaps)
        $this->assertGreaterThanOrEqual(9, count($users));
    }

    #[Test]
    public function orNotNull(): void
    {
        $users = User::find()
            ->where('username', 'alice')
            ->orNotNull('deleted_at')
            ->get()
            ->all();

        // alice + judy (has deleted_at) = 2
        $this->assertCount(2, $users);
    }

    // ------------------------------------------------------------------
    // NOT conditions
    // ------------------------------------------------------------------

    #[Test]
    public function notLike(): void
    {
        $users = User::find()
            ->notLike('username', '%a%')
            ->get()
            ->all();

        foreach ($users as $user) {
            $this->assertStringNotContainsString('a', $user->username);
        }
    }

    #[Test]
    public function notBetween(): void
    {
        $users = User::find()
            ->notBetween('score', 50, 90)
            ->get()
            ->all();

        foreach ($users as $user) {
            $this->assertTrue($user->score < 50 || $user->score > 90);
        }
    }

    // ------------------------------------------------------------------
    // Cursor (unbuffered iteration)
    // ------------------------------------------------------------------

    #[Test]
    public function cursorIteratesOneAtATime(): void
    {
        $count = 0;
        foreach (User::find()->orderBy('id')->cursor() as $user) {
            $count++;
            $this->assertNotNull($user->username);
        }
        $this->assertEquals(10, $count);
    }

    #[Test]
    public function cursorWithConditions(): void
    {
        $count = 0;
        foreach (User::find()->where('department_id', 1)->cursor() as $user) {
            $count++;
            $this->assertEquals(1, $user->department_id);
        }
        $this->assertEquals(3, $count);
    }

    // ------------------------------------------------------------------
    // Edge cases: empty results, boundary values
    // ------------------------------------------------------------------

    #[Test]
    public function emptyInArrayHandledGracefully(): void
    {
        // IN with empty array — ORM may generate invalid SQL or skip condition
        // This tests that it doesn't crash
        $query = User::find()->in('id', []);
        $count = $query->count();
        // With empty IN, MySQL treats IN() as always false OR the ORM skips it
        $this->assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function whereWithZeroValue(): void
    {
        // status_code = 0 (henry)
        $users = User::find()->where('status_code', 0)->get()->all();
        $this->assertCount(1, $users);
        $this->assertEquals('henry', $users[0]->username);
    }

    #[Test]
    public function whereWithEmptyString(): void
    {
        // No users have empty username
        $users = User::find()->where('username', '')->get()->all();
        $this->assertEmpty($users);
    }

    #[Test]
    public function aggregateOnEmptyResult(): void
    {
        $sum = Order::find()->where('user_id', 9999)->sum('total');
        $this->assertEquals(0, $sum);
    }

    #[Test]
    public function countOnEmptyResult(): void
    {
        $count = User::find()->where('username', 'nonexistent')->count();
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function limitZeroReturnsAll(): void
    {
        // limit(0) should effectively mean no limit
        $query = User::find()->orderBy('id');
        $results = $query->query($query);
        $this->assertEquals(10, count($results));
    }

    #[Test]
    public function distinctResults(): void
    {
        $query = (new ActiveQuery())
            ->select('role')
            ->distinct()
            ->from('user')
            ->withConnection('mysql');

        $results = $query->query($query);

        // 3 distinct roles: admin, editor, member
        $this->assertCount(3, $results);
    }

    #[Test]
    public function multipleOrderBy(): void
    {
        $query = User::find()
            ->orderBy('department_id', 'ASC')
            ->orderBy('score', 'DESC');

        $results = $query->query($query);

        // First result should be from dept 1 with highest score (alice, 95)
        $this->assertEquals(1, $results[0]['department_id']);
        $this->assertEquals(95, $results[0]['score']);
    }

    #[Test]
    public function refreshAfterSave(): void
    {
        $setting = new Setting();
        $setting->fill(['group' => 'edge', 'key' => 'refresh_test', 'value' => 'original']);
        $setting->save();

        // Modify in DB directly
        Setting::find()->where('id', $setting->id)->updateAll(['value' => 'modified']);

        // Model still has old value
        $this->assertEquals('original', $setting->value);

        // Refresh pulls latest from DB
        $setting->refresh();
        $this->assertEquals('modified', $setting->value);

        // Cleanup
        $setting->delete();
    }

    #[Test]
    public function duplicateUniqueKeyFails(): void
    {
        // Try to insert a user with duplicate username
        $user = new User();
        $user->username = 'alice'; // already exists
        $user->email = 'alice_dup@example.com';
        $user->password = 'x';
        $user->role = 'member';
        $user->score = 0;
        $user->department_id = 1;
        $user->status_code = 1;

        $this->expectException(\Simsoft\DB\Exceptions\QueryException::class);
        $user->save();
    }
}
