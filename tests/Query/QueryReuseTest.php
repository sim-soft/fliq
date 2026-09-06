<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;
use Simsoft\DB\Traits\Fetchable;

/**
 * A query object must survive being read from.
 *
 * These tests need no database: they compare the SQL a query generates before
 * and after a terminal call, and check by reflection that the methods callers
 * are told to use are actually reachable.
 */
class QueryReuseTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    // ------------------------------------------------------------------
    // A trait method a class redeclares is silently unreachable
    // ------------------------------------------------------------------

    /**
     * The bug: ActiveQuery declares exists(ActiveQuery|Raw $query) for the SQL
     * EXISTS sub-query, and Fetchable declared exists(): bool for the row check.
     * PHP resolves that in the class's favour without a warning, so the
     * documented no-argument call raised ArgumentCountError instead of
     * answering. The row check now lives at hasRecords().
     */
    #[Test]
    public function rowExistenceCheckIsNotShadowedByTheSubQueryCondition(): void
    {
        $resolved = new ReflectionMethod(ActiveQuery::class, 'hasRecords');

        $this->assertSame(
            (new ReflectionMethod(Fetchable::class, 'hasRecords'))->getFileName(),
            $resolved->getFileName(),
            'hasRecords() should resolve to the trait, not to an ActiveQuery override.'
        );
        $this->assertSame(0, $resolved->getNumberOfRequiredParameters());

        $returnType = $resolved->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('bool', $returnType->getName());
    }

    #[Test]
    public function theSubQueryExistsConditionStillTakesAQuery(): void
    {
        $exists = new ReflectionMethod(ActiveQuery::class, 'exists');

        $this->assertSame(1, $exists->getNumberOfRequiredParameters());

        $returnType = $exists->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('static', $returnType->getName());
    }

    /**
     * The same collision could reappear on any other Fetchable method, and it
     * would again be silent. Comparing the resolved method's source location
     * against the trait's catches it: a flattened trait method keeps the
     * trait's file and line, so a mismatch means the class redeclared it.
     */
    #[Test]
    public function noFetchableMethodIsShadowedByActiveQuery(): void
    {
        $query = new ReflectionClass(ActiveQuery::class);
        $shadowed = [];

        foreach ((new ReflectionClass(Fetchable::class))->getMethods() as $method) {
            $resolved = $query->getMethod($method->getName());

            if ($resolved->getFileName() !== $method->getFileName()) {
                $shadowed[] = $method->getName();
            }
        }

        $this->assertSame([], $shadowed, 'ActiveQuery redeclares these Fetchable methods, making the trait version unreachable.');
    }

    // ------------------------------------------------------------------
    // first() / hasRecords() must not write their LIMIT onto the receiver
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function terminalCalls(): array
    {
        return [
            'first' => ['first'],
            'hasRecords' => ['hasRecords'],
        ];
    }

    #[Test]
    #[DataProvider('terminalCalls')]
    public function aTerminalCallLeavesTheQueryUnchanged(string $method): void
    {
        $query = DB::table('user')->where('score', '>', 0);
        $before = $query->getSQL();

        $query->$method();

        $this->assertSame($before, $query->getSQL());
        $this->assertStringNotContainsString('LIMIT', $query->getSQL());
    }

    #[Test]
    #[DataProvider('terminalCalls')]
    public function aTerminalCallLeavesTheBindsUnchanged(string $method): void
    {
        $query = DB::table('user')->where('score', '>', 40);
        $before = $query->getBinds();

        $query->$method();

        $this->assertSame($before, $query->getBinds());
    }

    /**
     * A limit the caller set is theirs, and must still be there afterwards.
     */
    #[Test]
    #[DataProvider('terminalCalls')]
    public function aTerminalCallPreservesAnExplicitLimit(string $method): void
    {
        $query = DB::table('user')->limit(5);
        $this->assertStringContainsString('LIMIT 5', $query->getSQL());

        $query->$method();

        $this->assertStringContainsString('LIMIT 5', $query->getSQL());
        $this->assertTrue($query->hasLimit());
    }

    #[Test]
    #[DataProvider('terminalCalls')]
    public function aTerminalCallDoesNotMakeAnUnlimitedQueryLimited(string $method): void
    {
        $query = DB::table('user');
        $this->assertFalse($query->hasLimit());

        $query->$method();

        $this->assertFalse($query->hasLimit());
    }

    #[Test]
    public function firstStillAsksForASingleRow(): void
    {
        $query = DB::table('user')->where('score', '>', 0);

        // The limit belongs to the statement first() sends, not to $query, so
        // it is observable only in what the driver is handed. Asserting the
        // receiver is untouched is the other half of the same fact.
        $query->first();

        $this->assertSame('SELECT `user`.* FROM `user` WHERE `user`.`score` > ?', $query->getSQL());
    }

    // ------------------------------------------------------------------
    // Insert-id normalisation
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function pdoBackedDrivers(): array
    {
        return [
            'pdo_mysql' => ['Simsoft\DB\Drivers\PDODriver'],
            'pgsql' => ['Simsoft\DB\Drivers\PostgresDriver'],
            'sqlite' => ['Simsoft\DB\Drivers\SQLiteDriver'],
        ];
    }

    /**
     * Every PDO-backed driver must route its insert id through the shared
     * normaliser, or it will report PDO's "0" as though it were a real key.
     *
     * @param class-string $driver
     */
    #[Test]
    #[DataProvider('pdoBackedDrivers')]
    public function pdoBackedDriversNormalizeTheirInsertId(string $driver): void
    {
        $method = new ReflectionMethod($driver, 'lastInsertId');
        $file = $method->getFileName();
        $this->assertIsString($file);

        $lines = file($file);
        $this->assertIsArray($lines);

        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString('normalizeInsertId', $body);
    }

    #[Test]
    public function everyDriverDeclaresTheSameInsertIdContract(): void
    {
        $drivers = [
            'Simsoft\DB\Drivers\MySQLiDriver',
            'Simsoft\DB\Drivers\PDODriver',
            'Simsoft\DB\Drivers\PostgresDriver',
            'Simsoft\DB\Drivers\SQLiteDriver',
        ];

        foreach ($drivers as $driver) {
            $returnType = (new ReflectionMethod($driver, 'lastInsertId'))->getReturnType();
            $this->assertNotNull($returnType, "$driver::lastInsertId() has no return type.");

            // Reflection prints the union in declaration order, so sort it.
            $parts = explode('|', (string)$returnType);
            sort($parts);

            $this->assertSame(['false', 'string'], $parts, $driver);
        }
    }

    /**
     * The Raw builder is the one Executable that carries no RETURNING clause,
     * so getLastInsertId() goes straight to the driver for it.
     */
    #[Test]
    public function getLastInsertIdIsTypedNullableString(): void
    {
        $returnType = (new ReflectionMethod(Raw::class, 'getLastInsertId'))->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());
    }

    /**
     * normalizeInsertId() takes the read as a callable so it can also catch a
     * throw — PostgreSQL raises rather than returning when lastval() is
     * undefined, and that is the same "no id" fact as MySQL's "0".
     */
    #[Test]
    public function insertIdNormalizerAcceptsAFallibleRead(): void
    {
        $normalize = new ReflectionMethod('Simsoft\DB\Drivers\Driver', 'normalizeInsertId');

        $this->assertTrue($normalize->isProtected());
        $this->assertSame(1, $normalize->getNumberOfRequiredParameters());

        $parameters = $normalize->getParameters();
        $this->assertInstanceOf(ReflectionParameter::class, $parameters[0]);

        $type = $parameters[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('callable', $type->getName());
    }
}
