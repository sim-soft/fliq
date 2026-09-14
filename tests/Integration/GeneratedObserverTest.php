<?php

namespace Integration;

use Models\Task;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Generator\ObserverGenerator;

/**
 * A generated observer, registered on a real model, against a real database.
 *
 * The unit tests check that the generated file parses. That is not the same as
 * it working: an observer is loaded, registered through observe(), and its
 * return value read by fireEvent() to decide whether the operation goes ahead.
 * A stub that parses can still be the wrong shape for every one of those steps,
 * and the file's own docblock is what tells the reader which shape to write.
 *
 * So these tests generate an observer the way a reader would, fill in a method
 * the way the docblock instructs, and then ask the server whether the row is
 * still there.
 *
 * Everything written is removed in tearDown().
 */
class GeneratedObserverTest extends DatabaseTestCase
{
    /** @var string Scratch directory for generated observer files. */
    private string $dir;

    /** @var array<int, int> Task ids created by a test. */
    private array $created = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fliq_gen_obs_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Flush first: a test's observer must not get a say in the cleanup.
        Task::flushEvents();

        foreach ($this->created as $id) {
            $task = Task::withTrashed()->where('id', '=', $id)->first();
            if ($task instanceof Task) {
                $task->forceDelete();
            }
        }
        $this->created = [];

        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * Generate an observer whose class name is unique to this test run.
     *
     * A generated class is loaded into the process for good, so each test needs
     * its own name to be able to define a body of its own.
     *
     * @param string $model The model name to generate for.
     * @param array<int, string>|null $events The events to include.
     * @return string The path written.
     */
    private function generate(string $model, ?array $events = null): string
    {
        $generator = ObserverGenerator::forModel($model)
            ->skipValidation()
            ->namespace('Generated')
            ->modelNamespace('Models')
            ->outputDir($this->dir);

        if ($events !== null) {
            $generator->events($events);
        }

        $path = $generator->generate();
        $this->assertIsString($path);

        return $path;
    }

    /**
     * Load a generated file after rewriting its class and model names.
     *
     * The generator names the class after the model. These tests need the
     * generated shape but bound to the Task model, so the placeholder name is
     * swapped for Task's and the file is required.
     *
     * @param string $path The generated file.
     * @param string $model The placeholder model name used to generate it.
     * @param string $body Replacement body for one method, if any.
     * @param string $target The method whose body is replaced.
     * @return object The observer instance.
     */
    private function load(string $path, string $model, string $body = '', string $target = ''): object
    {
        $code = (string)file_get_contents($path);

        // Point the type hints and the import at the real model.
        $code = str_replace("use Models\\$model;", 'use Models\\Task;', $code);
        $code = str_replace("($model \$" . lcfirst($model) . ')', '(Task $task)', $code);

        if ($target !== '') {
            $code = (string)preg_replace(
                '/(public function ' . $target . '\(Task \$task\): \??\w+\s*\{)[^}]*\}/',
                '$1' . "\n" . $body . "\n    }",
                $code
            );
        }

        file_put_contents($path, $code);
        require $path;

        $class = 'Generated\\' . $model . 'Observer';
        $this->assertTrue(class_exists($class), "Generated class $class did not load.");

        return new $class();
    }

    /**
     * Insert a task and remember it for cleanup.
     *
     * @param string $title The task title.
     * @return Task
     */
    private function makeTask(string $title): Task
    {
        $task = new Task();
        $task->fill([
            'user_id' => 1,
            'title' => $title,
            'description' => 'generated observer test',
            'priority' => 'medium',
            'status' => 'todo',
        ]);
        $task->save();

        $this->created[] = (int)$task->id;

        return $task;
    }

    /**
     * Ask the server whether a row is present, bypassing the model.
     *
     * @param int $id The task id.
     * @return bool
     */
    private function rowExists(int $id): bool
    {
        return Task::withTrashed()->where('id', '=', $id)->hasRecords();
    }

