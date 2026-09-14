<?php

namespace Simsoft\DB\Generator;

use RuntimeException;

/**
 * Observer file generator.
 *
 * Generates an Observer class with event method stubs for a given Model.
 */
class ObserverGenerator
{
    /** @var string The model class name (short, e.g. 'User') */
    private string $modelName;

    /** @var string The namespace for the generated observer */
    private string $namespace = 'App\\Observers';

    /** @var string The model namespace for the use import */
    private string $modelNamespace = 'App\\Models';

    /** @var string The output directory path */
    private string $outputDir = 'app/Observers';

    /** @var bool Whether to overwrite existing files */
    private bool $force = false;

    /** @var bool Whether to skip model existence validation */
    private bool $skipValidation = false;

    /** @var array<int, string> Events to generate methods for */
    private array $events = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted'];

    /**
     * Constructor.
     *
     * @param string $modelName The model class name (PascalCase, e.g. 'User').
     */
    public function __construct(string $modelName)
    {
        $this->modelName = $modelName;
    }

    /**
     * Create a new generator instance (fluent factory).
     *
     * @param string $modelName The model class name.
     * @return self
     */
    public static function forModel(string $modelName): self
    {
        return new self($modelName);
    }

    /**
     * Set the namespace for the generated observer class.
     *
     * @param string $namespace The PHP namespace.
     * @return static
     */
    public function namespace(string $namespace): static
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Set the model namespace for the use import.
     *
     * @param string $namespace The model's PHP namespace.
     * @return static
     */
    public function modelNamespace(string $namespace): static
    {
        $this->modelNamespace = $namespace;
        return $this;
    }

    /**
     * Set the output directory for the generated file.
     *
     * @param string $directory The output directory path.
     * @return static
     */
    public function outputDir(string $directory): static
    {
        $this->outputDir = rtrim($directory, '/\\');
        return $this;
    }

    /**
     * Enable overwriting existing files.
     *
     * @param bool $force Whether to force overwriting.
     * @return static
     */
    public function force(bool $force = true): static
    {
        $this->force = $force;
        return $this;
    }

    /**
     * Skip model existence validation.
     *
     * Useful when generating observers in a batch from known model files.
     *
     * @return static
     */
    public function skipValidation(): static
    {
        $this->skipValidation = true;
        return $this;
    }

    /**
     * Specify which events to include in the observer.
     *
     * Names arrive from a --events list as often as from code, so they are
     * trimmed: "creating, created" splits on the comma into a second name
     * carrying a leading space, which is not a legal method name.
     *
     * @param array<int, string> $events The event names.
     * @return static
     */
    public function events(array $events): static
    {
        $this->events = array_values(array_filter(
            array_map(trim(...), $events),
            static fn(string $event): bool => $event !== ''
        ));
        return $this;
    }

    /**
     * Generate the observer file.
     *
     * @return string|false The file path written, or false if skipped.
     * @throws RuntimeException If the model class file cannot be found.
     */
    public function generate(): string|false
    {
        if (!$this->skipValidation) {
            $this->validateModelExists();
        }

        $className = $this->modelName . 'Observer';
        $filePath = $this->outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        if (!$this->force && file_exists($filePath)) {
            return false;
        }

        $code = $this->buildClassCode($className);

        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filePath, $code);

