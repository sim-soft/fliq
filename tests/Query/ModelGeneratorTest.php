<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Generator\ModelGenerator;

/**
 * Unit tests for ModelGenerator code generation logic.
 *
 * Uses a testable subclass to inject fake column data without a database connection.
 */
class ModelGeneratorTest extends TestCase
{
    /**
     * Create a testable generator that returns predefined columns.
     *
     * @param string $table The table name.
     * @param array<int, array{name: string, type: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @param string $namespace The namespace.
     * @return TestableModelGenerator
     */
    private function createGenerator(string $table, array $columns, string $namespace = 'App\\Models'): TestableModelGenerator
    {
        $generator = new TestableModelGenerator($table, $columns);
        $generator->namespace($namespace);
        return $generator;
    }

    #[Test]
    public function generatesBasicModel(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'email', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringContainsString("namespace App\\Models;", $code);
        $this->assertStringContainsString('use Simsoft\\DB\\Model;', $code);
        $this->assertStringContainsString('class User extends Model', $code);
        $this->assertStringContainsString("protected string \$table = 'user';", $code);
        $this->assertStringContainsString("'name',", $code);
        $this->assertStringContainsString("'email',", $code);
    }

    #[Test]
    public function excludesPrimaryKeyFromFillable(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('post', $columns)->preview();

        $this->assertStringNotContainsString("'id',", $code);
        $this->assertStringContainsString("'title',", $code);
    }

    #[Test]
    public function detectsNonIdPrimaryKey(): void
    {
        $columns = [
            ['name' => 'uuid', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('entity', $columns)->preview();

        $this->assertStringContainsString("protected string|array \$primaryKey = 'uuid';", $code);
    }

    #[Test]
    public function omitsPrimaryKeyPropertyWhenId(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringNotContainsString('$primaryKey', $code);
    }

    #[Test]
    public function detectsSoftDeletesTrait(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('post', $columns)->preview();

        $this->assertStringContainsString('use Simsoft\\DB\\Traits\\SoftDeletes;', $code);
        $this->assertStringContainsString('use SoftDeletes;', $code);
    }

    #[Test]
    public function detectsTimestampsTrait(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('task', $columns)->preview();

        $this->assertStringContainsString('use Simsoft\\DB\\Traits\\Timestamps;', $code);
        $this->assertStringContainsString('use Timestamps;', $code);
    }

    #[Test]
    public function detectsTimestampsTraitWithShortNames(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'created', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'updated', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringContainsString('use Simsoft\\DB\\Traits\\Timestamps;', $code);
        $this->assertStringContainsString('use Timestamps;', $code);
        $this->assertStringNotContainsString("'created',", $code);
        $this->assertStringNotContainsString("'updated',", $code);
    }

    #[Test]
    public function excludesTimestampColumnsFromFillable(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('task', $columns)->preview();

        $this->assertStringNotContainsString("'created_at',", $code);
        $this->assertStringNotContainsString("'updated_at',", $code);
        $this->assertStringNotContainsString("'deleted_at',", $code);
        $this->assertStringContainsString("'title',", $code);
    }

    #[Test]
    public function castsIntegerColumns(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'score', 'type' => 'int', 'nullable' => false, 'default' => '0', 'primary' => false],
            ['name' => 'status_code', 'type' => 'smallint', 'nullable' => false, 'default' => '1', 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringContainsString("'score' => 'int',", $code);
        $this->assertStringContainsString("'status_code' => 'int',", $code);
    }

    #[Test]
    public function castsBooleanColumns(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'default' => 'true', 'primary' => false],
        ];

        $code = $this->createGenerator('feature', $columns)->preview();

        $this->assertStringContainsString("'is_active' => 'bool',", $code);
    }

    #[Test]
    public function castsFloatColumns(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'default' => '0.00', 'primary' => false],
            ['name' => 'weight', 'type' => 'float', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('product', $columns)->preview();

        $this->assertStringContainsString("'price' => 'float',", $code);
        $this->assertStringContainsString("'weight' => 'float',", $code);
    }

    #[Test]
    public function castsJsonColumns(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'metadata', 'type' => 'jsonb', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'tags', 'type' => 'json', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('setting', $columns)->preview();

        $this->assertStringContainsString("'metadata' => 'json',", $code);
        $this->assertStringContainsString("'tags' => 'json',", $code);
    }

    #[Test]
    public function noCastsForStringColumns(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'bio', 'type' => 'text', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringNotContainsString("'name' =>", $code);
        $this->assertStringNotContainsString("'bio' =>", $code);
        $this->assertStringNotContainsString('$casts', $code);
    }