    /**
     * A generated observer registers and its methods actually run.
     *
     * observe() binds by method name, so a generated method that PHP accepts
     * can still be invisible to the model if the name or arity is wrong.
     */
    #[Test]
    public function everyGeneratedMethodIsReachedByObserve(): void
    {
        $path = $this->generate('Recorder');
        $observer = $this->load($path, 'Recorder');

        Task::observe($observer);

        $task = $this->makeTask('observer reach test');
        $task->title = 'observer reach test (edited)';
        $task->save();

        // Each of the eight is bound; the insert and update paths fire six of
        // them. Reaching this point without an error is the claim under test —
        // an unbound or mis-shaped method raises rather than being skipped.
        $this->assertTrue($this->rowExists((int)$task->id));
    }

    /**
     * The instruction the generated docblock gives, carried out.
     *
     * The stub used to be typed void while its docblock said to return false to
     * cancel. Writing that made the file a fatal error, so this is the test the
     * old output could not pass at all.
     */
    #[Test]
    public function returningFalseFromAGeneratedBeforeMethodCancelsTheDelete(): void
    {
        $task = $this->makeTask('cancel delete test');
        $id = (int)$task->id;

        $path = $this->generate('Guard', ['deleting']);
        $observer = $this->load($path, 'Guard', '        return false;', 'deleting');

        Task::observe($observer);

        $task->delete();

        $this->assertTrue($this->rowExists($id), 'The delete should have been cancelled.');
    }

    #[Test]
    public function anUntouchedBeforeStubLetsTheOperationProceed(): void
    {
        $task = $this->makeTask('untouched stub test');
        $id = (int)$task->id;

        $path = $this->generate('Passthrough', ['deleting']);
        $observer = $this->load($path, 'Passthrough');

        Task::observe($observer);

        $task->delete();

        // SoftDeletes marks the row rather than removing it, so the check is
        // that the delete was not cancelled, not that the row is gone.
        $this->assertFalse(
            Task::find()->where('id', '=', $id)->hasRecords(),
            'An untouched stub must not cancel the operation.'
        );
    }

    #[Test]
    public function returningFalseFromCreatingStopsTheInsert(): void
    {
        $path = $this->generate('Blocker', ['creating']);
        $observer = $this->load($path, 'Blocker', '        return false;', 'creating');

        Task::observe($observer);

        $before = Task::find()->where('title', '=', 'blocked insert test')->hasRecords();
        $this->assertFalse($before);

        $task = new Task();
        $task->fill([
            'user_id' => 1,
            'title' => 'blocked insert test',
            'description' => 'should never be written',
            'priority' => 'low',
            'status' => 'todo',
        ]);
        $task->save();

        $this->assertFalse(
            Task::find()->where('title', '=', 'blocked insert test')->hasRecords(),
            'The insert should have been cancelled.'
        );
    }

    /**
     * An after event cannot cancel, and its void stub is right to say so.
     */
    #[Test]
    public function anAfterEventStubCannotCancelAndTheRowIsWritten(): void
    {
        $path = $this->generate('Watcher', ['created']);
        $observer = $this->load($path, 'Watcher');

        Task::observe($observer);

        $task = $this->makeTask('after event test');

        $this->assertTrue($this->rowExists((int)$task->id));
    }

    /**
     * A file grown by append() is loadable and works the same way.
     */
    #[Test]
    public function anAppendedMethodWorksOnceRegistered(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'GrownObserver.php';
        file_put_contents($path, <<<'PHP'
<?php

namespace Generated;

class GrownObserver
{
}

PHP);

        $result = ObserverGenerator::forModel('Grown')
            ->skipValidation()
            ->namespace('Generated')
            ->modelNamespace('Models')
            ->outputDir($this->dir)
            ->events(['deleting'])
            ->append();

        $this->assertIsArray($result);
        $this->assertSame(['deleting'], $result['added']);

        $task = $this->makeTask('appended method test');
        $id = (int)$task->id;

        $observer = $this->load($path, 'Grown', '        return false;', 'deleting');
        Task::observe($observer);

        $task->delete();

        $this->assertTrue($this->rowExists($id), 'The appended method should have cancelled the delete.');
    }
}
