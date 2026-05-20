<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Model;

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $email
 */
class SimpleModel extends Model
{
    protected string $table = 'users';
    protected string $connection = 'test_save';
    protected string|array $primaryKey = 'id';
    protected array $fillable = ['name', 'email'];
}

/**
 * @property int|null $org_id
 * @property int|null $user_id
 * @property string|null $role
 */
class CompositePkModel extends Model
{
    protected string $table = 'org_users';
    protected string $connection = 'test_save';
    protected string|array $primaryKey = ['org_id', 'user_id'];
    protected array $fillable = ['role'];
}

/**
 * Tests Model insert/update behavior: dirty tracking, exists flag, composite PK.
 */
class ModelSaveTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('test_save', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $driver = Connection::get('test_save');
        $driver->execute(new Raw(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)'
        ));
        $driver->execute(new Raw(
            'CREATE TABLE org_users (org_id INTEGER, user_id INTEGER, role TEXT, PRIMARY KEY (org_id, user_id))'
        ));
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function insertSetExistsToTrue(): void
    {
        $model = new SimpleModel();
        $model->name = 'John';
        $model->email = 'john@test.com';

        $this->assertTrue($model->isNew());
        $this->assertTrue($model->save(false));
        $this->assertTrue($model->exists());
        $this->assertFalse($model->isNew());
    }

    #[Test]
    public function insertSetsAutoIncrementId(): void
    {
        $model = new SimpleModel();
        $model->name = 'John';
        $model->email = 'john@test.com';
        $model->save(false);

        $this->assertSame(1, $model->id);
    }

    #[Test]
    public function insertClearsDirtyAttributes(): void
    {
        $model = new SimpleModel();
        $model->name = 'John';
        $model->email = 'john@test.com';

        $this->assertTrue($model->isDirty());
        $model->save(false);
        $this->assertFalse($model->isDirty());
        $this->assertEmpty($model->getDirtyAttributes());
    }

    #[Test]
    public function updateClearsDirtyAttributes(): void
    {
        $model = new SimpleModel();
        $model->name = 'John';
        $model->email = 'john@test.com';
        $model->save(false);

        // Modify
        $model->name = 'Jane';
        $this->assertTrue($model->isDirty());
        $this->assertContains('name', $model->getDirtyAttributes());

        $model->save(false);
        $this->assertFalse($model->isDirty());
        $this->assertEmpty($model->getDirtyAttributes());
    }

    #[Test]
    public function secondSaveAfterInsertPerformsUpdate(): void
    {
        $model = new SimpleModel();
        $model->name = 'John';
        $model->email = 'john@test.com';
        $model->save(false);

        $model->name = 'Jane';
        $model->save(false);

        // Verify the update happened
        $driver = Connection::get('test_save');
        $rows = $driver->query(new Raw('SELECT * FROM users WHERE id = ?', [1]));
        $this->assertSame('Jane', $rows[0]['name']);
    }

    #[Test]
    public function compositePkInsertSetsExistsToTrue(): void
    {
        $model = new CompositePkModel();
        $model->org_id = 1;
        $model->user_id = 42;
        $model->role = 'admin';

        $this->assertTrue($model->isNew());
        $model->save(false);

        $this->assertTrue($model->exists());
        $this->assertFalse($model->isNew());
    }

    #[Test]
    public function compositePkInsertClearsDirtyAttributes(): void
    {
        $model = new CompositePkModel();
        $model->org_id = 1;
        $model->user_id = 42;
        $model->role = 'admin';

        $model->save(false);
        $this->assertFalse($model->isDirty());
    }

    #[Test]
    public function compositePkSecondSavePerformsUpdate(): void
    {
        $model = new CompositePkModel();
        $model->org_id = 1;
        $model->user_id = 42;
        $model->role = 'admin';
        $model->save(false);

        $model->role = 'member';
        $model->save(false);

        $driver = Connection::get('test_save');
        $rows = $driver->query(new Raw('SELECT * FROM org_users WHERE org_id = ? AND user_id = ?', [1, 42]));
        $this->assertSame('member', $rows[0]['role']);
    }

    #[Test]
    public function wasRecentlyCreatedIsTrueAfterInsert(): void
    {
        $model = new SimpleModel();
        $model->name = 'John';
        $model->email = 'john@test.com';
        $model->save(false);

        $this->assertTrue($model->wasRecentlyCreated());
    }

    #[Test]
    public function updateWithNoDirtyAttributesReturnsTrue(): void
    {
        $model = new SimpleModel();
        $model->name = 'John';
        $model->email = 'john@test.com';
        $model->save(false);

        // No changes — save should return true (nothing to update)
        $result = $model->save(false);
        $this->assertTrue($result);
    }

    #[Test]
    public function hydratedModelIsNotDirty(): void
    {
        $model = SimpleModel::hydrate(['id' => 1, 'name' => 'John', 'email' => 'john@test.com']);

        $this->assertTrue($model->exists());
        $this->assertFalse($model->isDirty());
    }

    #[Test]
    public function hydratedModelBecomesDirtyOnChange(): void
    {
        $model = SimpleModel::hydrate(['id' => 1, 'name' => 'John', 'email' => 'john@test.com']);

        $model->name = 'Jane';
        $this->assertTrue($model->isDirty());
        $this->assertContains('name', $model->getDirtyAttributes());
    }
}
