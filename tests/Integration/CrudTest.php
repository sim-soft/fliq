<?php

namespace Integration;

use Models\Comment;
use Models\Order;
use Models\Post;
use Models\Setting;
use Models\User;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for basic CRUD operations.
 */
class CrudTest extends DatabaseTestCase
{
    #[Test]
    public function findByPrimaryKey(): void
    {
        $user = User::findByPk(1);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('alice', $user->username);
        $this->assertEquals('alice@example.com', $user->email);
    }

    #[Test]
    public function findByPkReturnsNullForMissing(): void
    {
        $user = User::findByPk(9999);
        $this->assertNull($user);
    }

    #[Test]
    public function findAllReturnsCollection(): void
    {
        $users = User::findAll();

        $this->assertGreaterThanOrEqual(10, count($users));
    }

    #[Test]
    public function createNewRecord(): void
    {
        $setting = new Setting();
        $setting->fill([
            'group' => 'test',
            'key' => 'created_at_test',
            'value' => 'hello',
        ]);
        $result = $setting->save();

        $this->assertTrue($result);
        $this->assertTrue($setting->exists());
        $this->assertGreaterThan(0, $setting->id);
    }

    #[Test]
    public function updateExistingRecord(): void
    {
        $setting = Setting::findByPk(1);
        $this->assertInstanceOf(Setting::class, $setting);

        $originalValue = $setting->value;
        $setting->value = 'Updated Value';
        $result = $setting->save();

        $this->assertTrue($result);

        // Verify by re-fetching
        $refreshed = Setting::findByPk(1);
        $this->assertInstanceOf(Setting::class, $refreshed);
        $this->assertEquals('Updated Value', $refreshed->value);

        // Restore original
        $refreshed->value = $originalValue;
        $refreshed->save();
    }

    #[Test]
    public function updateWithAttributes(): void
    {
        $user = User::findByPk(6);
        $this->assertInstanceOf(User::class, $user);

        $result = $user->update(['score' => 42]);
        $this->assertTrue($result);

        $refreshed = User::findByPk(6);
        $this->assertInstanceOf(User::class, $refreshed);
        $this->assertEquals(42, $refreshed->score);

        // Restore
        $refreshed->update(['score' => 40]);
    }

    #[Test]
    public function deleteRecord(): void
    {
        // Create a temporary record to delete
        $setting = new Setting();
        $setting->fill([
            'group' => 'temp',
            'key' => 'to_delete',
            'value' => 'bye',
        ]);
        $setting->save();
        $settingId = $setting->id;

        $result = $setting->delete();
        $this->assertTrue($result);

        $deleted = Setting::findByPk($settingId);
        $this->assertNull($deleted);
    }

    #[Test]
    public function incrementAndDecrement(): void
    {
        $user = User::findByPk(3);
        $this->assertInstanceOf(User::class, $user);
        $originalScore = $user->score;

        $user->increment('score', 5);
        $refreshed = User::findByPk(3);
        $this->assertInstanceOf(User::class, $refreshed);
        $this->assertEquals($originalScore + 5, $refreshed->score);

        $refreshed->decrement('score', 5);
        $restored = User::findByPk(3);
        $this->assertInstanceOf(User::class, $restored);
        $this->assertEquals($originalScore, $restored->score);
    }

    #[Test]
    public function dirtyAttributeTracking(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $this->assertFalse($user->isDirty());

        $user->score = 999;
        $this->assertTrue($user->isDirty());

        $dirty = $user->getDirtyAttributes();
        $this->assertNotEmpty($dirty);

        // Restore without saving
        $user->refresh();
        $this->assertFalse($user->isDirty());
    }

    #[Test]
    public function fillMassAssignment(): void
    {
        $user = new User();
        $user->fill([
            'username' => 'masstest',
            'email' => 'mass@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 50,
            'department_id' => 1,
            'status_code' => 1,
        ]);

        $this->assertEquals('masstest', $user->username);
        $this->assertEquals('mass@test.com', $user->email);
        $this->assertTrue($user->isNew());
    }

    #[Test]
    public function toArrayAndToJson(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $array = $user->toArray();

        $this->assertNotEmpty($array);
        $this->assertArrayHasKey('username', $array);
        $this->assertEquals('alice', $array['username']);

        $json = $user->toJson();
        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('alice', $decoded['username']);
    }

    #[Test]
    public function onlyAndExcept(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $only = $user->only(['id', 'username']);
        $this->assertCount(2, $only);
        $this->assertArrayHasKey('username', $only);
        $this->assertArrayNotHasKey('email', $only);

        $except = $user->except(['password', 'deleted_at']);
        $this->assertArrayNotHasKey('password', $except);
        $this->assertArrayHasKey('username', $except);
    }

    #[Test]
    public function replicateModel(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $clone = $user->replicate(['id']);

        $this->assertTrue($clone->isNew());
        $this->assertEquals($user->username, $clone->username);
    }

    #[Test]
    public function transactionCommit(): void
    {
        $committed = User::transaction(function () {
            $setting = new Setting();
            $setting->fill(['group' => 'tx', 'key' => 'commit_test', 'value' => 'yes']);
            $setting->save();
            return true;
        });

        $this->assertTrue($committed);

        $found = Setting::find()->where('key', 'commit_test')->first();
        $this->assertNotNull($found);

        // Cleanup
        /** @var \Models\Setting $found */
        $found->delete();
    }

    #[Test]
    public function transactionRollback(): void
    {
        $rolledBack = User::transaction(function () {
            $setting = new Setting();
            $setting->fill(['group' => 'tx', 'key' => 'rollback_test', 'value' => 'no']);
            $setting->save();
            return false; // triggers rollback
        });

        $this->assertFalse($rolledBack);

        $found = Setting::find()->where('key', 'rollback_test')->first();
        $this->assertNull($found);
    }
}

