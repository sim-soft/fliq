<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\DB\Generator\ObserverGenerator;

/**
 * ObserverGenerator writing to disk.
 *
 * The sibling test covers preview(), which builds a string. These cover the two
 * methods that touch files — generate() and append() — and, more to the point,
 * whether what they write is code PHP will accept. A generator whose output does
 * not parse fails in the reader's project, not here, so every test that produces
 * a file lints it.
 *
 * Everything is written under a directory of this test's own, removed in
 * tearDown().
 */
class ObserverGeneratorFileTest extends TestCase
{
    /** @var string Scratch directory for generated files. */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fliq_obs_test_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    /**
     * Delete a directory and everything in it.
     *
     * @param string $path The directory.
     * @return void
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }

    /**
     * Assert that a file is code PHP can parse.
     *
     * @param string $path The file to lint.
     * @return void
     */
    private function assertParses(string $path): void
    {
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, "Generated file does not parse:\n" . implode("\n", $output));
    }

    /**
     * Assert that a string of PHP code parses.
     *
     * @param string $code The code.
     * @return void
     */
    private function assertCodeParses(string $code): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'lint_' . uniqid() . '.php';
        file_put_contents($path, $code);

        $this->assertParses($path);
    }

    /**
     * A generator writing into the scratch directory.
     *
     * @param string $model The model name.
     * @return ObserverGenerator
     */
    private function generator(string $model): ObserverGenerator
    {
        return ObserverGenerator::forModel($model)
            ->skipValidation()
            ->outputDir($this->dir);
    }

    // ------------------------------------------------------------------
    // generate()
    // ------------------------------------------------------------------

    #[Test]
    public function generateWritesAFileThatParses(): void
    {
        $path = $this->generator('Widget')->generate();

        $this->assertIsString($path);
        $this->assertFileExists($path);
        $this->assertParses($path);
    }

    #[Test]
    public function generateReturnsFalseRatherThanOverwriting(): void
    {
        $path = $this->generator('Widget')->generate();
        $this->assertIsString($path);

        file_put_contents($path, '<?php /* hand-written */');

        $this->assertFalse($this->generator('Widget')->generate());
        $this->assertSame('<?php /* hand-written */', file_get_contents($path));
    }

    #[Test]
    public function forceOverwritesAnExistingFile(): void
    {
        $path = $this->generator('Widget')->generate();
        $this->assertIsString($path);
        file_put_contents($path, '<?php /* hand-written */');

        $this->assertSame($path, $this->generator('Widget')->force()->generate());
        $this->assertStringContainsString('class WidgetObserver', (string)file_get_contents($path));
    }

    #[Test]
    public function generateCreatesAMissingOutputDirectory(): void
    {
        $nested = $this->dir . '/app/Observers';
        $this->assertDirectoryDoesNotExist($nested);

        $path = ObserverGenerator::forModel('Deep')
            ->skipValidation()->outputDir($nested)->generate();

        $this->assertIsString($path);
        $this->assertDirectoryExists($nested);
        $this->assertParses($path);
    }

    // ------------------------------------------------------------------
    // The cancel contract
    // ------------------------------------------------------------------

    /**
     * A before event must be able to do what its docblock says.
     *
     * The stubs were typed void while the docblock told the reader to return
     * false to cancel. A void method that returns a value is a fatal error, so
     * following the instruction printed in the generated file broke the file.
     */
    #[Test]
    public function aBeforeEventStubCanReturnFalseToCancel(): void
    {
        $code = $this->generator('Cancel')->preview();

        $this->assertStringContainsString('public function creating(Cancel $cancel): ?bool', $code);
        $this->assertStringContainsString('public function updating(Cancel $cancel): ?bool', $code);
        $this->assertStringContainsString('public function saving(Cancel $cancel): ?bool', $code);
        $this->assertStringContainsString('public function deleting(Cancel $cancel): ?bool', $code);

        // The instruction the docblock gives, carried out.
        $this->assertCodeParses(str_replace(
            "    public function deleting(Cancel \$cancel): ?bool\n    {\n        //\n\n        return null;\n    }",
            "    public function deleting(Cancel \$cancel): ?bool\n    {\n        return false;\n    }",
            $code
        ));
    }

    #[Test]
    public function anAfterEventStubStaysVoid(): void
    {
        $code = $this->generator('Cancel')->preview();

        $this->assertStringContainsString('public function created(Cancel $cancel): void', $code);
        $this->assertStringContainsString('public function updated(Cancel $cancel): void', $code);
        $this->assertStringContainsString('public function saved(Cancel $cancel): void', $code);
        $this->assertStringContainsString('public function deleted(Cancel $cancel): void', $code);
    }

    /**
     * A ?bool method that falls off its end raises a TypeError, so the stub
     * cannot be left empty the way a void one can.
     */
    #[Test]
    public function anUntouchedBeforeStubReturnsNullExplicitly(): void
    {
        $code = $this->generator('Cancel')->preview();

        $this->assertMatchesRegularExpression(
            '/public function creating\(Cancel \$cancel\): \?bool\s*\{\s*\/\/\s*return null;\s*\}/',
            $code
        );
    }

    #[Test]
    public function theCancelDocblockDescribesTheNullableReturn(): void
    {
        $code = $this->generator('Cancel')->preview();

        $this->assertStringContainsString(
            '@return bool|null Return false to cancel the operation; null to continue.',
            $code
        );
        $this->assertStringNotContainsString('@return void Return false', $code);
    }

    // ------------------------------------------------------------------
    // Event names
    // ------------------------------------------------------------------

    #[Test]
    public function anEventNameThatIsNotAMethodNameIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Event name 'save-point' is not a valid PHP method name");

        $this->generator('Bad')->events(['creating', 'save-point'])->preview();
    }

    #[Test]
    public function anEventNameStartingWithADigitIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->generator('Bad')->events(['9lives'])->preview();
    }

    #[Test]
    public function generateRefusesAnIllegalNameBeforeWritingAnything(): void
    {
        try {
            $this->generator('Bad')->events(['save-point'])->generate();
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'BadObserver.php');
        }
    }

    /**
     * The CLI builds its list with explode(','), which leaves the space after
     * each comma on the name — and " created" is not a method name.
     */
    #[Test]
    public function aCommaSeparatedListIsTrimmed(): void
    {
        $code = $this->generator('Trim')
            ->events(explode(',', 'creating, created, saved'))
            ->preview();

        $this->assertStringContainsString('public function created(Trim $trim): void', $code);
        $this->assertStringContainsString('public function saved(Trim $trim): void', $code);
        $this->assertCodeParses($code);
    }

    #[Test]
    public function emptyEntriesInTheListAreDropped(): void
    {
        $code = $this->generator('Trim')->events(['saved', '', '  '])->preview();

        $this->assertSame(1, substr_count($code, 'public function '));
        $this->assertCodeParses($code);
    }

    #[Test]
    public function anUnderscoredCustomEventIsAccepted(): void
    {
        $code = $this->generator('Custom')->events(['my_event'])->preview();

        $this->assertStringContainsString('public function my_event(Custom $custom): void', $code);
        $this->assertCodeParses($code);
    }

    // ------------------------------------------------------------------
    // append()
    // ------------------------------------------------------------------

    /**
     * Write an observer file with the given body.
     *
     * @param string $model The model name.
     * @param string $content The file content.
     * @return string The path written.
     */
    private function writeObserver(string $model, string $content): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $model . 'Observer.php';
        file_put_contents($path, $content);

        return $path;
    }

    #[Test]
    public function appendAddsOnlyTheMissingEvents(): void
    {
        $path = $this->writeObserver('Partial', <<<'PHP'
<?php

namespace App\Observers;

use App\Models\Partial;

class PartialObserver
{
    public function creating(Partial $partial): ?bool
    {
        return null;
    }
}

PHP);

        $result = $this->generator('Partial')->append();

        $this->assertIsArray($result);
        $this->assertSame(['creating'], $result['existing']);
        $this->assertNotContains('creating', $result['added']);
        $this->assertContains('saved', $result['added']);
        $this->assertParses($path);
        $this->assertSame(1, substr_count((string)file_get_contents($path), 'function creating'));
    }

    #[Test]
    public function appendReturnsFalseWhenThereIsNoFile(): void
    {
        $this->assertFalse($this->generator('Ghost')->append());
    }

    #[Test]
    public function appendAddsNothingWhenEveryEventIsPresent(): void
    {
        $path = (string)$this->generator('Full')->generate();
        $before = (string)file_get_contents($path);

        $result = $this->generator('Full')->append();

        $this->assertIsArray($result);
        $this->assertSame([], $result['added']);
        $this->assertCount(8, $result['existing']);
        $this->assertSame($before, file_get_contents($path));
    }

    /**
     * A custom event was looked for in a fixed list of the eight model events,
     * never found, and so appended again on every run — the second of which
     * wrote a duplicate method and left a file PHP refuses to parse.
     */
    #[Test]
    public function appendingACustomEventTwiceDoesNotDuplicateIt(): void
    {
        $path = $this->writeObserver('Repeat', "<?php\n\nnamespace App\\Observers;\n\nclass RepeatObserver\n{\n}\n");

        $first = $this->generator('Repeat')->events(['restoring'])->append();
        $second = $this->generator('Repeat')->events(['restoring'])->append();

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame(['restoring'], $first['added']);
        $this->assertSame([], $second['added']);
        $this->assertSame(1, substr_count((string)file_get_contents($path), 'function restoring'));
        $this->assertParses($path);
    }

    #[Test]
    public function appendRefusesAFileWithNoClassBody(): void
    {
        $path = $this->writeObserver('Nobrace', "<?php\n\nnamespace App\\Observers;\n");
        $before = (string)file_get_contents($path);

        try {
            $this->generator('Nobrace')->events(['saved'])->append();
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no class body', $exception->getMessage());
        }

        $this->assertSame($before, file_get_contents($path));
    }

    #[Test]
    public function appendRefusesAnIllegalEventName(): void
    {
        $this->writeObserver('Bad', "<?php\n\nclass BadObserver\n{\n}\n");

        $this->expectException(RuntimeException::class);

        $this->generator('Bad')->events(['save-point'])->append();
    }

    // ------------------------------------------------------------------
    // append() and the model import
    // ------------------------------------------------------------------

    /**
     * The appended methods hint the model by its short name. Without an import
     * that resolves to the observer's own namespace — a class that does not
     * exist — and the file still parses, so nothing complains until a real
     * model is handed to a method and PHP raises a TypeError.
     */
    #[Test]
    public function appendAddsTheModelImportWhenItIsMissing(): void
    {
        $path = $this->writeObserver('Noimport', "<?php\n\nnamespace App\\Observers;\n\nclass NoimportObserver\n{\n}\n");

        $this->generator('Noimport')->events(['saved'])->append();

        $this->assertStringContainsString('use App\Models\Noimport;', (string)file_get_contents($path));
        $this->assertParses($path);
    }

    #[Test]
    public function appendPlacesTheImportAfterExistingImports(): void
    {
        $path = $this->writeObserver('Extra', <<<'PHP'
<?php

namespace App\Observers;

use DateTime;
use RuntimeException;

class ExtraObserver
{
}

PHP);

        $this->generator('Extra')->events(['saved'])->append();
        $content = (string)file_get_contents($path);

        $this->assertStringContainsString('use App\Models\Extra;', $content);
        $this->assertGreaterThan(strpos($content, 'use RuntimeException;'), strpos($content, 'use App\Models\Extra;'));
        $this->assertLessThan(strpos($content, 'class ExtraObserver'), strpos($content, 'use App\Models\Extra;'));
        $this->assertParses($path);
    }

    /**
     * Importing a second class under a name already in use is a fatal error, so
     * a file that resolves the short name for itself is left alone.
     */
    #[Test]
    public function appendDoesNotAddASecondImportOfTheSameShortName(): void
    {
        $path = $this->writeObserver('Other', <<<'PHP'
<?php

namespace App\Observers;

use Domain\Entities\Other;

class OtherObserver
{
}

PHP);

        $this->generator('Other')->events(['saved'])->append();
        $content = (string)file_get_contents($path);

        $this->assertSame(1, substr_count($content, 'Other;'));
        $this->assertStringNotContainsString('use App\Models\Other;', $content);
        $this->assertParses($path);
    }

    #[Test]
    public function appendRespectsAnAliasBoundToTheModelName(): void
    {
        $path = $this->writeObserver('Aliased', <<<'PHP'
<?php

namespace App\Observers;

use Something\Else1 as Aliased;

class AliasedObserver
{
}

PHP);

        $this->generator('Aliased')->events(['saved'])->append();
        $content = (string)file_get_contents($path);

        $this->assertStringNotContainsString('use App\Models\Aliased;', $content);
        $this->assertParses($path);
    }

    /**
     * An import merely ending in the model's name binds a different short name,
     * so it must not be mistaken for one that already covers the hint.
     */
    #[Test]
    public function anImportEndingInTheModelNameIsNotMistakenForIt(): void
    {
        $path = $this->writeObserver('Item', <<<'PHP'
<?php

namespace App\Observers;

use App\Models\UpperItem;

class ItemObserver
{
}

PHP);

        $this->generator('Item')->events(['saved'])->append();
        $content = (string)file_get_contents($path);

        $this->assertStringContainsString('use App\Models\Item;', $content);
        $this->assertParses($path);
    }

    #[Test]
    public function appendAddsTheImportToAFileWithNoNamespace(): void
    {
        $path = $this->writeObserver('Bare', "<?php\n\nclass BareObserver\n{\n}\n");

        $this->generator('Bare')->events(['saved'])->append();

        $this->assertStringContainsString('use App\Models\Bare;', (string)file_get_contents($path));
        $this->assertParses($path);
    }

    // ------------------------------------------------------------------
    // validateModelExists()
    // ------------------------------------------------------------------

    #[Test]
    public function generateNamesTheMissingModelAndHowToMakeIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Model class 'App\\Models\\NoSuchModel' not found");

        ObserverGenerator::forModel('NoSuchModel')->outputDir($this->dir)->generate();
    }

    #[Test]
    public function appendValidatesTheModelToo(): void
    {
        $this->writeObserver('NoSuchModel', "<?php\n\nclass NoSuchModelObserver\n{\n}\n");

        $this->expectException(RuntimeException::class);

        ObserverGenerator::forModel('NoSuchModel')->outputDir($this->dir)->append();
    }

    #[Test]
    public function aLoadedClassSatisfiesValidation(): void
    {
        $path = ObserverGenerator::forModel('ObserverGenerator')
            ->modelNamespace('Simsoft\\DB\\Generator')
            ->outputDir($this->dir)
            ->generate();

        $this->assertIsString($path);
        $this->assertParses($path);
    }
}
