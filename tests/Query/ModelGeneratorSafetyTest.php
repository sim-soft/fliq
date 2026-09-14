<?php

namespace Query;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\DB\Generator\ModelGenerator;

/**
 * The generator's output has to be a file PHP will load.
 *
 * Everything here is about a failure that lands in the reader's project rather
 * than in this repository: a generated file that does not parse, or parses and
 * then dies at load, is not something this library's own test run would notice.
 * A table name comes from a database, not from a developer typing it, so the
 * generator meets names it did not choose.
 */
class ModelGeneratorSafetyTest extends TestCase
{
    /** @var string Scratch directory for generated files. */
    private string $dir;

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
     * Build a generator over fake columns, so no database is needed.
     *
     * @param string $table The table name.
     * @param array<int, array<string, mixed>> $columns The fake columns.
     * @param string $connectionName The connection name to write into the file.
     * @return TestableModelGenerator
     */
    private function generator(string $table, array $columns, string $connectionName = 'default'): TestableModelGenerator
    {
        foreach ($columns as $index => $column) {
            if (!isset($column['rawType'])) {
                $columns[$index]['rawType'] = $column['type'];
            }
        }

        $generator = new TestableModelGenerator($table, $columns, $connectionName);
        $generator->namespace('Probe\\Gen');

        return $generator;
    }

    /**
     * A plain int column definition.
     *
     * @param string $name The column name.
     * @param bool $primary Whether it is the primary key.
     * @return array<string, mixed>
     */
    private function intColumn(string $name, bool $primary = false): array
    {
        return ['name' => $name, 'type' => 'int', 'nullable' => false, 'default' => null, 'primary' => $primary];
    }

