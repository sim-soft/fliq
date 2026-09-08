<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;
use Simsoft\DB\Generator\ModelGenerator;
use Simsoft\DB\Model;
use Throwable;

/**
 * Model generation against PostgreSQL, whose introspection is its own query.
 *
 * The column list on PostgreSQL is assembled from information_schema rather
 * than a SHOW, and that query joined the constraint tables in a way that
 * returned a column once per constraint it belonged to. A column that was both
 * UNIQUE and a foreign key therefore arrived three times, and each copy became
 * another property line and another relation method — "Cannot redeclare" in a
 * file the reader had not written. The fixture's user_profile.user_id is
 * exactly that shape, so the schema here reproduces it deliberately.
 *
 * Requires ext-pdo_pgsql and a running PostgreSQL server; skipped otherwise.
 */
class GeneratedModelPostgresTest extends TestCase
{
    /** @var bool Whether a PostgreSQL server answered at setup. */
    private static bool $available = false;

    /** @var string Scratch table, created and dropped by this class alone. */
    private const TABLE = 'gen_model_pg_fixture';

    /** @var string The table the scratch table points at. */
    private const PARENT_TABLE = 'gen_model_pg_parent';

    /** @var string Scratch directory for generated files. */
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add('genpg', [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: 'postgres',
            'charset' => 'utf8',
            'schema' => 'public',
        ]);

        try {
            Connection::get('genpg');
            self::$available = true;
        } catch (Throwable) {
            self::$available = false;

            return;
        }

        DB::raw('DROP TABLE IF EXISTS ' . self::TABLE, [], 'genpg');
        DB::raw('DROP TABLE IF EXISTS ' . self::PARENT_TABLE, [], 'genpg');

        DB::raw('CREATE TABLE ' . self::PARENT_TABLE . ' (id SERIAL PRIMARY KEY, label VARCHAR(50))', [], 'genpg');

        // owner_id carries three constraints at once: UNIQUE, FOREIGN KEY and
        // (through neither) an ordinary column. That is the shape that used to
        // come back from introspection three times over.
        DB::raw(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id SERIAL PRIMARY KEY, '
            . 'owner_id INT UNIQUE REFERENCES ' . self::PARENT_TABLE . '(id), '
            . 'title VARCHAR(100) NOT NULL, '
            . 'amount NUMERIC(10,2), '
            . 'active BOOLEAN DEFAULT true)',
            [],
            'genpg'
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$available) {
            return;
        }

