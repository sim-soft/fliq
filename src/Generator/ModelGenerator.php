<?php

namespace Simsoft\DB\Generator;

use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;

/**
 * Model file generator.
 *
 * Introspects a database table and generates a Model PHP class file with
 * properties, casts, fillable fields, relations, and trait usage inferred from schema.
 */
class ModelGenerator
{
    /** @var string The database connection name */
    private string $connectionName;

    /** @var string The table name to introspect */
    private string $table;

    /** @var string The namespace for the generated model */
    private string $namespace = 'App\\Models';

    /** @var string The output directory path */
    private string $outputDir = 'app/Models';

    /** @var bool Whether to overwrite existing files */
    private bool $force = false;

    /** @var string|null Custom class name (overrides table-derived name) */
    private ?string $customClassName = null;

    /** @var array<int, string> Tables to exclude from generateAll */
    private static array $excludedTables = [];

    /**
     * Constructor.
     *
     * @param string $table The table name.
     * @param string|null $connectionName The connection name. Null for default.
     */
    public function __construct(string $table, ?string $connectionName = null)
    {
        $this->table = $table;
        $this->connectionName = $connectionName ?? Connection::getDefaultName();
    }

    /**
     * Create a new generator instance (fluent factory).
     *
     * @param string $table The table name.
     * @param string|null $connectionName The connection name.
     * @return self
     */
    public static function fromTable(string $table, ?string $connectionName = null): self
    {
        return new self($table, $connectionName);
    }

    /**
     * Set the namespace for the generated model class.
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
     * Set a custom class name (overrides the table-derived name).
     *
     * @param string $className The desired class name.
     * @return static
     */
    public function className(string $className): static
    {
        $this->customClassName = $className;
        return $this;
    }

    /**
     * Set tables to exclude from generateAll.
     *
     * @param array<int, string> $tables Table names to skip.
     * @return void
     */
    public static function exclude(array $tables): void
    {
        self::$excludedTables = $tables;
    }

    /**
     * Generate the model file.
     *
     * @return string|false The file path written, or false if skipped (a file exists and force=false).
     */
    public function generate(): string|false
    {
        $className = $this->customClassName ?? $this->tableToClassName($this->table);
        $filePath = $this->outputDir . DIRECTORY_SEPARATOR . $className . '.php';

        if (!$this->force && file_exists($filePath)) {
            return false;
        }

        $columns = $this->introspectColumns();
        $code = $this->buildClassCode($className, $columns);

        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filePath, $code);