    /**
     * Assert PHP accepts the given code.
     *
     * This shells out to `php -l` rather than tokenizing in-process, which
     * would be much cheaper. token_get_all(TOKEN_PARSE) only parses: it
     * accepts a class declaring the same method twice, and it accepts a void
     * method that returns a value. Both are compile errors, and the first is
     * one of the things this file exists to catch — so the cheap check would
     * have passed the very code the tests are here to reject.
     *
     * @param string $code The PHP code, including its opening tag.
     * @return void
     */
    private function assertCodeParses(string $code): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'lint_' . uniqid() . '.php';
        file_put_contents($path, $code);

        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Generated code does not parse:\n" . implode("\n", $output));
    }

    // ------------------------------------------------------------------
    // TABLE NAMES THAT REACH SQL
    // ------------------------------------------------------------------

    /**
     * Two of the three introspection queries name the table inline, because an
     * identifier cannot be parameter-bound. The name is therefore checked.
     *
     * @param string $table The rejected table name.
     * @return void
     */
    #[Test]
    #[DataProvider('unsafeTableNames')]
    public function aTableNameThatCannotBeSafelyInterpolatedIsRejected(string $table): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid table name');

        ModelGenerator::fromTable($table, 'default');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeTableNames(): array
    {
        return [
            'backtick escape' => ['user` UNION SELECT 1,2 -- '],
            'quote' => ["user'"],
            'semicolon' => ['user; DROP TABLE user'],
            'space' => ['my table'],
            'hyphen' => ['user-table'],
            'empty' => [''],
            'backslash' => ['user\\table'],
        ];
    }

    /**
     * @param string $table The accepted table name.
     * @return void
     */
    #[Test]
    #[DataProvider('safeTableNames')]
    public function anOrdinaryTableNameIsAccepted(string $table): void
    {
        $generator = ModelGenerator::fromTable($table, 'default');

        $this->assertInstanceOf(ModelGenerator::class, $generator);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function safeTableNames(): array
    {
        return [
            'plain' => ['user'],
            'snake case' => ['order_item'],
            'digits' => ['user2'],
            'leading underscore' => ['_temp'],
            'leading digit' => ['2fa_token'],
        ];
    }

    // ------------------------------------------------------------------
    // CLASS NAMES PHP WILL NOT ACCEPT
    // ------------------------------------------------------------------

    /**
     * MySQL allows a table name starting with a digit; PHP does not allow a
     * class name starting with one. That produced a file whose class line was
     * a parse error, written without complaint.
     */
    #[Test]
    public function aTableNameStartingWithADigitIsRefusedRatherThanWrittenOut(): void
    {
        $generator = $this->generator('2fa_token', [$this->intColumn('id', true)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2faToken');

        $generator->preview();
    }

    /**
     * @param string $table A table whose derived class name is a reserved word.
     * @return void
     */
    #[Test]
    #[DataProvider('reservedClassNameTables')]
    public function aTableNamedAfterAReservedWordIsRefused(string $table): void
    {
        $generator = $this->generator($table, [$this->intColumn('id', true)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved word');

        $generator->preview();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reservedClassNameTables(): array
    {
        return [
            'class' => ['class'],
            'list' => ['list'],
            'match' => ['match'],
            'enum' => ['enum'],
            'string' => ['string'],
            'object' => ['object'],
        ];
    }

    /**
     * The refusal names the table and points at the way out.
     */
    #[Test]
    public function theRefusalSaysWhichTableAndWhatToDo(): void
    {
        $generator = $this->generator('2fa_token', [$this->intColumn('id', true)]);

        $this->expectExceptionMessage("Table '2fa_token'");

        $generator->preview();
    }

    /**
     * An explicit class name is the documented escape, so it has to work.
     */
    #[Test]
    public function anExplicitClassNameRescuesAnUnusableTableName(): void
    {
        $code = $this->generator('2fa_token', [$this->intColumn('id', true)])
            ->className('TwoFactorToken')
            ->preview();

        $this->assertStringContainsString('class TwoFactorToken extends Model', $code);
        $this->assertStringContainsString("protected string \$table = '2fa_token';", $code);
        $this->assertCodeParses($code);
    }

    /**
     * A custom name is not a way to smuggle in something PHP rejects.
     */
    #[Test]
    public function anUnusableCustomClassNameIsRefusedToo(): void
    {
        $generator = $this->generator('user', [$this->intColumn('id', true)])->className('9Lives');

        $this->expectException(RuntimeException::class);

        $generator->preview();
    }

    // ------------------------------------------------------------------
    // RELATION METHOD NAMES
    // ------------------------------------------------------------------

    /**
     * A "save_id" column produced save(): Relation on a Model subclass, whose
     * signature cannot match Model::save(bool): bool. The file parses and then
     * dies the moment it is loaded, so a lint check would not have caught it.
     */
    #[Test]
    public function aRelationIsNotGeneratedWhenItWouldOverrideAModelMethod(): void
    {
        $code = $this->generator('widget', [
            $this->intColumn('id', true),
            $this->intColumn('save_id'),
            $this->intColumn('user_id'),
        ])->preview();

        $this->assertStringNotContainsString('public function save(', $code);

        // The unaffected relation is still generated.
        $this->assertStringContainsString('public function user(): Relation', $code);
        $this->assertCodeParses($code);
    }

    /**
     * @param string $column A column whose relation name is taken by Model.
     * @return void
     */
    #[Test]
    #[DataProvider('collidingColumns')]
    public function noRelationTakesTheNameOfAnExistingModelMethod(string $column): void
    {
        $relation = substr($column, 0, -3);

        $code = $this->generator('widget', [
            $this->intColumn('id', true),
            $this->intColumn($column),
        ])->preview();

        $this->assertStringNotContainsString("public function $relation(", $code);
        $this->assertCodeParses($code);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function collidingColumns(): array
    {
        return [
            'save' => ['save_id'],
            'delete' => ['delete_id'],
            'fill' => ['fill_id'],
            'find' => ['find_id'],
        ];
    }

    /**
     * "class_id" produced Class::class — a reserved word used as a class
     * reference, which is a parse error rather than a load-time failure.
     */
    #[Test]
    public function aRelationToAReservedWordClassIsNotGenerated(): void
    {
        $code = $this->generator('widget', [
            $this->intColumn('id', true),
            $this->intColumn('class_id'),
            $this->intColumn('user_id'),
        ])->preview();

        $this->assertStringNotContainsString('Class::class', $code);
        $this->assertStringContainsString('User::class', $code);
        $this->assertCodeParses($code);
    }

    /**
     * Two columns can resolve to one relation name; the second would be a
     * "Cannot redeclare" fatal.
     */
    #[Test]
    public function twoColumnsResolvingToOneRelationNameGenerateItOnce(): void
    {
        $code = $this->generator('widget', [
            $this->intColumn('id', true),
            $this->intColumn('user_id'),
            $this->intColumn('User_id'),
        ])->preview();

        $this->assertSame(1, substr_count($code, 'public function user('));
        $this->assertCodeParses($code);
    }

    /**
     * A column repeated by a faulty introspection query must not be written
     * twice, whatever produced the repeat.
     */
    #[Test]
    public function aRepeatedColumnDoesNotProduceTwoRelationMethods(): void
    {
        $code = $this->generator('user_profile', [
            $this->intColumn('id', true),
            $this->intColumn('user_id'),
            $this->intColumn('user_id'),
        ])->preview();

        $this->assertSame(1, substr_count($code, 'public function user(): Relation'));
        $this->assertCodeParses($code);
    }

    /**
     * The ordinary case is untouched by all of the above.
     */
    #[Test]
    public function anOrdinaryForeignKeyStillGeneratesItsRelation(): void
    {
        $code = $this->generator('post', [
            $this->intColumn('id', true),
            $this->intColumn('user_id'),
            $this->intColumn('category_id'),
        ])->preview();

        $this->assertStringContainsString('public function user(): Relation', $code);
        $this->assertStringContainsString(
            "return \$this->hasOne(User::class, ['id' => 'user_id']);",
            $code
        );
        $this->assertStringContainsString('public function category(): Relation', $code);
        $this->assertCodeParses($code);
    }

    // ------------------------------------------------------------------
    // THE NAMESPACE REACHES THE FILE UNQUOTED
    // ------------------------------------------------------------------

    /**
     * Namespaces PHP will not accept in `namespace X;`.
     *
     * @return array<string, array{string}>
     */
    public static function unusableNamespaces(): array
    {
        $bs = chr(92);

        return [
            'empty' => [''],
            'space' => ['My Models'],
            'trailing separator' => ['App' . $bs . 'Models' . $bs],
            'leading separator' => [$bs . 'App'],
            'doubled separator' => ['App' . $bs . $bs . 'Models'],
            'starts with a digit' => ['9App'],
            'brace' => ['App{'],
            'closes the statement' => ["App; echo 'X';"],
        ];
    }

    /**
     * The namespace is written straight into `namespace X;`, and nothing
     * checked it. Most bad values give a file that does not parse; one of them
     * gives a file that parses fine and runs whatever followed the semicolon,
     * on every include.
     *
     * @param string $namespace The rejected namespace.
     * @return void
     */
    #[Test]
    #[DataProvider('unusableNamespaces')]
    public function aNamespacePhpWillNotAcceptIsRejected(string $namespace): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid namespace');

        $this->generator('post', [$this->intColumn('id', true)])->namespace($namespace);
    }

    /**
     * Namespaces that are fine and must keep working.
     *
     * @return array<string, array{string}>
     */
    public static function usableNamespaces(): array
    {
        $bs = chr(92);

        return [
            'single segment' => ['App'],
            'two segments' => ['App' . $bs . 'Models'],
            'three segments' => ['Acme' . $bs . 'App' . $bs . 'Models'],
            'underscored' => ['My_App' . $bs . 'Models_2'],
        ];
    }

    /**
     * @param string $namespace The accepted namespace.
     * @return void
     */
    #[Test]
    #[DataProvider('usableNamespaces')]
    public function anOrdinaryNamespaceIsAccepted(string $namespace): void
    {
        $code = $this->generator('post', [$this->intColumn('id', true)])->namespace($namespace)->preview();

        $this->assertStringContainsString("namespace $namespace;", $code);
        $this->assertCodeParses($code);
    }

    /**
     * The refusal has to name the value, since generateAll() passes a namespace
     * the caller may have built rather than typed.
     */
    #[Test]
    public function theNamespaceRefusalSaysWhatWasWrong(): void
    {
        try {
            $this->generator('post', [$this->intColumn('id', true)])->namespace('My Models');
            $this->fail('an unusable namespace was accepted');
        } catch (AssertionFailedError $failure) {
            // fail() throws a RuntimeException subclass, so without this the
            // catch below reports the failure message as the refusal.
            throw $failure;
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('My Models', $exception->getMessage());
            $this->assertStringContainsString('App\\Models', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // THE CONNECTION NAME REACHES THE FILE INSIDE A QUOTED STRING
    // ------------------------------------------------------------------

    /**
     * The name is interpolated into `protected string $connection = '...';`, so
     * a quote in it ends the string early and the file stops parsing.
     * Connection::add() accepts any string, so this needs nothing unusual of
     * the caller.
     */
    #[Test]
    public function aConnectionNameHoldingAQuoteIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be written into a generated model');

        new TestableModelGenerator('post', [$this->intColumn('id', true)], "ev'il");
    }

    /**
     * A backslash is the other way out of a single-quoted string: a trailing
     * one escapes the closing quote.
     */
    #[Test]
    public function aConnectionNameHoldingABackslashIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be written into a generated model');

        new TestableModelGenerator('post', [$this->intColumn('id', true)], 'has' . chr(92) . 'backslash');
    }

    /**
     * A space is not a problem inside a quoted string, and refusing it would
     * break connections that already work.
     */
    #[Test]
    public function anOrdinaryConnectionNameIsAccepted(): void
    {
        $code = $this->generator('post', [$this->intColumn('id', true)], 'with space')->preview();

        $this->assertStringContainsString("protected string \$connection = 'with space';", $code);
        $this->assertCodeParses($code);
    }
}
