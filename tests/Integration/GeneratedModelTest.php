<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Simsoft\DB\Generator\ModelGenerator;
use Simsoft\DB\Model;

/**
 * Models generated from the real schema, loaded and queried.
 *
 * The unit tests build code from fake columns, which says nothing about the
 * introspection that produces those columns in practice — and that is where
 * the driver-specific defects were. So these tests introspect the actual
 * fixture tables, check PHP accepts every file, load them, and query with
 * them.
 *
 * Everything written is removed in tearDown(); nothing is written to the
 * database.
 */
class GeneratedModelTest extends DatabaseTestCase
{
    /** @var string Scratch directory for generated model files. */
    private string $dir;

    /** @var int Distinguishes each test's namespace, since a class loads once. */
    private static int $run = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fliq_gen_model_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * A namespace no earlier test has loaded a class into.
     *
     * @return string
     */
    private function freshNamespace(): string
    {
        self::$run++;

        return 'GenModel' . self::$run;
    }

    /**
     * Assert PHP accepts a generated file.
     *
     * @param string $path The file path.
     * @return void
     */
    private function assertParses(string $path): void
    {
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Generated file does not parse:\n" . implode("\n", $output));
    }

    /**
     * Every fixture table generates a file PHP accepts.
     *
     * The generated file is the deliverable, so "it parses" is the minimum
     * claim — and it is one the library's own test run cannot make for itself,
     * because the file is never loaded here otherwise.
     */
    #[Test]
    public function everyFixtureTableGeneratesAFileThatParses(): void
    {
        $tables = ModelGenerator::listTables('mysql');
        $this->assertNotEmpty($tables);

        $namespace = $this->freshNamespace();

        foreach ($tables as $table) {
            $path = ModelGenerator::fromTable($table, 'mysql')
                ->namespace($namespace)
                ->outputDir($this->dir)
                ->force()
                ->generate();

            $this->assertIsString($path, "Generation failed for '$table'.");
            $this->assertParses($path);
        }
    }

    /**
     * listTables() finds the fixture tables.
     */
    #[Test]
    public function listTablesReturnsTheFixtureTables(): void
    {
        $tables = ModelGenerator::listTables('mysql');

        foreach (['user', 'post', 'task', 'order_item'] as $expected) {
            $this->assertContains($expected, $tables);
        }
    }

    /**
     * A generated model queries the table it was generated from.
     */
    #[Test]
    public function aGeneratedModelCanQueryItsOwnTable(): void
    {
        $namespace = $this->freshNamespace();

        $path = ModelGenerator::fromTable('department', 'mysql')
            ->namespace($namespace)
            ->outputDir($this->dir)
            ->force()
            ->generate();

        $this->assertIsString($path);
        require $path;

        $class = $namespace . '\\Department';
        $this->assertTrue(class_exists($class));

        $this->assertSame(5, $class::find()->count());
    }

    /**
     * A generated hasOne relation resolves against the real schema.
     *
     * The generator infers the relation from a "*_id" column name alone, so
     * whether it points anywhere is only answerable against a database.
     */
    #[Test]
    public function aGeneratedRelationResolves(): void
    {
        $namespace = $this->freshNamespace();

        foreach (['user', 'task'] as $table) {
            $path = ModelGenerator::fromTable($table, 'mysql')
                ->namespace($namespace)
                ->outputDir($this->dir)
                ->force()
                ->generate();

            $this->assertIsString($path);
            require $path;
        }

        $taskClass = $namespace . '\\Task';
        $task = $taskClass::find()->where('id', '=', 1)->first();

        $this->assertInstanceOf(Model::class, $task);

        // The relation is a method on a class that did not exist when this
        // test was written, so it is reached the way any caller would reach a
        // generated one — by name.
        $related = $task->__get('user');
        $this->assertInstanceOf(Model::class, $related);
        $this->assertSame('alice', $related->getAttributes()['username']);
    }

    /**
     * Introspection picks up the traits the schema implies.
     *
     * The task table has deleted_at plus created_at/updated_at, so a generated
     * Task must soft-delete and timestamp — the assertion being on the fixture
     * count, which differs from the raw row count by the soft-deleted rows.
     */
    #[Test]
    public function softDeletesIsAppliedWhenTheTableHasDeletedAt(): void
    {
        $namespace = $this->freshNamespace();

        $path = ModelGenerator::fromTable('task', 'mysql')
            ->namespace($namespace)
            ->outputDir($this->dir)
            ->force()
            ->generate();

        $this->assertIsString($path);

        $code = (string)file_get_contents($path);
        $this->assertStringContainsString('use Simsoft\\DB\\Traits\\SoftDeletes;', $code);
        $this->assertStringContainsString('use Simsoft\\DB\\Traits\\Timestamps;', $code);

        require $path;

        // 10 rows, 2 of them soft-deleted: the trait is doing its job.
        $class = $namespace . '\\Task';
        $this->assertSame(8, $class::find()->count());
        $this->assertSame(10, $class::withTrashed()->count());
    }