    #[Test]
    public function phpDocShowsNullableTypes(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'phone', 'type' => 'varchar', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'score', 'type' => 'int', 'nullable' => false, 'default' => '0', 'primary' => false],
        ];

        $code = $this->createGenerator('profile', $columns)->preview();

        $this->assertStringContainsString('@property int $id', $code);
        $this->assertStringContainsString('@property string|null $phone', $code);
        $this->assertStringContainsString('@property int $score', $code);
    }

    #[Test]
    public function phpDocShowsJsonAsArray(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'metadata', 'type' => 'jsonb', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('setting', $columns)->preview();

        $this->assertStringContainsString('@property array|null $metadata', $code);
    }

    #[Test]
    public function convertsSnakeCaseTableToClassName(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'user_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('user_profile', $columns)->preview();

        $this->assertStringContainsString('class UserProfile extends Model', $code);
        $this->assertStringContainsString("protected string \$table = 'user_profile';", $code);
    }

    #[Test]
    public function respectsCustomNamespace(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
        ];

        $code = $this->createGenerator('order', $columns, 'App\\Domain\\Billing')->preview();

        $this->assertStringContainsString('namespace App\\Domain\\Billing;', $code);
    }

    #[Test]
    public function generatesValidPhpOpenTag(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
        ];

        $code = $this->createGenerator('item', $columns)->preview();

        $this->assertStringStartsWith('<?php', $code);
    }

    #[Test]
    public function fullModelWithAllFeatures(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'serial', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'user_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'body', 'type' => 'text', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'view_count', 'type' => 'int', 'nullable' => false, 'default' => '0', 'primary' => false],
            ['name' => 'is_published', 'type' => 'boolean', 'nullable' => false, 'default' => 'false', 'primary' => false],
            ['name' => 'metadata', 'type' => 'jsonb', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('blog_post', $columns, 'App\\Models')->preview();

        // Class structure
        $this->assertStringContainsString('class BlogPost extends Model', $code);
        $this->assertStringContainsString("protected string \$table = 'blog_post';", $code);

        // Traits
        $this->assertStringContainsString('use SoftDeletes;', $code);
        $this->assertStringContainsString('use Timestamps;', $code);

        // Fillable (excludes id, timestamps)
        $this->assertStringContainsString("'user_id',", $code);
        $this->assertStringContainsString("'title',", $code);
        $this->assertStringContainsString("'body',", $code);
        $this->assertStringContainsString("'view_count',", $code);
        $this->assertStringContainsString("'is_published',", $code);
        $this->assertStringContainsString("'metadata',", $code);

        // Casts
        $this->assertStringContainsString("'user_id' => 'int',", $code);
        $this->assertStringContainsString("'view_count' => 'int',", $code);
        $this->assertStringContainsString("'is_published' => 'bool',", $code);
        $this->assertStringContainsString("'metadata' => 'json',", $code);

        // PHPDoc
        $this->assertStringContainsString('@property int $id', $code);
        $this->assertStringContainsString('@property string $title', $code);
        $this->assertStringContainsString('@property string|null $body', $code);
        $this->assertStringContainsString('@property bool $is_published', $code);
        $this->assertStringContainsString('@property array|null $metadata', $code);
    }

    /** @return array<string, array{string, string}> */
    public static function tableNameProvider(): array
    {
        return [
            'simple' => ['user', 'User'],
            'snake_case' => ['user_profile', 'UserProfile'],
            'multi_word' => ['order_line_item', 'OrderLineItem'],
            'hyphenated' => ['blog-post', 'BlogPost'],
            'single_char_segments' => ['a_b_c', 'ABC'],
        ];
    }

    #[Test]
    #[DataProvider('tableNameProvider')]
    public function tableToClassNameConversion(string $table, string $expected): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
        ];

        $code = $this->createGenerator($table, $columns)->preview();

        $this->assertStringContainsString("class $expected extends Model", $code);
    }
}

/**
 * Testable subclass that bypasses database introspection.
 */
class TestableModelGenerator extends ModelGenerator
{
    /** @var array<int, array{name: string, type: string, nullable: bool, default: mixed, primary: bool}> */
    private array $fakeColumns;

    /**
     * @param string $table The table name.
     * @param array<int, array{name: string, type: string, nullable: bool, default: mixed, primary: bool}> $columns
     */
    public function __construct(string $table, array $columns)
    {
        $this->fakeColumns = $columns;
        // Skip parent constructor to avoid Connection dependency
        $this->setTable($table);
    }

    /**
     * Set the table name directly (bypasses parent constructor).
     *
     * @param string $table The table name.
     * @return void
     */
    private function setTable(string $table): void
    {
        // Use reflection to set the private property
        $reflection = new \ReflectionClass(ModelGenerator::class);
        $prop = $reflection->getProperty('table');
        $prop->setValue($this, $table);

        $connProp = $reflection->getProperty('connectionName');
        $connProp->setValue($this, 'default');
    }

    /**
     * Override preview to use fake columns instead of introspecting.
     *
     * @return string
     */
    public function preview(): string
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);

        $tableMethod = $reflection->getMethod('tableToClassName');
        $className = $tableMethod->invoke($this, $this->getTable());

        $buildMethod = $reflection->getMethod('buildClassCode');
        return $buildMethod->invoke($this, $className, $this->fakeColumns);
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    private function getTable(): string
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);
        $prop = $reflection->getProperty('table');
        return $prop->getValue($this);
    }
}
