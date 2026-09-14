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
     * @param array<int, array<string, mixed>> $columns
     * @param string $namespace The namespace.
     * @return TestableModelGenerator
     */
    private function createGenerator(string $table, array $columns, string $namespace = 'App\\Models'): TestableModelGenerator
    {
        // Add rawType if not present (backward compat for simpler test cases)
        foreach ($columns as $idx => $col) {
            if (!isset($col['rawType'])) {
                $columns[$idx]['rawType'] = $col['type'];
            }
        }

        $generator = new TestableModelGenerator($table, $columns);
        $generator->namespace($namespace);
        return $generator;
    }

    // ------------------------------------------------------------------
    // BASIC GENERATION
    // ------------------------------------------------------------------

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
    public function generatesGuardedProperty(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringContainsString("protected array \$guarded = ['id'];", $code);
    }

    #[Test]
    public function generatesConnectionProperty(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringContainsString("protected string \$connection = 'default';", $code);
    }

    // ------------------------------------------------------------------
    // PRIMARY KEY
    // ------------------------------------------------------------------

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
        $this->assertStringContainsString("protected array \$guarded = ['uuid'];", $code);
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
    public function detectsCompositePrimaryKey(): void
    {
        $columns = [
            ['name' => 'post_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'tag_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
        ];

        $code = $this->createGenerator('post_tag', $columns)->preview();

        $this->assertStringContainsString("protected string|array \$primaryKey = ['post_id', 'tag_id'];", $code);
        $this->assertStringContainsString("'post_id',", $code);
        $this->assertStringContainsString("'tag_id',", $code);
    }

    // ------------------------------------------------------------------
    // TRAITS
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // CASTS
    // ------------------------------------------------------------------

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

        $this->assertStringNotContainsString("'name' => '", $code);
        $this->assertStringNotContainsString("'bio' => '", $code);
    }

    // ------------------------------------------------------------------
    // ENUM COMMENTS
    // ------------------------------------------------------------------

    #[Test]
    public function detectsMysqlEnumValues(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'rawType' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'role', 'type' => 'varchar', 'rawType' => "enum('admin','editor','member')", 'nullable' => false, 'default' => 'member', 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringContainsString('admin,editor,member', $code);
    }

    // ------------------------------------------------------------------
    // RELATIONS
    // ------------------------------------------------------------------

    #[Test]
    public function detectsHasOneRelationFromForeignKey(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'user_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('post', $columns)->preview();

        $this->assertStringContainsString('use Simsoft\\DB\\Relation;', $code);
        $this->assertStringContainsString('public function user(): Relation', $code);
        $this->assertStringContainsString("hasOne(User::class, ['id' => 'user_id'])", $code);
    }

    #[Test]
    public function detectsMultipleRelations(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'user_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'category_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('post', $columns)->preview();

        $this->assertStringContainsString('public function user(): Relation', $code);
        $this->assertStringContainsString('public function category(): Relation', $code);
    }

    #[Test]
    public function noRelationForNonForeignKeyColumns(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'score', 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('user', $columns)->preview();

        $this->assertStringNotContainsString('Relation', $code);
        $this->assertStringNotContainsString('hasOne', $code);
    }

    // ------------------------------------------------------------------
    // PHPDOC
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // TABLE NAME CONVERSION
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // FULL MODEL
    // ------------------------------------------------------------------

    #[Test]
    public function fullModelWithAllFeatures(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'serial', 'rawType' => 'serial', 'nullable' => false, 'default' => null, 'primary' => true],
            ['name' => 'user_id', 'type' => 'int', 'rawType' => 'int', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'title', 'type' => 'varchar', 'rawType' => 'varchar(200)', 'nullable' => false, 'default' => null, 'primary' => false],
            ['name' => 'body', 'type' => 'text', 'rawType' => 'text', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'view_count', 'type' => 'int', 'rawType' => 'int', 'nullable' => false, 'default' => '0', 'primary' => false],
            ['name' => 'is_published', 'type' => 'boolean', 'rawType' => 'bool', 'nullable' => false, 'default' => 'false', 'primary' => false],
            ['name' => 'metadata', 'type' => 'jsonb', 'rawType' => 'jsonb', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'created_at', 'type' => 'timestamp', 'rawType' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'updated_at', 'type' => 'timestamp', 'rawType' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'rawType' => 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false],
        ];

        $code = $this->createGenerator('blog_post', $columns, 'App\\Models')->preview();

        // Class structure
        $this->assertStringContainsString('class BlogPost extends Model', $code);
        $this->assertStringContainsString("protected string \$table = 'blog_post';", $code);

        // Traits
        $this->assertStringContainsString('use SoftDeletes;', $code);
        $this->assertStringContainsString('use Timestamps;', $code);

        // Relation
        $this->assertStringContainsString('public function user(): Relation', $code);

        // Guarded
        $this->assertStringContainsString("protected array \$guarded = ['id'];", $code);

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
}

/**
 * Testable subclass that bypasses database introspection.
 */
class TestableModelGenerator extends ModelGenerator
{
    /** @var array<int, array<string, mixed>> */
    private array $fakeColumns;

    /**
     * @param string $table The table name.
     * @param array<int, array<string, mixed>> $columns
     * @param string $connectionName The connection name to write into the file.
     */
    public function __construct(string $table, array $columns, string $connectionName = 'default')
    {
        $this->fakeColumns = $columns;
        $this->setTable($table, $connectionName);
    }

    /**
     * Set the table name directly (bypasses parent constructor).
     *
     * The connection name is put through the real check rather than assigned
     * straight to the property. The parent constructor is what normally runs
     * it, and this double replaces the parent constructor — so assigning
     * directly would make the double the one place the check does not happen,
     * which is the opposite of what a test of that check needs.
     *
     * @param string $table The table name.
     * @param string $connectionName The connection name.
     * @return void
     */
    private function setTable(string $table, string $connectionName): void
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);

        $reflection->getMethod('assertConnectionName')->invoke(null, $connectionName);

        $prop = $reflection->getProperty('table');
        $prop->setValue($this, $table);

        $connProp = $reflection->getProperty('connectionName');
        $connProp->setValue($this, $connectionName);
    }

    /**
     * Override preview to use fake columns instead of introspecting.
     *
     * The class name is resolved by the real method rather than a copy of it.
     * A double that derives the name its own way cannot show what the real
     * generator does with a name PHP will not accept — which is the whole of
     * what ModelGeneratorSafetyTest is checking.
     *
     * @return string
     */
    public function preview(): string
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);

        $resolve = $reflection->getMethod('resolveClassName');
        $className = $resolve->invoke($this);

        $buildMethod = $reflection->getMethod('buildClassCode');
        return $buildMethod->invoke($this, $className, $this->fakeColumns);
    }
}