        DB::raw('DROP TABLE IF EXISTS ' . self::TABLE, [], 'genpg');
        DB::raw('DROP TABLE IF EXISTS ' . self::PARENT_TABLE, [], 'genpg');
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            $this->markTestSkipped('PostgreSQL is not available.');
        }

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fliq_gen_pg_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir)) {
            return;
        }

        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * A column in several constraints is introspected once.
     */
    #[Test]
    public function aColumnInSeveralConstraintsAppearsOnlyOnce(): void
    {
        $code = ModelGenerator::fromTable(self::TABLE, 'genpg')
            ->namespace('GenPg')
            ->preview();

        $this->assertSame(1, substr_count($code, '@property int|null $owner_id'));
        $this->assertSame(1, substr_count($code, "'owner_id',"));
        $this->assertSame(1, substr_count($code, 'public function owner(): Relation'));
    }

    /**
     * The generated file parses — the duplicate methods made it a fatal error.
     */
    #[Test]
    public function theGeneratedFileParses(): void
    {
        $path = ModelGenerator::fromTable(self::TABLE, 'genpg')
            ->namespace('GenPg')
            ->outputDir($this->dir)
            ->force()
            ->generate();

        $this->assertIsString($path);

        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, "Generated file does not parse:\n" . implode("\n", $output));
    }

    /**
     * The primary key is still read correctly after the join was rewritten.
     *
     * Restricting the constraint lookup to the primary key is what removed the
     * duplicates; this is the check that it did not also remove the answer.
     */
    #[Test]
    public function thePrimaryKeyIsStillDetected(): void
    {
        $code = ModelGenerator::fromTable(self::TABLE, 'genpg')
            ->namespace('GenPg')
            ->preview();

        // id is the primary key, so it is guarded rather than fillable, and no
        // $primaryKey override is emitted for the default name.
        $this->assertStringContainsString("protected array \$guarded = ['id'];", $code);
        $this->assertStringNotContainsString("'id',\n", $code);
    }

    /**
     * A non-default primary key name is read from the schema, not assumed.
     */
    #[Test]
    public function aNonDefaultPrimaryKeyNameIsRead(): void
    {
        DB::raw('DROP TABLE IF EXISTS gen_model_pg_oddpk', [], 'genpg');
        DB::raw('CREATE TABLE gen_model_pg_oddpk (code VARCHAR(20) PRIMARY KEY, note TEXT)', [], 'genpg');

        try {
            $code = ModelGenerator::fromTable('gen_model_pg_oddpk', 'genpg')
                ->namespace('GenPg')
                ->preview();

            $this->assertStringContainsString("protected string|array \$primaryKey = 'code';", $code);
            $this->assertStringContainsString("protected array \$guarded = ['code'];", $code);
        } finally {
            DB::raw('DROP TABLE IF EXISTS gen_model_pg_oddpk', [], 'genpg');
        }
    }

    /**
     * PostgreSQL types map to casts through udt_name.
     */
    #[Test]
    public function postgresTypesBecomeCasts(): void
    {
        $code = ModelGenerator::fromTable(self::TABLE, 'genpg')
            ->namespace('GenPg')
            ->preview();

        $this->assertStringContainsString("'owner_id' => 'int',", $code);
        $this->assertStringContainsString("'amount' => 'float',", $code);
        $this->assertStringContainsString("'active' => 'bool',", $code);
    }

    /**
     * Nullability comes from the schema.
     */
    #[Test]
    public function nullabilityIsReadFromTheSchema(): void
    {
        $code = ModelGenerator::fromTable(self::TABLE, 'genpg')
            ->namespace('GenPg')
            ->preview();

        $this->assertStringContainsString('@property string $title', $code);
        $this->assertStringContainsString('@property float|null $amount', $code);
    }

    /**
     * listTables() works on PostgreSQL, which uses its own catalog query.
     */
    #[Test]
    public function listTablesFindsTheScratchTable(): void
    {
        $tables = ModelGenerator::listTables('genpg');

        $this->assertContains(self::TABLE, $tables);
        $this->assertContains(self::PARENT_TABLE, $tables);
    }

    /**
     * The generated model queries PostgreSQL through the connection it names.
     */
    #[Test]
    public function theGeneratedModelCanQueryPostgres(): void
    {
        DB::raw('INSERT INTO ' . self::PARENT_TABLE . ' (label) VALUES (?)', ['owner one'], 'genpg');
        DB::raw(
            'INSERT INTO ' . self::TABLE . ' (owner_id, title, amount, active) '
            . 'VALUES ((SELECT id FROM ' . self::PARENT_TABLE . ' LIMIT 1), ?, ?, ?)',
            ['generated row', '12.50', true],
            'genpg'
        );

        try {
            $path = ModelGenerator::fromTable(self::TABLE, 'genpg')
                ->namespace('GenPgLive')
                ->outputDir($this->dir)
                ->force()
                ->generate();

            $this->assertIsString($path);
            require $path;

            $class = 'GenPgLive\\GenModelPgFixture';
            $this->assertTrue(class_exists($class));

            $row = $class::find()->where('title', '=', 'generated row')->first();
            $this->assertInstanceOf(Model::class, $row);

            // Read through toArray(), which presents cast values: the class did
            // not exist when this test was written, so its properties are not
            // statically known.
            $values = $row->toArray();
            $this->assertSame(12.5, $values['amount']);
            $this->assertTrue($values['active']);
        } finally {
            DB::raw('DELETE FROM ' . self::TABLE, [], 'genpg');
            DB::raw('DELETE FROM ' . self::PARENT_TABLE, [], 'genpg');
        }
    }
}
