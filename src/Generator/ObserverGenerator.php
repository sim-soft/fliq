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
     * @param array<int, string> $events The event names.
     * @return static
     */
    public function events(array $events): static
    {
        $this->events = $events;
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

        $existingEvents = $this->detectExistingEvents($content);
        $missingEvents = array_diff($this->events, $existingEvents);

        if (empty($missingEvents)) {
            return ['file' => $filePath, 'added' => [], 'existing' => $existingEvents];
        }

        $methodsCode = $this->buildMethodStubs($missingEvents);
        $updatedContent = $this->insertBeforeClosingBrace($content, $methodsCode);

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
     * @param string $content The file content.
     * @return array<int, string> Event names that are already defined.
     */
    private function detectExistingEvents(string $content): array
    {
        $allEvents = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted'];
        $existing = [];

        foreach ($allEvents as $event) {
            if (preg_match('/public\s+function\s+' . $event . '\s*\(/', $content)) {
                $existing[] = $event;
            }
        }

        return $existing;
    }

    /**
     * Build method stub code for the given events.
     *
     * @param array<int, string> $events The event names to generate stubs for.
     * @return string The method code block.
     */
    private function buildMethodStubs(array $events): string
    {
        $paramName = lcfirst($this->modelName);
        $lines = [];

        foreach ($events as $event) {
            $description = $this->eventDescription($event);
            $returnDoc = $this->isBeforeEvent($event)
                ? "     * @return void Return false to cancel the operation."
                : "     * @return void";

            $lines[] = '';
            $lines[] = '    /**';
            $lines[] = "     * Handle the $description event.";
            $lines[] = '     *';
            $lines[] = "     * @param $this->modelName \$$paramName The model instance.";
            $lines[] = $returnDoc;
            $lines[] = '     */';
            $lines[] = "    public function $event($this->modelName \$$paramName): void";
            $lines[] = '    {';
            $lines[] = '        //';
            $lines[] = '    }';
        }

        return implode("\n", $lines);
    }

    /**
     * Insert code before the last closing brace of the class.
     *
     * @param string $content The original file content.
     * @param string $code The code to insert.
     * @return string The updated file content.
     */
    private function insertBeforeClosingBrace(string $content, string $code): string
    {
        $lastBrace = strrpos($content, '}');
        if ($lastBrace === false) {
            return $content . $code . "\n}\n";
        }

        return substr($content, 0, $lastBrace) . $code . "\n}\n";
    }

    /**
     * Build the full PHP class code.
     *
     * @param string $className The observer class name.
     * @return string
     */
    private function buildClassCode(string $className): string
    {
        $modelFqcn = $this->modelNamespace . '\\' . $this->modelName;
        $paramName = lcfirst($this->modelName);

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

            $description = $this->eventDescription($event);
            $returnType = 'void';
            $returnDoc = $this->isBeforeEvent($event)
                ? "     * @return void Return false to cancel the operation."
                : "     * @return void";

            $lines[] = '    /**';
            $lines[] = "     * Handle the $description event.";
            $lines[] = '     *';
            $lines[] = "     * @param $this->modelName \$$paramName The model instance.";
            $lines[] = $returnDoc;
            $lines[] = '     */';
            $lines[] = "    public function $event($this->modelName \$$paramName): $returnType";
            $lines[] = '    {';
            $lines[] = '        //';
            $lines[] = '    }';
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