    /**
     * Introspection reads the primary key, not a guess at one.
     */
    #[Test]
    public function aCompositePrimaryKeyIsDetected(): void
    {
        $code = ModelGenerator::fromTable('post_tag', 'mysql')
            ->namespace($this->freshNamespace())
            ->preview();

        $this->assertStringContainsString(
            "protected string|array \$primaryKey = ['post_id', 'tag_id'];",
            $code
        );
    }

    /**
     * Column types become casts, read from the live schema.
     */
    #[Test]
    public function columnTypesBecomeCasts(): void
    {
        $code = ModelGenerator::fromTable('order_item', 'mysql')
            ->namespace($this->freshNamespace())
            ->preview();

        $this->assertStringContainsString("'quantity' => 'int',", $code);
        $this->assertStringContainsString("'unit_price' => 'float',", $code);
    }

    /**
     * generate() declines to overwrite unless told to.
     */
    #[Test]
    public function anExistingFileIsNotOverwrittenWithoutForce(): void
    {
        $namespace = $this->freshNamespace();

        $first = ModelGenerator::fromTable('department', 'mysql')
            ->namespace($namespace)
            ->outputDir($this->dir)
            ->generate();

        $this->assertIsString($first);
        file_put_contents($first, '<?php /* hand-edited */');

        $second = ModelGenerator::fromTable('department', 'mysql')
            ->namespace($namespace)
            ->outputDir($this->dir)
            ->generate();

        $this->assertFalse($second, 'A second run must not overwrite by default.');
        $this->assertStringContainsString('hand-edited', (string)file_get_contents($first));

        $third = ModelGenerator::fromTable('department', 'mysql')
            ->namespace($namespace)
            ->outputDir($this->dir)
            ->force()
            ->generate();

        $this->assertIsString($third);
        $this->assertStringNotContainsString('hand-edited', (string)file_get_contents($third));
    }

    /**
     * generateAll() writes every table and reports what it skipped.
     */
    #[Test]
    public function generateAllWritesEveryTableAndReportsSkips(): void
    {
        $namespace = $this->freshNamespace();

        $first = ModelGenerator::generateAll('mysql', $namespace, $this->dir);

        $this->assertCount(12, $first['created']);
        $this->assertSame([], $first['skipped']);

        foreach ($first['created'] as $path) {
            $this->assertParses($path);
        }

        // Without force, a second pass writes nothing and says so.
        $second = ModelGenerator::generateAll('mysql', $namespace, $this->dir);

        $this->assertSame([], $second['created']);
        $this->assertCount(12, $second['skipped']);
    }

    /**
     * exclude() keeps a table out of generateAll().
     */
    #[Test]
    public function excludedTablesAreSkippedByGenerateAll(): void
    {
        ModelGenerator::exclude(['user', 'post']);

        try {
            $result = ModelGenerator::generateAll('mysql', $this->freshNamespace(), $this->dir);

            $names = array_map(
                static fn(string $path): string => basename($path, '.php'),
                $result['created']
            );

            $this->assertNotContains('User', $names);
            $this->assertNotContains('Post', $names);
            $this->assertContains('Task', $names);
        } finally {
            ModelGenerator::exclude([]);
        }
    }

    /**
     * A table PHP has no class name for is refused, naming the table.
     *
     * MySQL accepts a table name beginning with a digit. PHP does not accept a
     * class name beginning with one, so the file used to be written anyway and
     * failed as a parse error the reader had to trace back themselves.
     */
    #[Test]
    public function aTableWhoseNameIsNotAValidClassNameIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2fa_token');

        ModelGenerator::fromTable('2fa_token', 'mysql')
            ->namespace($this->freshNamespace())
            ->outputDir($this->dir)
            ->generate();
    }

    /**
     * The table name reaches SHOW COLUMNS inline, so it is checked first.
     */
    #[Test]
    public function aTableNameThatWouldBreakOutOfTheQueryIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid table name');

        ModelGenerator::fromTable('user` UNION SELECT 1,2,3,4,5,6 -- ', 'mysql')
            ->namespace($this->freshNamespace())
            ->preview();
    }
}