        return $filePath;
    }

    /**
     * Append missing event methods to an existing observer file.
     *
     * Reads the file, detects which event methods already exist,
     * and inserts only the missing ones before the closing brace.
     *
     * @return array{file: string, added: array<int, string>, existing: array<int, string>}|false
     *     Returns result info, or false if the file doesn't exist.
     * @throws RuntimeException If the model class file cannot be found.
     */
    public function append(): array|false
    {
        if (!$this->skipValidation) {
            $this->validateModelExists();
        }

        $className = $this->modelName . 'Observer';
        $filePath = $this->outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        if (!file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }

        $this->assertEventNames($this->events);

        $existingEvents = $this->detectExistingEvents($content);
        $missingEvents = array_diff($this->events, $existingEvents);

        if (empty($missingEvents)) {
            return ['file' => $filePath, 'added' => [], 'existing' => $existingEvents];
        }

        $methodsCode = $this->buildMethodStubs($missingEvents);
        $updatedContent = $this->insertBeforeClosingBrace($content, $methodsCode);
        $updatedContent = $this->ensureModelImport($updatedContent);

        file_put_contents($filePath, $updatedContent);

        return ['file' => $filePath, 'added' => array_values($missingEvents), 'existing' => $existingEvents];
    }

    /**
     * Generate the observer class code as a string (without writing to the file).
     *
     * @return string The generated PHP code.
     */
    public function preview(): string
    {
        $className = $this->modelName . 'Observer';
        return $this->buildClassCode($className);
    }

    /**
     * Detect which event methods already exist in the file content.
     *
     * The events asked for are searched, not a fixed list of the eight model
     * events: a custom event was never found in the file, so append() offered
     * it again on every run and the second run wrote a duplicate method the
     * parser rejects with "Cannot redeclare".
     *
     * @param string $content The file content.
     * @return array<int, string> Event names that are already defined.
     */
    private function detectExistingEvents(string $content): array
    {
        $known = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted'];
        $existing = [];

        foreach (array_unique(array_merge($known, $this->events)) as $event) {
            if (preg_match('/public\s+function\s+' . preg_quote($event, '/') . '\s*\(/', $content)) {
                $existing[] = $event;
            }
        }

        return $existing;
    }

    /**
     * Reject event names that cannot be PHP method names.
     *
     * An event reaches this class straight from a --events list, and a name
     * like "save-point" — or one left with the space after a comma — was
     * written into the file as a method name and only failed when the parser
     * reached it, with nothing pointing back at the command that made it.
     *
     * @param array<int, string> $events The event names.
     * @return void
     * @throws RuntimeException If a name cannot be a method name.
     */
    private function assertEventNames(array $events): void
    {
        foreach ($events as $event) {
            if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $event) === 1) {
                continue;
            }

            throw new RuntimeException(
                "Event name '$event' is not a valid PHP method name. "
                . 'Use letters, digits and underscores, starting with a letter or underscore.'
            );
        }
    }

    /**
     * Build method stub code for the given events.
     *
     * @param array<int, string> $events The event names to generate stubs for.
     * @return string The method code block.
     */
    private function buildMethodStubs(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = '';
            foreach ($this->buildMethod($event) as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build one event method, docblock included.
     *
     * A "before" event can stop the operation by returning false, so it is
     * typed ?bool rather than void: a void method that returns a value is a
     * fatal error, which is what the generated docblock used to instruct the
     * reader to write. The stub returns null explicitly, because a ?bool method
     * that falls off its end raises a TypeError, and null means "carry on".
     *
     * @param string $event The event name.
     * @return array<int, string> The lines of the method.
     */
    private function buildMethod(string $event): array
    {
        $paramName = lcfirst($this->modelName);
        $before = $this->isBeforeEvent($event);
        $returnType = $before ? '?bool' : 'void';

        $lines = [
            '    /**',
            '     * Handle the ' . $this->eventDescription($event) . ' event.',
            '     *',
            "     * @param $this->modelName \$$paramName The model instance.",
            $before
                ? '     * @return bool|null Return false to cancel the operation; null to continue.'
                : '     * @return void',
            '     */',
            "    public function $event($this->modelName \$$paramName): $returnType",
            '    {',
            '        //',
        ];

        if ($before) {
            $lines[] = '';
            $lines[] = '        return null;';
        }

        $lines[] = '    }';

        return $lines;
    }

    /**
     * Insert code before the last closing brace of the class.
     *
     * @param string $content The original file content.
     * @param string $code The code to insert.
     * @return string The updated file content.
     * @throws RuntimeException If the file has no class body to append to.
     */
    private function insertBeforeClosingBrace(string $content, string $code): string
    {
        $lastBrace = strrpos($content, '}');

        // Appending to a file with no closing brace used to paste the methods
        // onto the end and add a brace of its own, leaving them outside any
        // class — a parse error written over the reader's own file. There is
        // nothing sensible to append to, so say so and leave the file alone.
        if ($lastBrace === false) {
            throw new RuntimeException(
                'Cannot append to a file with no class body. '
                . 'Expected a class declaration ending in "}".'
            );
        }

        return substr($content, 0, $lastBrace) . $code . "\n}\n";
    }

    /**
     * Add the model's use import if the file does not already have it.
     *
     * The appended methods type-hint the model by its short name, which without
     * an import resolves to the observer's own namespace. The file still
     * parsed, so nothing complained until a real model was handed to a method
     * and PHP raised a TypeError naming a class that was never meant to exist.
     *
     * @param string $content The file content.
     * @return string The content, with the import present.
     */
    private function ensureModelImport(string $content): string
    {
        $modelFqcn = $this->modelNamespace . '\\' . $this->modelName;

        // Match on the short name the hint resolves through, not the full one.
        // A file already importing the model from a different namespace — or
        // aliasing something else to that name — is left alone: adding a second
        // import of the same short name is "Cannot use ... as ...", a fatal
        // error introduced into a file that was working before.
        $name = preg_quote($this->modelName, '/');

        // The name must be the whole last segment, not a suffix of one:
        // "use App\Models\UpperItem;" binds UpperItem, and says nothing about
        // Item.
        $pattern = '/^use\s+(?:[^;]*\\\\)?' . $name . '\s*;'
            . '|^use\s+[^;]+\s+as\s+' . $name . '\s*;/m';

        if (preg_match($pattern, $content) === 1) {
            return $content;
        }

        // Below the last existing import, or below the namespace when there are
        // none — either way ahead of the class the methods were added to.
        if (preg_match_all('/^use\s+[^;]+;/m', $content, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = $matches[0][count($matches[0]) - 1];
            $at = $last[1] + strlen($last[0]);

            return substr($content, 0, $at) . "\nuse $modelFqcn;" . substr($content, $at);
        }

        if (preg_match('/^namespace\s+[^;]+;/m', $content, $found, PREG_OFFSET_CAPTURE) === 1) {
            $at = $found[0][1] + strlen($found[0][0]);

            return substr($content, 0, $at) . "\n\nuse $modelFqcn;" . substr($content, $at);
        }

        // No namespace and no imports: the opening tag is the only anchor.
        if (preg_match('/^<\?php/m', $content, $tag, PREG_OFFSET_CAPTURE) === 1) {
            $at = $tag[0][1] + strlen($tag[0][0]);

            return substr($content, 0, $at) . "\n\nuse $modelFqcn;" . substr($content, $at);
        }

        return $content;
    }

    /**
     * Build the full PHP class code.
     *
     * @param string $className The observer class name.
     * @return string
     */
    private function buildClassCode(string $className): string
    {
        $this->assertEventNames($this->events);

        $modelFqcn = $this->modelNamespace . '\\' . $this->modelName;

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace $this->namespace;";
        $lines[] = '';
        $lines[] = "use $modelFqcn;";
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * $className Class.";
        $lines[] = ' *';
        $lines[] = " * Observes lifecycle events on the $this->modelName model.";
        $lines[] = " * Register with: $this->modelName::observe(new $className());";
        $lines[] = ' */';
        $lines[] = "class $className";
        $lines[] = '{';

        foreach ($this->events as $index => $event) {
            if ($index > 0) {
                $lines[] = '';
            }

            foreach ($this->buildMethod($event) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Get a human-readable description for an event.
     *
     * @param string $event The event name.
     * @return string
     */
    private function eventDescription(string $event): string
    {
        return match ($event) {
            'creating' => 'model is being created (before INSERT)',
            'created' => 'model was created (after INSERT)',
            'updating' => 'model is being updated (before UPDATE)',
            'updated' => 'model was updated (after UPDATE)',
            'saving' => 'model is being saved (before INSERT or UPDATE)',
            'saved' => 'model was saved (after INSERT or UPDATE)',
            'deleting' => 'model is being deleted (before DELETE)',
            'deleted' => 'model was deleted (after DELETE)',
            default => "$event event",
        };
    }

    /**
     * Determine if an event is a "before" event (can cancel the operation).
     *
     * @param string $event The event name.
     * @return bool
     */
    private function isBeforeEvent(string $event): bool
    {
        return in_array($event, ['creating', 'updating', 'saving', 'deleting'], true);
    }

    /**
     * Validate that the model class exists (either as a loaded class or a file on disk).
     *
     * @return void
     * @throws RuntimeException If the model cannot be found.
     */
    private function validateModelExists(): void
    {
        $fqcn = $this->modelNamespace . '\\' . $this->modelName;

        if (class_exists($fqcn)) {
            return;
        }

        // Check if the model file exists on disk by convention (PSR-4)
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $this->modelNamespace);
        $possiblePaths = [
            $relativePath . DIRECTORY_SEPARATOR . $this->modelName . '.php',
            'app/Models' . DIRECTORY_SEPARATOR . $this->modelName . '.php',
            'src/Models' . DIRECTORY_SEPARATOR . $this->modelName . '.php',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return;
            }
        }

        throw new RuntimeException(
            "Model class '$fqcn' not found. Generate the model first with: "
            . "vendor/bin/fliq make:model $this->modelName --config=config/db.php"
        );
    }
}