        return $filePath;
    }

    /**
     * Generate model files for all tables in the connection.
     *
     * @param string|null $connectionName The connection name. Null for default.
     * @param string $namespace The PHP namespace.
     * @param string $outputDir The output directory.
     * @param bool $force Whether to overwrite existing files.
     * @return array{created: array<int, string>, skipped: array<int, string>}
     */
    public static function generateAll(
        ?string $connectionName = null,
        string  $namespace = 'App\\Models',
        string  $outputDir = 'app/Models',
        bool    $force = false
    ): array
    {
        $tables = self::listTables($connectionName);
        $created = [];
        $skipped = [];

        foreach ($tables as $table) {
            if (in_array($table, self::$excludedTables, true)) {
                continue;
            }

            $generator = self::fromTable($table, $connectionName)
                ->namespace($namespace)
                ->outputDir($outputDir)
                ->force($force);

            $result = $generator->generate();
            if ($result === false) {
                $skipped[] = $table;
                continue;
            }
            $created[] = $result;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * List all table names in the connection's database.
     *
     * @param string|null $connectionName The connection name. Null for default.
     * @return array<int, string>
     */
    public static function listTables(?string $connectionName = null): array
    {
        $connName = $connectionName ?? Connection::getDefaultName();
        $grammar = Connection::grammar($connName);
        $driverName = $grammar->getDriverName();

        $raw = match ($driverName) {
            'pgsql' => new Raw(
                "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename"
            ),
            'sqlite' => new Raw(
                "SELECT name AS tablename FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            ),
            default => new Raw("SHOW TABLES"),
        };

        $raw->withConnection($connName);
        $rows = $raw->fetchAll();

        $tables = [];
        foreach ($rows as $row) {
            $tables[] = reset($row);
        }

        return $tables;
    }

    /**
     * Generate the model class code as a string (without writing to the file).
     *
     * @return string The generated PHP code.
     */
    public function preview(): string
    {
        $className = $this->customClassName ?? $this->tableToClassName($this->table);
        $columns = $this->introspectColumns();

        return $this->buildClassCode($className, $columns);
    }

    /**
     * Introspect table columns from the database.
     *
     * @return array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}>
     */
    private function introspectColumns(): array
    {
        $grammar = Connection::grammar($this->connectionName);
        $driverName = $grammar->getDriverName();

        return match ($driverName) {
            'pgsql' => $this->introspectPostgres(),
            'sqlite' => $this->introspectSQLite(),
            default => $this->introspectMySQL(),
        };
    }

    /**
     * Introspect columns from MySQL/MariaDB.
     *
     * @return array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}>
     */
    private function introspectMySQL(): array
    {
        $raw = new Raw("SHOW COLUMNS FROM `$this->table`");
        $raw->withConnection($this->connectionName);
        $rows = $raw->fetchAll();

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['Field'],
                'type' => $this->normalizeType($row['Type']),
                'rawType' => strtolower($row['Type']),
                'nullable' => $row['Null'] === 'YES',
                'default' => $row['Default'],
                'primary' => $row['Key'] === 'PRI',
            ];
        }

        return $columns;
    }

    /**
     * Introspect columns from PostgreSQL.
     *
     * @return array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}>
     */
    private function introspectPostgres(): array
    {
        $sql = "SELECT c.column_name, c.data_type, c.udt_name, c.is_nullable, c.column_default,
                CASE WHEN tc.constraint_type = 'PRIMARY KEY' THEN true ELSE false END AS is_primary
                FROM information_schema.columns c
                LEFT JOIN information_schema.key_column_usage kcu
                    ON c.table_name = kcu.table_name AND c.column_name = kcu.column_name
                LEFT JOIN information_schema.table_constraints tc
                    ON kcu.constraint_name = tc.constraint_name AND tc.constraint_type = 'PRIMARY KEY'
                WHERE c.table_name = ?
                ORDER BY c.ordinal_position";

        $raw = new Raw($sql, [$this->table]);
        $raw->withConnection($this->connectionName);
        $rows = $raw->fetchAll();

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = [
                'name' => $row['column_name'],
                'type' => $this->normalizeType($row['data_type']),
                'rawType' => strtolower($row['udt_name'] ?? $row['data_type']),
                'nullable' => $row['is_nullable'] === 'YES',
                'default' => $row['column_default'],
                'primary' => (bool)$row['is_primary'],
            ];
        }

        return $columns;
    }

    /**
     * Introspect columns from SQLite.
     *
     * @return array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}>
     */
    private function introspectSQLite(): array
    {
        $raw = new Raw("PRAGMA table_info('$this->table')");
        $raw->withConnection($this->connectionName);
        $rows = $raw->fetchAll();

        $columns = [];
        foreach ($rows as $row) {
            $rawType = strtolower($row['type'] ?? 'text');
            $columns[] = [
                'name' => $row['name'],
                'type' => $this->normalizeType($rawType),
                'rawType' => $rawType,
                'nullable' => (int)$row['notnull'] === 0,
                'default' => $row['dflt_value'],
                'primary' => (int)$row['pk'] === 1,
            ];
        }

        return $columns;
    }

    /**
     * Build the full PHP class code.
     *
     * @param string $className The class name.
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @return string
     */
    private function buildClassCode(string $className, array $columns): string
    {
        $primaryKeys = $this->extractPrimaryKeys($columns);
        $fillable = $this->extractFillable($columns, $primaryKeys);
        $casts = $this->extractCasts($columns, $primaryKeys);
        $traits = $this->detectTraits($columns);
        $phpDocProps = $this->buildPhpDocProperties($columns);
        $enumComments = $this->extractEnumComments($columns);
        $relations = $this->detectRelations($columns, $primaryKeys);

        $lines = $this->buildFileHeader($className, $traits, $phpDocProps, $relations);
        $this->appendTraitUses($lines, $traits);
        $this->appendProperties($lines, $primaryKeys);
        $this->appendFillableAndGuarded($lines, $fillable, $primaryKeys);
        $this->appendCastsAndEnums($lines, $casts, $enumComments);
        $this->appendRelations($lines, $relations);

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Build file header (namespace, imports, PHPDoc, class declaration).
     *
     * @param string $className The class name.
     * @param array{imports: array<int, string>, uses: array<int, string>} $traits Trait data.
     * @param array<int, string> $phpDocProps PHPDoc property annotations.
     * @param array<int, array<string, string>> $relations Detected relations.
     * @return array<int, string>
     */
    private function buildFileHeader(string $className, array $traits, array $phpDocProps, array $relations): array
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace $this->namespace;";
        $lines[] = '';
        $lines[] = 'use Simsoft\DB\Model;';

        if (!empty($relations)) {
            $lines[] = 'use Simsoft\DB\Relation;';
        }

        foreach ($traits['imports'] as $import) {
            $lines[] = "use $import;";
        }

        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * $className Model Class.";
        $lines[] = ' *';
        foreach ($phpDocProps as $prop) {
            $lines[] = " * $prop";
        }
        $lines[] = ' */';
        $lines[] = "class $className extends Model";
        $lines[] = '{';

        return $lines;
    }

    /**
     * Append trait use statements to lines.
     *
     * @param array<int, string> $lines Output lines (modified by reference).
     * @param array{imports: array<int, string>, uses: array<int, string>} $traits Trait data.
     * @return void
     */
    private function appendTraitUses(array &$lines, array $traits): void
    {
        foreach ($traits['uses'] as $traitUse) {
            $lines[] = "    use $traitUse;";
        }

        if (!empty($traits['uses'])) {
            $lines[] = '';
        }
    }

    /**
     * Append connection, table, and primary key properties.
     *
     * @param array<int, string> $lines Output lines (modified by reference).
     * @param array<int, string> $primaryKeys The primary key columns.
     * @return void
     */
    private function appendProperties(array &$lines, array $primaryKeys): void
    {
        $lines[] = '    /** @var string Database connection name. */';
        $lines[] = "    protected string \$connection = '$this->connectionName';";
        $lines[] = '';
        $lines[] = '    /** @var string Database table name. */';
        $lines[] = "    protected string \$table = '$this->table';";

        if (count($primaryKeys) > 1) {
            $pkArray = "['" . implode("', '", $primaryKeys) . "']";
            $lines[] = '';
            $lines[] = '    /** @var string|array Composite primary key columns. */';
            $lines[] = "    protected string|array \$primaryKey = $pkArray;";
            return;
        }

        $pkName = $primaryKeys[0] ?? 'id';
        if ($pkName !== 'id') {
            $lines[] = '';
            $lines[] = '    /** @var string|array Primary key column name. */';
            $lines[] = "    protected string|array \$primaryKey = '$pkName';";
        }
    }

    /**
     * Append fillable and guarded properties.
     *
     * @param array<int, string> $lines Output lines (modified by reference).
     * @param array<int, string> $fillable Fillable column names.
     * @param array<int, string> $primaryKeys The primary key columns.
     * @return void
     */
    private function appendFillableAndGuarded(array &$lines, array $fillable, array $primaryKeys): void
    {
        $lines[] = '';
        $lines[] = '    /** @var array<int, string> Mass-assignable attributes. */';
        $lines[] = '    protected array $fillable = [';
        foreach ($fillable as $field) {
            $lines[] = "        '$field',";
        }
        $lines[] = '    ];';

        $lines[] = '';
        $lines[] = '    /** @var array<int, string> Attributes excluded from mass assignment. */';
        if (count($primaryKeys) > 1) {
            $lines[] = '    protected array $guarded = [';
            foreach ($primaryKeys as $pk) {
                $lines[] = "        '$pk',";
            }
            $lines[] = '    ];';
            return;
        }

        $guardedKey = $primaryKeys[0] ?? 'id';
        $lines[] = "    protected array \$guarded = ['$guardedKey'];";
    }

    /**
     * Append casts and enum comment block.
     *
     * @param array<int, string> $lines Output lines (modified by reference).
     * @param array<string, string> $casts Column casts.
     * @param array<string, string> $enumComments Enum value comments.
     * @return void
     */
    private function appendCastsAndEnums(array &$lines, array $casts, array $enumComments): void
    {
        if (!empty($casts)) {
            $lines[] = '';
            $lines[] = '    /** @var array<string, string> Attribute type casts. */';
            $lines[] = '    protected array $casts = [';
            foreach ($casts as $field => $cast) {
                $comment = $enumComments[$field] ?? '';
                $suffix = $comment !== '' ? " /* $comment */" : '';
                $lines[] = "        '$field' => '$cast',$suffix";
            }
            $lines[] = '    ];';
        }

        $nonCastEnums = array_diff_key($enumComments, $casts);
        if (!empty($nonCastEnums)) {
            $lines[] = '';
            $lines[] = '    /*';
            $lines[] = '     * Enum/check constraint values:';
            foreach ($nonCastEnums as $field => $values) {
                $lines[] = "     *   $field: $values";
            }
            $lines[] = '     */';
        }
    }

    /**
     * Append relation method stubs.
     *
     * @param array<int, string> $lines Output lines (modified by reference).
     * @param array<int, array{name: string, related: string, foreignKey: string, localKey: string}> $relations Detected relations.
     * @return void
     */
    private function appendRelations(array &$lines, array $relations): void
    {
        foreach ($relations as $relation) {
            $lines[] = '';
            $lines[] = '    /**';
            $lines[] = "     * Get related {$relation['name']}.";
            $lines[] = '     *';
            $lines[] = '     * @return Relation';
            $lines[] = '     */';
            $lines[] = "    public function {$relation['name']}(): Relation";
            $lines[] = '    {';
            $lines[] = "        return \$this->hasOne({$relation['related']}::class, ['{$relation['foreignKey']}' => '{$relation['localKey']}']);";
            $lines[] = '    }';
        }
    }

    /**
     * Extract all primary key column names (supports composite).
     *
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @return array<int, string>
     */
    private function extractPrimaryKeys(array $columns): array
    {
        $keys = [];
        foreach ($columns as $column) {
            if ($column['primary']) {
                $keys[] = $column['name'];
            }
        }

        return $keys ?: ['id'];
    }

    /**
     * Extract fillable columns (excludes PKs, timestamps, soft delete).
     *
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @param array<int, string> $primaryKeys The primary key names.
     * @return array<int, string>
     */
    private function extractFillable(array $columns, array $primaryKeys): array
    {
        $excluded = array_merge($primaryKeys, ['created_at', 'updated_at', 'deleted_at', 'created', 'updated']);
        $fillable = [];

        foreach ($columns as $column) {
            if (!in_array($column['name'], $excluded, true)) {
                $fillable[] = $column['name'];
            }
        }

        return $fillable;
    }

    /**
     * Extract type casts based on column types.
     *
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @param array<int, string> $primaryKeys The primary key names.
     * @return array<string, string>
     */
    private function extractCasts(array $columns, array $primaryKeys): array
    {
        $excluded = array_merge($primaryKeys, ['created_at', 'updated_at', 'deleted_at', 'created', 'updated']);
        $casts = [];

        foreach ($columns as $column) {
            if (in_array($column['name'], $excluded, true)) {
                continue;
            }

            $cast = $this->typeToCast($column['type']);
            if ($cast !== null) {
                $casts[$column['name']] = $cast;
            }
        }

        return $casts;
    }

    /**
     * Extract enum/check constraint value comments from raw column types.
     *
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @return array<string, string> Column name => comma-separated values.
     */
    private function extractEnumComments(array $columns): array
    {
        $enums = [];

        foreach ($columns as $column) {
            $rawType = $column['rawType'];

            // MySQL ENUM: enum('value1','value2','value3')
            if (preg_match("/^enum\((.+)\)$/", $rawType, $matches)) {
                $values = str_replace("'", '', $matches[1]);
                $enums[$column['name']] = $values;
            }
        }

        return $enums;
    }

    /**
     * Detect hasOne relations from foreign key columns (columns ending with _id).
     *
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @param array<int, string> $primaryKeys The primary key names.
     * @return array<int, array{name: string, related: string, foreignKey: string, localKey: string}>
     */
    private function detectRelations(array $columns, array $primaryKeys): array
    {
        $excluded = array_merge($primaryKeys, ['created_at', 'updated_at', 'deleted_at', 'created', 'updated']);
        $relations = [];

        foreach ($columns as $column) {
            $name = $column['name'];

            if (in_array($name, $excluded, true)) {
                continue;
            }

            // Detect *_id pattern → hasOne relation
            if (str_ends_with($name, '_id')) {
                $relatedTable = substr($name, 0, -3); // user_id → user
                $relatedClass = $this->tableToClassName($relatedTable);
                $relationName = lcfirst($relatedClass);

                $relations[] = [
                    'name' => $relationName,
                    'related' => $relatedClass,
                    'foreignKey' => 'id',
                    'localKey' => $name,
                ];
            }
        }

        return $relations;
    }

    /**
     * Detect which traits should be applied based on column presence.
     *
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @return array{imports: array<int, string>, uses: array<int, string>}
     */
    private function detectTraits(array $columns): array
    {
        $columnNames = array_column($columns, 'name');
        $imports = [];
        $uses = [];

        if (in_array('deleted_at', $columnNames, true)) {
            $imports[] = 'Simsoft\DB\Traits\SoftDeletes';
            $uses[] = 'SoftDeletes';
        }

        $hasTimestamps = (in_array('created_at', $columnNames, true) && in_array('updated_at', $columnNames, true))
            || (in_array('created', $columnNames, true) && in_array('updated', $columnNames, true));

        if ($hasTimestamps) {
            $imports[] = 'Simsoft\DB\Traits\Timestamps';
            $uses[] = 'Timestamps';
        }

        return ['imports' => $imports, 'uses' => $uses];
    }

    /**
     * Build PHPDoc property annotations for all columns.
     *
     * @param array<int, array{name: string, type: string, rawType: string, nullable: bool, default: mixed, primary: bool}> $columns
     * @return array<int, string>
     */
    private function buildPhpDocProperties(array $columns): array
    {
        $props = [];

        foreach ($columns as $column) {
            $phpType = $this->typeToPhpType($column['type']);
            if ($column['nullable']) {
                $phpType .= '|null';
            }
            $props[] = "@property $phpType \${$column['name']}";
        }

        return $props;
    }

    /**
     * Convert a table name to a PascalCase class name.
     *
     * @param string $table The table name (snake_case).
     * @return string The PascalCase class name.
     */
    private function tableToClassName(string $table): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $table)));
    }

    /**
     * Normalize a database column type string to a simplified form.
     *
     * @param string $type The raw database type.
     * @return string Simplified type name.
     */
    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        // Remove size/precision info: varchar(100) -> varchar
        $type = preg_replace('/\(.*\)/', '', $type) ?? $type;

        // Remove unsigned, zerofill, etc.
        $type = preg_replace('/\s+(unsigned|zerofill|varying)/', '', $type) ?? $type;

        return trim($type);
    }

    /**
     * Map a database type to a PHP cast name.
     *
     * @param string $type The normalized database type.
     * @return string|null The cast name, or null if no cast needed.
     */
    private function typeToCast(string $type): ?string
    {
        return match ($type) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'serial', 'bigserial' => 'int',
            'boolean', 'bool' => 'bool',
            'float', 'double', 'decimal', 'numeric', 'real', 'money' => 'float',
            'json', 'jsonb' => 'json',
            default => null,
        };
    }

    /**
     * Map a database type to a PHP type for PHPDoc.
     *
     * @param string $type The normalized database type.
     * @return string The PHP type string.
     */
    private function typeToPhpType(string $type): string
    {
        return match ($type) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'serial', 'bigserial' => 'int',
            'boolean', 'bool' => 'bool',
            'float', 'double', 'decimal', 'numeric', 'real', 'money' => 'float',
            'json', 'jsonb' => 'array',
            default => 'string',
        };
    }
}
