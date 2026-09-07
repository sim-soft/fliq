<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Connection;

/**
 * What an upsert must mean the same way on every engine.
 *
 * Upsert used to build MySQL's statement itself and hand every other engine to
 * the grammar, and only its own branch understood an explicit update value —
 * so ['value' => 'x'] wrote 'x' on MySQL and the inserted value on PostgreSQL
 * and SQLite, with nothing said either way. The wrapper around the assignments
 * is the only part that varies by engine; these tests hold the rest still.
 */
class UpsertPortabilityTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();

        Connection::add('mysql', [
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'test',
            'username' => 'root', 'password' => '',
        ]);
        Connection::add('pgsql', [
            'driver' => 'pgsql', 'host' => 'localhost', 'database' => 'test',
            'username' => 'postgres', 'password' => '',
        ]);
        Connection::add('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * The three connections registered by setUp().
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function connections(): array
    {
        return [
            'mysql' => ['mysql'],
            'pgsql' => ['pgsql'],
            'sqlite' => ['sqlite'],
        ];
    }

    /**
     * Build an upsert against one connection.
     *
     * @param non-empty-string $connection The connection name.
     * @param array<int|string, mixed> $updateColumns Columns to update on conflict.
     * @param array<int, string> $conflictColumns The conflict target.
     * @return Upsert
     */
    private function upsert(string $connection, array $updateColumns, array $conflictColumns = ['a', 'b']): Upsert
    {
        $upsert = new Upsert(
            'up',
            ['a' => 'x', 'b' => 'y', 'v' => 'NEW'],
            $updateColumns,
            $conflictColumns
        );
        $upsert->withConnection($connection);

        // Binds are collected while the statement is built, so the statement
        // has to exist before getBinds() answers with anything — which is the
        // order execute() and dump() use.
        $upsert->getSQL();

        return $upsert;
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function anExplicitUpdateValueIsBoundOnEveryEngine(string $connection): void
    {
        $upsert = $this->upsert($connection, ['v' => 'FORCED']);

        // The value has to reach the server, and it has to reach it as a bind:
        // it used to be dropped entirely on the engines that take a conflict
        // target, so the row kept the inserted value instead.
        $this->assertSame(['x', 'y', 'NEW', 'FORCED'], $upsert->getBinds());
        $this->assertStringContainsString('= ?', $upsert->getSQL());
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function anExplicitValueIsNeverInterpolated(string $connection): void
    {
        $upsert = $this->upsert($connection, ['v' => "O'Brien; DROP TABLE up"]);

        $this->assertStringNotContainsString('DROP TABLE', $upsert->getSQL());
        $this->assertContains("O'Brien; DROP TABLE up", (array)$upsert->getBinds());
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function bindsRunInsertedValuesFirstThenExplicitOnes(string $connection): void
    {
        // Statement order, not array order: the assignments come after VALUES,
        // so their binds must too or every value lands one column over.
        $upsert = $this->upsert($connection, ['v' => 'FORCED', 'b' => 'SECOND']);

        $this->assertSame(['x', 'y', 'NEW', 'FORCED', 'SECOND'], $upsert->getBinds());
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function aNumericEntryTakesTheInsertedValueAndBindsNothing(string $connection): void
    {
        $upsert = $this->upsert($connection, ['v']);

        $this->assertSame(['x', 'y', 'NEW'], $upsert->getBinds());
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function numericAndStringEntriesCanBeMixed(string $connection): void
    {
        $upsert = $this->upsert($connection, ['v' => 'FORCED', 'b']);
        $sql = $upsert->getSQL();

        // v takes a bind, b takes the engine's word for the inserted row.
        $this->assertSame(['x', 'y', 'NEW', 'FORCED'], $upsert->getBinds());
        $this->assertMatchesRegularExpression('/[`"]v[`"] = \?/', $sql);
        $this->assertMatchesRegularExpression('/[`"]b[`"] = (VALUES\(|EXCLUDED\.|excluded\.)/', $sql);
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function omittingUpdateColumnsUpdatesEveryColumn(string $connection): void
    {
        $upsert = $this->upsert($connection, []);
        $sql = $upsert->getSQL();

        foreach (['a', 'b', 'v'] as $column) {
            $this->assertMatchesRegularExpression(
                '/[`"]' . $column . '[`"] = (VALUES\(|EXCLUDED\.|excluded\.)/',
                $sql
            );
        }

        $this->assertSame(['x', 'y', 'NEW'], $upsert->getBinds());
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function anUpsertWithNoColumnsIsRefusedRatherThanBuilt(string $connection): void
    {
        // INSERT INTO t () VALUES () is not a statement any engine accepts. It
        // used to reach MySQL and be rejected there, and on the others read
        // $columns[0] off an empty array — "Undefined array key 0", then a
        // TypeError naming a line in the grammar rather than the argument.
        $upsert = new Upsert('up', [], ['v'], ['a']);
        $upsert->withConnection($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UPSERT requires at least one column => value pair');

        $upsert->getSQL();
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function aColumnNameCarryingSqlIsRefused(string $connection): void
    {
        $upsert = new Upsert('up', ['a' => 1], ['v` = 1, `a' => 2], ['a']);
        $upsert->withConnection($connection);

        $this->expectException(InvalidArgumentException::class);

        $upsert->getSQL();
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function aConflictColumnCarryingSqlIsRefused(string $connection): void
    {
        $upsert = new Upsert('up', ['a' => 1], ['a'], ['a) DO NOTHING --']);
        $upsert->withConnection($connection);

        $this->expectException(InvalidArgumentException::class);

        $upsert->getSQL();
    }

    #[Test]
    public function mysqlTakesNoConflictTargetAndSaysSo(): void
    {
        $grammar = Connection::grammar('mysql');

        $this->assertFalse($grammar->requiresConflictTarget());
        $this->assertStringNotContainsString('ON CONFLICT', $this->upsert('mysql', ['v'])->getSQL());
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('conflictTargetEngines')]
    public function theOtherEnginesNameTheTargetTheyWereGiven(string $connection): void
    {
        $grammar = Connection::grammar($connection);

        $this->assertTrue($grammar->requiresConflictTarget());
        $this->assertStringContainsString(
            'ON CONFLICT ("a", "b") DO UPDATE SET',
            $this->upsert($connection, ['v'])->getSQL()
        );
    }

    /**
     * The engines that reject an upsert with no conflict target.
     *
     * @return array<string, array{0: non-empty-string}>
     */
    public static function conflictTargetEngines(): array
    {
        return [
            'pgsql' => ['pgsql'],
            'sqlite' => ['sqlite'],
        ];
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('conflictTargetEngines')]
    public function withoutATargetTheFirstColumnIsUsed(string $connection): void
    {
        // Not a good guess — the first inserted column is rarely the key, and
        // the engine rejects a target that is no unique constraint. It is the
        // documented fallback, and better than a target that parses and then
        // updates on the wrong key.
        $upsert = $this->upsert($connection, ['v'], []);

        $this->assertStringContainsString('ON CONFLICT ("a") DO UPDATE SET', $upsert->getSQL());
    }

    #[Test]
    public function eachEngineSpellsTheInsertedRowItsOwnWay(): void
    {
        $this->assertSame('VALUES(`v`)', Connection::grammar('mysql')->excludedColumnSQL('v'));
        $this->assertSame('EXCLUDED."v"', Connection::grammar('pgsql')->excludedColumnSQL('v'));
        $this->assertSame('excluded."v"', Connection::grammar('sqlite')->excludedColumnSQL('v'));
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function theInsertHalfIsTheSameStatementEverywhere(string $connection): void
    {
        $sql = $this->upsert($connection, ['v'])->getSQL();

        $this->assertMatchesRegularExpression(
            '/^INSERT INTO [`"]up[`"] \([`"]a[`"], [`"]b[`"], [`"]v[`"]\) VALUES \(\?, \?, \?\) ON /',
            $sql
        );
    }

    /**
     * @param non-empty-string $connection The connection to build against.
     * @return void
     */
    #[Test]
    #[DataProvider('connections')]
    public function nullIsAnExplicitValueLikeAnyOther(string $connection): void
    {
        $upsert = $this->upsert($connection, ['v' => null]);

        $this->assertSame(['x', 'y', 'NEW', null], $upsert->getBinds());
    }
}
