<?php

namespace Integration;

use Models\Order;
use Models\Post;
use Models\PostTag;
use Models\Setting;
use Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for advanced CRUD: updateAttributes, updateAll, deleteAll,
 * composite primary keys, and mass assignment protection.
 *

 */
class AdvancedCrudTest extends DatabaseTestCase
{
    // ------------------------------------------------------------------
    // updateAttributes()
    // ------------------------------------------------------------------

    #[Test]
    public function updateAttributesOnExistingRecord(): void
    {
        $user = User::findByPk(6);
        $this->assertInstanceOf(User::class, $user);
        $originalScore = $user->score;

        $result = $user->updateAttributes(['score' => 99]);
        $this->assertTrue($result);

        // Verify persisted
        $refreshed = User::findByPk(6);
        $this->assertInstanceOf(User::class, $refreshed);
        $this->assertEquals(99, $refreshed->score);

        // Restore
        $refreshed->updateAttributes(['score' => $originalScore]);
    }

    #[Test]
    public function updateAttributesMultipleFields(): void
    {
        $user = User::findByPk(7);
        $this->assertInstanceOf(User::class, $user);
        $originalRole = $user->role;
        $originalScore = $user->score;

        $result = $user->updateAttributes(['role' => 'editor', 'score' => 75]);
        $this->assertTrue($result);

        $refreshed = User::findByPk(7);
        $this->assertInstanceOf(User::class, $refreshed);
        $this->assertEquals('editor', $refreshed->role);
        $this->assertEquals(75, $refreshed->score);

        // Restore
        $refreshed->updateAttributes(['role' => $originalRole, 'score' => $originalScore]);
    }

