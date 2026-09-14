<?php

namespace Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Delete;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Update;
use Simsoft\DB\Connection;

/**
 * returning() with no arguments, executed against the servers that have the clause.
 *
 * The unit tests pin the SQL; these run it. A bare returning() used to emit no
 * clause at all, so the statement did its work and reported nothing back, and
 * getReturningResult() answered null exactly as it does for a statement that
 * never asked. Both PostgreSQL and SQLite 3.35+ accept RETURNING *, so both must
 * hand the rows back.
 *
 * Every statement here runs against its own scratch table: the PostgreSQL
 * fixture is not reloaded between classes, so nothing may touch the sample data.
 */
class ReturningAllColumnsExecutionTest extends TestCase
{
    protected static bool $pgAvailable = false;

    public static function setUpBeforeClass(): void
    {
        Connection::add('ret_sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add('ret_pg', [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: '',
            'charset' => 'utf8',
            'schema' => 'public',
        ]);

        try {
            Connection::get('ret_pg');
            static::$pgAvailable = true;
        } catch (\Throwable) {
            static::$pgAvailable = false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::remove('ret_sqlite');

        if (static::$pgAvailable) {
            Connection::get('ret_pg')->execute(new Raw('DROP TABLE IF EXISTS ret_all_probe'));
            Connection::remove('ret_pg');
        }
    }

    /** @return array<string, array{string}> */
    public static function connections(): array
    {
        return ['postgres' => ['ret_pg'], 'sqlite' => ['ret_sqlite']];
    }

    /**
     * Build the scratch table the statement under test will run against.
     */
    private function scratch(string $connection): void
    {
        if ($connection === 'ret_pg' && !static::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        $driver = Connection::get($connection);
        $serial = $connection === 'ret_pg' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY';

        $driver->execute(new Raw('DROP TABLE IF EXISTS ret_all_probe'));
        $driver->execute(new Raw("CREATE TABLE ret_all_probe (id $serial, name TEXT, n INT)"));
        $driver->execute(new Raw("INSERT INTO ret_all_probe (name, n) VALUES ('a', 1), ('b', 2), ('c', 3)"));
    }

    #[Test]
    #[DataProvider('connections')]
    public function anUpdateReturnsEveryColumnOfEveryRowItChanged(string $connection): void
    {
        $this->scratch($connection);

        $update = new Update('ret_all_probe', ['n' => 10], 'name = \'a\'');
        $update->withConnection($connection)->returning();
        $this->assertTrue($update->execute());

        $rows = $update->getReturningResult();
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(['id', 'name', 'n'], array_keys($rows[0]), 'RETURNING * names every column');
        $this->assertSame('a', $rows[0]['name']);
        $this->assertEquals(10, $rows[0]['n'], 'the value returned is the one written');
    }

    #[Test]
    #[DataProvider('connections')]
    public function aDeleteReturnsTheRowsItRemoved(string $connection): void
    {
        $this->scratch($connection);

        $delete = new Delete('ret_all_probe', 'n > 1');
        $delete->withConnection($connection)->returning();
        $this->assertTrue($delete->execute());

        $rows = $delete->getReturningResult();
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows, 'both matching rows come back');
        $this->assertSame(['b', 'c'], array_column($rows, 'name'));

        $check = new Raw('SELECT name FROM ret_all_probe');
        $check->withConnection($connection);
        $this->assertSame(['a'], array_column($check->fetchAll(), 'name'), 'and they really are gone');
    }

    #[Test]
    #[DataProvider('connections')]
    public function aStatementMatchingNothingReturnsAnEmptyListNotNull(string $connection): void
    {
        $this->scratch($connection);

        $update = new Update('ret_all_probe', ['n' => 99], 'name = \'nobody\'');
        $update->withConnection($connection)->returning();
        $update->execute();

        // null is the answer for a statement that never asked for RETURNING.
        // One that asked and matched no row has to be distinguishable from it.
        $this->assertSame([], $update->getReturningResult());
    }

    #[Test]
    #[DataProvider('connections')]
    public function namingColumnsReturnsOnlyThose(string $connection): void
    {
        $this->scratch($connection);

        $update = new Update('ret_all_probe', ['n' => 42], 'name = \'b\'');
        $update->withConnection($connection)->returning('id', 'n');
        $update->execute();

        $rows = $update->getReturningResult();
        $this->assertIsArray($rows);
        $this->assertSame(['id', 'n'], array_keys($rows[0]));
    }

    #[Test]
    #[DataProvider('connections')]
    public function aStatementThatNeverAskedStillReportsNothing(string $connection): void
    {
        $this->scratch($connection);

        $update = new Update('ret_all_probe', ['n' => 7], 'name = \'a\'');
        $update->withConnection($connection);
        $this->assertTrue($update->execute());

        $this->assertNull($update->getReturningResult(), 'no request, no rows');
        $this->assertStringNotContainsString('RETURNING', $update->getSQL());
    }

    #[Test]
    public function mysqlOmitsTheClauseAndStillPerformsTheWrite(): void
    {
        Connection::add('ret_mysql', [
            'driver' => 'mysqli',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'sample_db',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ]);

        try {
            $driver = Connection::get('ret_mysql');
        } catch (\Throwable) {
            Connection::remove('ret_mysql');
            $this->markTestSkipped('MySQL not available.');
        }

        $driver->execute(new Raw('DROP TABLE IF EXISTS ret_all_probe'));
        $driver->execute(new Raw('CREATE TABLE ret_all_probe (id INT PRIMARY KEY, n INT)'));
        $driver->execute(new Raw('INSERT INTO ret_all_probe (id, n) VALUES (1, 1)'));

        // MySQL has no RETURNING. The request must not reach the server, and it
        // must not stop the statement doing its work either.
        $update = new Update('ret_all_probe', ['n' => 5], 'id = 1');
        $update->withConnection('ret_mysql')->returning();

        $this->assertStringNotContainsString('RETURNING', $update->getSQL());
        $this->assertTrue($update->execute());

        $check = new Raw('SELECT n FROM ret_all_probe WHERE id = 1');
        $check->withConnection('ret_mysql');
        $this->assertEquals(5, $check->fetchAll()[0]['n'], 'the write happened');

        $driver->execute(new Raw('DROP TABLE IF EXISTS ret_all_probe'));
        Connection::remove('ret_mysql');
    }
}
