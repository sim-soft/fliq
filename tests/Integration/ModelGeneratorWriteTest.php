<?php

namespace Integration;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Generator\ModelGenerator;

/**
 * What the generator does with the filesystem, and what it reports afterwards.
 *
 * generate() is documented to answer the path it wrote, and generateAll()
 * counts that answer into 'created'. Both returns from the write — mkdir() and
 * file_put_contents() — used to be discarded, so a run into a directory that
 * could not be created printed two PHP warnings and then reported every table
 * as created with nothing on disk. A scaffolding command reading that reports
 * success and exits 0.
 *
 * The SQLite introspection path is exercised here too. It is the one of the
 * three the unit tests cannot reach, since they supply columns directly rather
 * than reading a schema, and PRAGMA answers a different shape from either
 * SHOW COLUMNS or information_schema.
 */
class ModelGeneratorWriteTest extends TestCase
{
    /** @var string Scratch directory for generated files. */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fliq_gen_write_' . uniqid();
        mkdir($this->dir, 0755, true);

        Connection::add('gen', ['driver' => 'sqlite', 'database' => ':memory:']);

        $driver = Connection::get('gen');
        $driver->execute(new Raw(
            'CREATE TABLE article (
                id INTEGER PRIMARY KEY,
                author_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                body TEXT,
                views INT DEFAULT 0,
                rating REAL,
                meta JSON,
                is_live BOOLEAN NOT NULL DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME,
                deleted_at DATETIME
            )'
        ));
        $driver->execute(new Raw('CREATE TABLE widget (id INTEGER PRIMARY KEY, label TEXT)'));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);

        if (Connection::has('gen')) {
            Connection::remove('gen');
        }
    }

    /**
     * Delete a directory and everything under it.
     *
     * @param string $path The directory to remove.
     * @return void
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }

        rmdir($path);
    }

    // ------------------------------------------------------------------
    // A path it could not write must not be reported as written
    // ------------------------------------------------------------------

    #[Test]
    public function aDirectoryThatCannotBeCreatedIsReportedRatherThanIgnored(): void
    {
        // A plain file where a directory would have to go: mkdir() cannot
        // succeed, and every write beneath it fails too.
        $blocker = $this->dir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'a file, not a directory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create output directory');

        ModelGenerator::fromTable('article', 'gen')
            ->outputDir($blocker . DIRECTORY_SEPARATOR . 'models')
            ->generate();
    }

    #[Test]
    public function generateAllStopsRatherThanCountingFilesItNeverWrote(): void
    {
        // The reported count is the whole value of generateAll()'s return, so
        // reporting two files created into a directory that does not exist is
        // worse than failing: the caller has no other way to find out.
        $blocker = $this->dir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'a file, not a directory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create output directory');

        ModelGenerator::generateAll('gen', 'Probe\\Gen', $blocker . DIRECTORY_SEPARATOR . 'models');
    }

    #[Test]
    public function aFileThatCannotBeWrittenIsReportedRatherThanIgnored(): void
    {
        // A different failure from the one above: the output directory exists,
        // so mkdir() is never reached and only the write fails. A directory
        // standing where the model file has to go does that — and it is not
        // contrived, since a stray `Article.php/` from an unpacked archive or a
        // half-finished copy looks exactly like this.
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'Article.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write model file');

        ModelGenerator::fromTable('article', 'gen')
            ->namespace('Probe\\Gen')
            ->outputDir($this->dir)
            ->force()
            ->generate();
    }

    #[Test]
    public function anOrdinaryGenerateStillWritesAndReportsItsPath(): void
    {
        $path = ModelGenerator::fromTable('article', 'gen')
            ->namespace('Probe\\Gen')
            ->outputDir($this->dir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deep')
            ->generate();

        $this->assertIsString($path);
        $this->assertFileExists($path);
        $this->assertStringContainsString('class Article extends Model', (string)file_get_contents($path));
    }

    #[Test]
    public function anExistingFileIsStillSkippedRatherThanTreatedAsAFailure(): void
    {
        // "Skipped" and "could not be written" are different answers, and the
        // new guard must not have collapsed them: false still means the file
        // was already there and force is off.
        $generator = fn(): ModelGenerator => ModelGenerator::fromTable('article', 'gen')
            ->namespace('Probe\\Gen')
            ->outputDir($this->dir);

        $first = $generator()->generate();

        $this->assertIsString($first);
        $this->assertFalse($generator()->generate(), 'the second run finds the file and skips');
        $this->assertSame($first, $generator()->force()->generate(), 'force writes it again');
    }

    #[Test]
    public function generateAllReportsWhatItActuallyDid(): void
    {
        $first = ModelGenerator::generateAll('gen', 'Probe\\Gen', $this->dir);

        $this->assertCount(2, $first['created']);
        $this->assertSame([], $first['skipped']);

        foreach ($first['created'] as $path) {
            $this->assertFileExists($path);
        }

        $second = ModelGenerator::generateAll('gen', 'Probe\\Gen', $this->dir);

        $this->assertSame([], $second['created'], 'nothing left to write');
        $this->assertCount(2, $second['skipped']);
    }

    #[Test]
    public function aBadNamespaceFailsBeforeAnyFileIsWritten(): void
    {
        // generateAll() writes as it goes, so a namespace checked per table
        // would leave the directory half full before the failure. Checking it
        // up front is what makes the failure leave nothing behind.
        try {
            ModelGenerator::generateAll('gen', 'Not A Namespace', $this->dir);
            $this->fail('an unusable namespace was accepted');
        } catch (AssertionFailedError $failure) {
            // PHPUnit's fail() throws this, and it is a RuntimeException — so
            // without this the catch below swallows it and reports the failure
            // message as the exception it was checking.
            throw $failure;
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Invalid namespace', $exception->getMessage());
        }

        $this->assertSame(
            [],
            glob($this->dir . DIRECTORY_SEPARATOR . '*.php') ?: [],
            'nothing was written before the refusal'
        );
    }

    #[Test]
    public function anEmptyDatabaseStillRefusesABadNamespace(): void
    {
        // The per-table namespace() call raises on the first table, so for any
        // non-empty database the up-front check makes no difference to what is
        // written. This is the case where it does: with no tables the loop
        // never runs, and without the check generateAll() would answer
        // "created nothing, skipped nothing" for a namespace it should have
        // refused — the caller would read that as an empty database rather than
        // as a mistake in the argument they passed.
        Connection::add('empty_gen', ['driver' => 'sqlite', 'database' => ':memory:']);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid namespace');

            ModelGenerator::generateAll('empty_gen', 'Not A Namespace', $this->dir);
        } finally {
            Connection::remove('empty_gen');
        }
    }

    // ------------------------------------------------------------------
    // SQLite introspection
    // ------------------------------------------------------------------

    #[Test]
    public function sqliteColumnsAreIntrospectedFromPragma(): void
    {
        // PRAGMA table_info answers name/type/notnull/dflt_value/pk, which is a
        // different shape from both SHOW COLUMNS and information_schema. Every
        // field the generator reads out of it is checked here, because a
        // mis-read column is a wrong @property line rather than an error.
        $code = ModelGenerator::fromTable('article', 'gen')->namespace('Probe\\Gen')->preview();

        $this->assertStringContainsString('class Article extends Model', $code);

        // pk = 1 → primary key, so guarded and absent from fillable.
        $this->assertStringContainsString("protected array \$guarded = ['id'];", $code);
        $this->assertStringNotContainsString("        'id',", $code);

        // notnull → nullability in the PHPDoc.
        $this->assertStringContainsString('@property int $author_id', $code);
        $this->assertStringContainsString('@property string|null $body', $code);

        // type → the cast map.
        $this->assertStringContainsString("'author_id' => 'int',", $code);
        $this->assertStringContainsString("'rating' => 'float',", $code);
        $this->assertStringContainsString("'meta' => 'json',", $code);
        $this->assertStringContainsString("'is_live' => 'bool',", $code);

        // Column names drive trait detection and relations.
        $this->assertStringContainsString('use SoftDeletes;', $code);
        $this->assertStringContainsString('use Timestamps;', $code);
        $this->assertStringContainsString('public function author(): Relation', $code);
    }

    #[Test]
    public function sqliteTablesAreListedWithoutInternalOnes(): void
    {
        $tables = ModelGenerator::listTables('gen');

        $this->assertSame(['article', 'widget'], $tables);
    }

    #[Test]
    public function generatedSqliteModelsParse(): void
    {
        // The point of the generator is a file PHP will load, and SQLite is the
        // one introspection path nothing else in the suite writes a file from.
        foreach (ModelGenerator::generateAll('gen', 'Probe\\Gen', $this->dir)['created'] as $path) {
            exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, "Generated code does not parse:\n" . implode("\n", $output));
        }
    }
}