    #[Test]
    public function updateAttributesReturnsFalseOnNewModel(): void
    {
        $user = new User();
        $user->fill([
            'username' => 'ghost',
            'email' => 'ghost@test.com',
            'password' => 'x',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);

        // Not saved yet — updateAttributes should return false
        $result = $user->updateAttributes(['score' => 50]);
        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // updateAll()
    // ------------------------------------------------------------------

    #[Test]
    public function updateAllWithQuery(): void
    {
        // Set all inactive users' score to 0
        $user = new User();
        $query = User::find()->where('status_code', 0);
        $result = $user->updateAll(['score' => 0], $query);
        $this->assertTrue($result);

        // Verify: henry (id=8, status=0) should now have score 0
        $henry = User::findByPk(8);
        $this->assertInstanceOf(User::class, $henry);
        $this->assertEquals(0, $henry->score);

        // Restore
        $henry->updateAttributes(['score' => 33]);
    }

    #[Test]
    public function updateAllAffectsMultipleRows(): void
    {
        // Bump all Engineering dept users' score by setting to a known value
        $user = new User();
        $query = User::find()->where('department_id', 1);
        $result = $user->updateAll(['status_code' => 1], $query);
        $this->assertTrue($result);

        // Verify all 3 Engineering users have status 1
        $engUsers = User::find()->where('department_id', 1)->get()->all();
        foreach ($engUsers as $engUser) {
            $this->assertEquals(1, $engUser->status_code);
        }
    }

    #[Test]
    public function updateAllViaActiveQueryMethod(): void
    {
        // Use the ActiveQuery updateAll method directly
        $result = Setting::find()
            ->where('group', 'cache')
            ->updateAll(['value' => '7200']);

        $this->assertTrue($result);

        // Verify
        $settings = Setting::find()->where('group', 'cache')->get()->all();
        foreach ($settings as $setting) {
            $this->assertEquals('7200', $setting->value);
        }

        // Restore
        Setting::find()->where('key', 'driver')->where('group', 'cache')->updateAll(['value' => 'file']);
        Setting::find()->where('key', 'ttl')->updateAll(['value' => '3600']);
    }

    // ------------------------------------------------------------------
    // deleteAll()
    // ------------------------------------------------------------------

    #[Test]
    public function deleteAllWithCondition(): void
    {
        // Create temp records to delete
        $s1 = new Setting();
        $s1->fill(['group' => 'temp', 'key' => 'del1', 'value' => 'x']);
        $s1->save();

        $s2 = new Setting();
        $s2->fill(['group' => 'temp', 'key' => 'del2', 'value' => 'y']);
        $s2->save();

        $s3 = new Setting();
        $s3->fill(['group' => 'temp', 'key' => 'del3', 'value' => 'z']);
        $s3->save();

        // Verify they exist
        $count = Setting::find()->where('group', 'temp')->count();
        $this->assertEquals(3, $count);

        // Delete all temp settings
        $setting = new Setting();
        $condition = Setting::find()->where('group', 'temp');
        $result = $setting->deleteAll($condition);
        $this->assertTrue($result);

        // Verify deleted
        $remaining = Setting::find()->where('group', 'temp')->count();
        $this->assertEquals(0, $remaining);
    }

    #[Test]
    public function deleteAllWithRawCondition(): void
    {
        // Create temp records
        $s1 = new Setting();
        $s1->fill(['group' => 'deleteme', 'key' => 'a', 'value' => '1']);
        $s1->save();

        $s2 = new Setting();
        $s2->fill(['group' => 'deleteme', 'key' => 'b', 'value' => '2']);
        $s2->save();

        // Delete using raw string condition
        $setting = new Setting();
        $result = $setting->deleteAll("`group` = 'deleteme'");
        $this->assertTrue($result);

        $remaining = Setting::find()->where('group', 'deleteme')->count();
        $this->assertEquals(0, $remaining);
    }

    // ------------------------------------------------------------------
    // Composite Primary Keys
    // ------------------------------------------------------------------

    #[Test]
    public function compositePkFindByPk(): void
    {
        $postTag = PostTag::findByPk(['post_id' => 1, 'tag_id' => 1]);

        $this->assertInstanceOf(PostTag::class, $postTag);
        $this->assertEquals(1, $postTag->post_id);
        $this->assertEquals(1, $postTag->tag_id);
    }

    #[Test]
    public function compositePkFindByPkReturnsNullWhenMissing(): void
    {
        $postTag = PostTag::findByPk(['post_id' => 999, 'tag_id' => 999]);
        $this->assertNull($postTag);
    }

    #[Test]
    public function compositePkInsert(): void
    {
        // Tag 3 (Laravel) is not used — assign it to post 5
        $postTag = new PostTag();
        $postTag->post_id = 5;
        $postTag->tag_id = 3;
        $result = $postTag->save();
        $this->assertTrue($result);

        // Verify
        $found = PostTag::findByPk(['post_id' => 5, 'tag_id' => 3]);
        $this->assertInstanceOf(PostTag::class, $found);

        // Cleanup
        $found->delete();
    }

    #[Test]
    public function compositePkDelete(): void
    {
        // Create a record to delete
        $postTag = new PostTag();
        $postTag->post_id = 11;
        $postTag->tag_id = 4;
        $postTag->save();

        // Find and delete it
        $found = PostTag::findByPk(['post_id' => 11, 'tag_id' => 4]);
        $this->assertInstanceOf(PostTag::class, $found);

        $result = $found->delete();
        $this->assertTrue($result);

        // Verify gone
        $deleted = PostTag::findByPk(['post_id' => 11, 'tag_id' => 4]);
        $this->assertNull($deleted);
    }

    #[Test]
    public function compositePkExists(): void
    {
        // Insert and verify exists() works for composite PK
        $postTag = new PostTag();
        $postTag->post_id = 12;
        $postTag->tag_id = 4;
        $postTag->save();

        $found = PostTag::findByPk(['post_id' => 12, 'tag_id' => 4]);
        $this->assertInstanceOf(PostTag::class, $found);
        $this->assertTrue($found->exists());

        // Cleanup
        $found->delete();
    }

    #[Test]
    public function compositePkFindAll(): void
    {
        // Find all tags for post 2
        $postTags = PostTag::findAll(['post_id' => 2]);

        // Post 2 has 3 tags: PHP(1), Docker(5), Tutorial(6)
        $this->assertEquals(3, count($postTags));
    }

    // ------------------------------------------------------------------
    // Mass Assignment Protection
    // ------------------------------------------------------------------

    #[Test]
    public function guardedFieldsAreNotFilled(): void
    {
        $user = new User();
        $user->fill([
            'id' => 999,
            'username' => 'hacker',
            'email' => 'hack@test.com',
            'password' => 'x',
            'role' => 'admin',
            'score' => 100,
            'department_id' => 1,
            'status_code' => 1,
        ]);

        // 'id' is in guarded — should not be set via fill
        $this->assertNotEquals(999, $user->id);
        $this->assertEquals('hacker', $user->username);
    }

    #[Test]
    public function fillableFieldsAreAccepted(): void
    {
        $user = new User();
        $user->fill([
            'username' => 'allowed',
            'email' => 'allowed@test.com',
            'password' => 'secret',
            'role' => 'editor',
            'score' => 77,
            'department_id' => 2,
            'status_code' => 1,
        ]);

        $this->assertEquals('allowed', $user->username);
        $this->assertEquals('allowed@test.com', $user->email);
        $this->assertEquals('editor', $user->role);
        $this->assertEquals(77, $user->score);
        $this->assertEquals(2, $user->department_id);
    }

    #[Test]
    public function nonFillableFieldsAreIgnored(): void
    {
        // Setting model has fillable: ['group', 'key', 'value', 'metadata']
        // 'id', 'created', 'updated' are not fillable
        $setting = new Setting();
        $setting->fill([
            'id' => 999,
            'group' => 'test',
            'key' => 'fillable_test',
            'value' => 'hello',
            'created' => '2020-01-01',
        ]);

        // 'group', 'key', 'value' should be set
        $this->assertEquals('test', $setting->group);
        $this->assertEquals('fillable_test', $setting->key);
        $this->assertEquals('hello', $setting->value);
    }

    #[Test]
    public function directPropertySetBypassesMassAssignment(): void
    {
        // Direct property assignment should work even for guarded fields
        $user = new User();
        $user->id = 999;
        $user->username = 'direct';

        $this->assertEquals(999, $user->id);
        $this->assertEquals('direct', $user->username);
    }

    #[Test]
    public function fillOnExistingModelUpdatesAttributes(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $originalScore = $user->score;

        $user->fill(['score' => 50, 'role' => 'member']);

        $this->assertEquals(50, $user->score);
        $this->assertEquals('member', $user->role);
        $this->assertTrue($user->isDirty());

        // Don't save — just verify fill works on existing models
        $user->refresh();
        $this->assertEquals($originalScore, $user->score);
    }
}
