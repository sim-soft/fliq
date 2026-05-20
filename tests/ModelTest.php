<?php

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Model;

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $email
 * @property int|null $status
 * @property string|null $role
 * @property mixed $meta
 */
class TestUser extends Model
{
    protected string $table = 'user';
    protected string|array $primaryKey = 'id';
    protected array $fillable = ['name', 'email', 'status'];
    protected array $casts = [
        'status' => 'int',
        'meta' => 'json',
    ];
}

class ModelTest extends TestCase
{
    #[Test]
    public function toArrayReturnsAttributes(): void
    {
        $user = new TestUser(['id' => 1, 'name' => 'John', 'email' => 'john@test.com'], false);
        $array = $user->toArray();

        $this->assertEquals(1, $array['id']);
        $this->assertEquals('John', $array['name']);
        $this->assertEquals('john@test.com', $array['email']);
    }

    #[Test]
    public function toArrayWithFieldsFilter(): void
    {
        $user = new TestUser(['id' => 1, 'name' => 'John', 'email' => 'john@test.com', 'status' => 1], false);
        $array = $user->toArray(['name', 'email']);

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('email', $array);
        $this->assertArrayNotHasKey('id', $array);
        $this->assertArrayNotHasKey('status', $array);
    }

    #[Test]
    public function toJsonReturnsString(): void
    {
        $user = new TestUser(['id' => 1, 'name' => 'John'], false);
        $json = $user->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('John', $decoded['name']);
    }

    #[Test]
    public function onlyReturnsSpecifiedFields(): void
    {
        $user = new TestUser(['id' => 1, 'name' => 'John', 'email' => 'john@test.com', 'status' => 1], false);
        $result = $user->only(['name', 'email']);

        $this->assertEquals(['name' => 'John', 'email' => 'john@test.com'], $result);
    }

    #[Test]
    public function exceptExcludesSpecifiedFields(): void
    {
        $user = new TestUser(['id' => 1, 'name' => 'John', 'email' => 'john@test.com'], false);
        $result = $user->except(['id']);

        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
    }

    #[Test]
    public function replicateCreatesNewInstance(): void
    {
        $user = new TestUser(['id' => 5, 'name' => 'John', 'email' => 'john@test.com'], false);
        $clone = $user->replicate();

        $this->assertTrue($clone->isNew());
        $this->assertEquals('John', $clone->name);
        $this->assertEquals('john@test.com', $clone->email);
        $this->assertNull($clone->id); // PK stripped
    }

    #[Test]
    public function castIntWorks(): void
    {
        $user = new TestUser(['status' => '5']);
        $this->assertSame(5, $user->status);
    }

    #[Test]
    public function castJsonEncodes(): void
    {
        $user = new TestUser(['meta' => ['tags' => ['php', 'mysql']]]);
        $attributes = $user->getAttributes();
        // JSON cast stores as encoded string on set
        $this->assertIsString($attributes['meta']);
        $this->assertStringContainsString('php', $attributes['meta']);
    }

    #[Test]
    public function castJsonDecodes(): void
    {
        $user = new TestUser(['meta' => '{"tags":["php","mysql"]}'], false);
        $meta = $user->meta;
        $this->assertIsArray($meta);
        $this->assertEquals(['php', 'mysql'], $meta['tags']);
    }

    #[Test]
    public function hydrateCreatesExistingModel(): void
    {
        $user = TestUser::hydrate(['id' => 1, 'name' => 'John', 'email' => 'john@test.com']);

        $this->assertTrue($user->exists());
        $this->assertFalse($user->isNew());
        $this->assertEquals('John', $user->name);
        $this->assertEmpty($user->getDirtyAttributes());
    }

    #[Test]
    public function fillRespectsGuarded(): void
    {
        $user = new TestUser();
        $user->fill(['id' => 99, 'name' => 'John', 'email' => 'john@test.com']);

        $this->assertEquals('John', $user->name);
        $this->assertNull($user->id); // guarded (primary key)
    }

    #[Test]
    public function fillRespectsFillable(): void
    {
        $user = new TestUser();
        $user->fill(['name' => 'John', 'email' => 'john@test.com', 'role' => 'admin']);

        $this->assertEquals('John', $user->name);
        $this->assertNull($user->role); // not in fillable
    }

    #[Test]
    public function dirtyTrackingOnNewModel(): void
    {
        $user = new TestUser();
        $user->name = 'John';
        $user->email = 'john@test.com';

        $this->assertTrue($user->isDirty());
        $this->assertContains('name', $user->getDirtyAttributes());
        $this->assertContains('email', $user->getDirtyAttributes());
    }
}
