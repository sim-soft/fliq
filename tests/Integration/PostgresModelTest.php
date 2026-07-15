<?php

namespace Integration;

use Models\Comment;
use Models\Post;
use Models\Setting;
use Models\Task;
use Models\User;
use Models\UserProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\PostgresDriver;

/**
 * Integration tests for Active Record models on PostgreSQL.
 *
 * Covers CRUD, relations, eager loading, soft deletes, timestamps,
 * RETURNING clause, INSERT ON CONFLICT DO NOTHING, FOR UPDATE/SHARE,
 * fulltext search, and JSON key existence.
 */
class PostgresModelTest extends TestCase
{
    protected static bool $available = false;
    protected static string $connName = 'pgsql';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        $pgConfig = [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: '',
            'charset' => 'utf8',
            'schema' => 'public',
            'statement_cache' => true,
            'statement_cache_size' => 50,
        ];

        Connection::reset();
        Connection::add(static::$connName, $pgConfig);

        // Models default to connection name 'mysql', so register PG under that name too
        Connection::add('mysql', $pgConfig);
        Connection::setDefault(static::$connName);

        try {
            Connection::get(static::$connName);
            static::$available = true;
        } catch (\Throwable) {
            static::$available = false;
        }
    }

    protected function setUp(): void
    {
        if (!static::$available) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::reset();
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

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
    public function findAllReturnsResults(): void
    {
        $users = User::findAll();
        $this->assertGreaterThanOrEqual(10, count($users));
    }

    #[Test]
    public function createNewRecord(): void
    {
        $setting = new Setting();
        $setting->fill([
            'group' => 'pg_model_test',
            'key' => 'create_test',
            'value' => 'test_value',
        ]);
        $result = $setting->save();

        $this->assertTrue($result);
        $this->assertTrue($setting->exists());
        $this->assertGreaterThan(0, $setting->id);

        // Cleanup
        $setting->delete();
    }

    #[Test]
    public function createReturnsIdViaReturning(): void
    {
        $setting = new Setting();
        $setting->fill([
            'group' => 'pg_returning',
            'key' => 'returning_test',
            'value' => 'hello',
        ]);
        $result = $setting->save();

        $this->assertTrue($result);
        $this->assertIsInt($setting->id);
        $this->assertGreaterThan(0, $setting->id);

        // Verify the record actually exists with that ID
        $found = Setting::findByPk($setting->id);
        $this->assertInstanceOf(Setting::class, $found);
        $this->assertEquals('pg_returning', $found->group);

        // Cleanup
        $setting->delete();
    }

    #[Test]
    public function updateExistingRecord(): void
    {
        $setting = Setting::findByPk(1);
        $this->assertInstanceOf(Setting::class, $setting);

        $originalValue = $setting->value;
        $setting->value = 'PG Updated';
        $result = $setting->save();

        $this->assertTrue($result);

        // Verify by re-fetching
        $refreshed = Setting::findByPk(1);
        $this->assertInstanceOf(Setting::class, $refreshed);
        $this->assertEquals('PG Updated', $refreshed->value);

        // Restore
        $refreshed->value = $originalValue;
        $refreshed->save();
    }

    #[Test]
    public function deleteRecord(): void
    {
        $setting = new Setting();
        $setting->fill([
            'group' => 'pg_delete',
            'key' => 'delete_test',
            'value' => 'gone',
        ]);
        $setting->save();
        $settingId = $setting->id;

        $result = $setting->delete();
        $this->assertTrue($result);

        $deleted = Setting::findByPk($settingId);
        $this->assertNull($deleted);
    }

    // ------------------------------------------------------------------
    // RELATIONS
    // ------------------------------------------------------------------

    #[Test]
    public function hasOneRelation(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $profile = $user->getProfile()->fetch();
        $this->assertInstanceOf(UserProfile::class, $profile);
        $this->assertEquals('Alice', $profile->first_name);
        $this->assertEquals(1, $profile->user_id);
    }

    #[Test]
    public function hasManyRelation(): void
    {
        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);

        $posts = $user->getPosts()->fetch();
        $this->assertGreaterThanOrEqual(3, count($posts));

        foreach ($posts as $post) {
            $this->assertInstanceOf(Post::class, $post);
            $this->assertEquals(1, $post->user_id);
        }
    }

    #[Test]
    public function nestedRelation(): void
    {
        $post = Post::findByPk(1);
        $this->assertInstanceOf(Post::class, $post);

        $comments = $post->getComments()->fetch();
        $this->assertGreaterThanOrEqual(1, count($comments));

        foreach ($comments as $comment) {
            $this->assertInstanceOf(Comment::class, $comment);
            $this->assertEquals(1, $comment->post_id);
        }
    }

    // ------------------------------------------------------------------
    // EAGER LOADING
    // ------------------------------------------------------------------

    #[Test]
    public function eagerLoadHasOne(): void
    {
        /** @var User $user */
        $user = User::find()->with('profile')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertInstanceOf(UserProfile::class, $user->profile);
        $this->assertEquals('Alice', $user->profile->first_name);
    }

    #[Test]
    public function eagerLoadHasMany(): void
    {
        /** @var User $user */
        $user = User::find()->with('posts')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('posts'));
        $this->assertIsArray($user->posts);
        $this->assertGreaterThanOrEqual(3, count($user->posts));
    }

    #[Test]
    public function eagerLoadMultiple(): void
    {
        /** @var User $user */
        $user = User::find()->with('profile', 'posts')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertTrue($user->relationLoaded('posts'));
    }

    #[Test]
    public function eagerLoadNested(): void
    {
        /** @var User $user */
        $user = User::find()->with('posts.comments')->where('id', 1)->first();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->relationLoaded('posts'));

        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertGreaterThanOrEqual(3, count($posts));

        // Verify nested relation was loaded on posts
        $commentsLoaded = false;
        foreach ($posts as $post) {
            if ($post->relationLoaded('comments')) {
                $commentsLoaded = true;
                break;
            }
        }
        $this->assertTrue($commentsLoaded, 'Nested comments relation should be loaded on posts');
    }

    // ------------------------------------------------------------------
    // SOFT DELETES
    // ------------------------------------------------------------------

    #[Test]
    public function findExcludesSoftDeleted(): void
    {
        $tasks = Task::find()->get()->all();

        // 10 total tasks, 2 soft-deleted = 8 visible
        $this->assertCount(8, $tasks);

        foreach ($tasks as $task) {
            $this->assertNull($task->deleted_at);
        }
    }

    #[Test]
    public function findByPkExcludesSoftDeleted(): void
    {
        // Task 6 is soft-deleted
        $task = Task::findByPk(6);
        $this->assertNull($task);
    }

    #[Test]
    public function withTrashedIncludesAll(): void
    {
        $tasks = Task::withTrashed()->get()->all();
        $this->assertCount(10, $tasks);
    }

    #[Test]
    public function softDeleteAndRestore(): void
    {
        // Create a task, soft delete it, restore it
        $task = new Task();
        $task->fill([
            'user_id' => 1,
            'title' => 'PG Soft Delete Test',
            'priority' => 'low',
            'status' => 'todo',
        ]);
        $task->save();
        $taskId = $task->id;

        $task->delete();

        // Should not be found normally
        $this->assertNull(Task::findByPk($taskId));

        // Should be found with trashed
        $trashed = Task::withTrashed()->where('id', $taskId)->first();
        $this->assertInstanceOf(Task::class, $trashed);
        $this->assertNotNull($trashed->deleted_at);

        // Restore
        $trashed->restore();
        $restored = Task::findByPk($taskId);
        $this->assertInstanceOf(Task::class, $restored);
        $this->assertNull($restored->deleted_at);

        // Hard delete cleanup
        (new Raw('DELETE FROM task WHERE id = ?', [$taskId]))
            ->withConnection(static::$connName)->execute();
    }

    // ------------------------------------------------------------------
    // TIMESTAMPS
    // ------------------------------------------------------------------

    #[Test]
    public function timestampsSetsCreatedAtOnInsert(): void
    {
        $task = new Task();
        $task->fill([
            'user_id' => 1,
            'title' => 'PG Timestamp Test',
            'priority' => 'medium',
            'status' => 'todo',
        ]);
        $task->save();

        $this->assertNotNull($task->created_at);
        $this->assertNotNull($task->updated_at);

        // Cleanup
        (new Raw('DELETE FROM task WHERE id = ?', [$task->id]))
            ->withConnection(static::$connName)->execute();
    }

    // ------------------------------------------------------------------
    // INSERT ON CONFLICT DO NOTHING
    // ------------------------------------------------------------------

    #[Test]
    public function insertOrIgnoreDoesNotThrow(): void
    {
        // Setting table has UNIQUE("group", key). Insert a duplicate.
        $existing = Setting::findByPk(1);
        $this->assertInstanceOf(Setting::class, $existing);

        // Try to insert a duplicate via raw ON CONFLICT DO NOTHING
        $insert = (new Insert('setting', [
            'group' => $existing->group,
            'key' => $existing->key,
            'value' => 'conflict_value',
        ]))->ignore();
        $insert->withConnection(static::$connName);
        $result = $insert->execute();

        // Should succeed (no throw) but insert nothing
        $this->assertTrue($result);
    }

    // ------------------------------------------------------------------
    // RETURNING ON UPDATE/DELETE
    // ------------------------------------------------------------------

    #[Test]
    public function updateWithReturning(): void
    {
        // Cleanup any leftover from previous run
        (new Raw('DELETE FROM setting WHERE "group" = ?', ['pg_returning_upd']))
            ->withConnection(static::$connName)->execute();

        // Create a temp setting
        $setting = new Setting();
        $setting->fill([
            'group' => 'pg_returning_upd',
            'key' => 'upd_test',
            'value' => 'before',
        ]);
        $setting->save();

        $update = new Update('setting', ['value' => 'after']);
        $update->withConnection(static::$connName);
        $update->condition("\"group\" = 'pg_returning_upd' AND key = 'upd_test'");
        $update->returning('id', 'value');
        $result = $update->execute();

        $this->assertTrue($result);
        $rows = $update->getReturningResult();
        $this->assertNotNull($rows);
        $this->assertCount(1, $rows);
        $this->assertEquals('after', $rows[0]['value']);

        // Cleanup
        (new Raw('DELETE FROM setting WHERE "group" = ?', ['pg_returning_upd']))
            ->withConnection(static::$connName)->execute();
    }

    #[Test]
    public function deleteWithReturning(): void
    {
        // Cleanup any leftover from previous run
        (new Raw('DELETE FROM setting WHERE "group" = ?', ['pg_returning_del']))
            ->withConnection(static::$connName)->execute();

        // Create a temp setting
        $setting = new Setting();
        $setting->fill([
            'group' => 'pg_returning_del',
            'key' => 'del_test',
            'value' => 'to_delete',
        ]);
        $setting->save();
        $settingId = $setting->id;

        $delete = new Delete('setting');
        $delete->withConnection(static::$connName);
        $delete->condition("\"group\" = 'pg_returning_del'");
        $delete->returning('id', 'key');
        $result = $delete->execute();

        $this->assertTrue($result);
        $rows = $delete->getReturningResult();
        $this->assertNotNull($rows);
        $this->assertCount(1, $rows);
        $this->assertEquals($settingId, $rows[0]['id']);
        $this->assertEquals('del_test', $rows[0]['key']);
    }

    // ------------------------------------------------------------------
    // FOR UPDATE / FOR SHARE (row-level locking)
    // ------------------------------------------------------------------

    #[Test]
    public function forUpdateGeneratesLockClause(): void
    {
        /** @var PostgresDriver $driver */
        $driver = Connection::get(static::$connName);

        // Run inside a transaction so the lock is valid
        $result = $driver->transaction(function () {
            $query = (new ActiveQuery())
                ->withConnection(static::$connName)
                ->from('user')
                ->where('id', 1)
                ->forUpdate();

            $rows = $query->query($query);
            $this->assertCount(1, $rows);
            $this->assertEquals('alice', $rows[0]['username']);
            return true;
        });

        $this->assertTrue($result);
    }

    #[Test]
    public function forShareGeneratesLockClause(): void
    {
        /** @var PostgresDriver $driver */
        $driver = Connection::get(static::$connName);

        $result = $driver->transaction(function () {
            $query = (new ActiveQuery())
                ->withConnection(static::$connName)
                ->from('user')
                ->where('id', 1)
                ->forShare();

            $rows = $query->query($query);
            $this->assertCount(1, $rows);
            return true;
        });

        $this->assertTrue($result);
    }

    // ------------------------------------------------------------------
    // JSON KEY EXISTS (jsonHas / jsonMissing via Grammar)
    // ------------------------------------------------------------------

    #[Test]
    public function jsonKeyExists(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('setting')
            ->whereJsonContainsKey('metadata->priority');

        $results = $query->query($query);
        // All settings with metadata have a priority key
        $this->assertGreaterThanOrEqual(5, count($results));
    }

    #[Test]
    public function jsonKeyDoesntExist(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('setting')
            ->whereJsonDoesntContainKey('metadata->nonexistent');

        $results = $query->query($query);
        // All settings should pass (nonexistent key)
        $this->assertGreaterThanOrEqual(9, count($results));
    }

    // ------------------------------------------------------------------
    // FULLTEXT SEARCH (tsvector/tsquery)
    // ------------------------------------------------------------------

    #[Test]
    public function fulltextSearchPlain(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('post')
            ->whereFulltext(['title', 'body'], 'PHP');

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function fulltextSearchPhrase(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('post')
            ->whereFulltext(['title', 'body'], 'query builder', 'phrase');

        $results = $query->query($query);
        // May or may not find results depending on data, but should not error
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    // ------------------------------------------------------------------
    // QUERY BUILDER FEATURES
    // ------------------------------------------------------------------

    #[Test]
    public function whereWithOperators(): void
    {
        $users = User::find()->where('score', '>=', 80)->get()->all();

        $this->assertGreaterThanOrEqual(3, count($users));
        foreach ($users as $user) {
            $this->assertGreaterThanOrEqual(80, $user->score);
        }
    }

    #[Test]
    public function orderByAndLimit(): void
    {
        $users = User::find()->orderBy('score', 'DESC')->limit(3)->get()->all();

        $this->assertCount(3, $users);
        $this->assertEquals(95, $users[0]->score);
    }

    #[Test]
    public function countAggregation(): void
    {
        $count = User::find()->where('status_code', 1)->count();
        $this->assertEquals(8, $count);
    }

    #[Test]
    public function sumAggregation(): void
    {
        $total = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('order')
            ->where('status_code', 4)
            ->sum('total');

        $this->assertGreaterThan(0, $total);
    }

    #[Test]
    public function inCondition(): void
    {
        $users = User::find()->in('id', [1, 2, 3])->get()->all();
        $this->assertCount(3, $users);
    }

    #[Test]
    public function betweenCondition(): void
    {
        $users = User::find()->between('score', 50, 80)->get()->all();

        foreach ($users as $user) {
            $this->assertGreaterThanOrEqual(50, $user->score);
            $this->assertLessThanOrEqual(80, $user->score);
        }
    }

    #[Test]
    public function joinQuery(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('!post.title', '!user.username')
            ->from('post')
            ->join('user', ['id' => '!post.user_id'])
            ->where('!user.username', 'alice');

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    #[Test]
    public function statementCacheConfigurable(): void
    {
        /** @var PostgresDriver $driver */
        $driver = Connection::get(static::$connName);

        $this->assertTrue($driver->isStatementCacheEnabled());

        $driver->disableStatementCache();
        $this->assertFalse($driver->isStatementCacheEnabled());

        // Queries still work without cache
        $raw = new Raw('SELECT 1 AS result');
        $raw->withConnection(static::$connName);
        $result = $raw->fetchAll();
        $this->assertEquals(1, $result[0]['result']);

        $driver->enableStatementCache();
        $this->assertTrue($driver->isStatementCacheEnabled());
    }
}
