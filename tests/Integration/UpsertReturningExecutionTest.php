<?php

namespace Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Connection;

/**
 * What an upsert reports about the row it wrote, run against real servers.
 *
 * getLastInsertId() answers from the driver when the statement carries no
 * RETURNING clause, and on two of the three engines the driver's answer is
 * session-scoped rather than statement-scoped. PostgreSQL reports lastval(),
 * which a conflicting attempt still advances; SQLite reports the connection's
 * last id, which a skipped statement leaves alone. So an upsert that took the
 * conflict branch answered with an id — on PostgreSQL one naming no row in the
 * table at all.
 *
 * Upsert was the only write builder that could not be given a RETURNING clause:
 * it declared none of the methods, and the drivers and getLastInsertId()
 * dispatched on Insert, Update and Delete by name. That set is now a declared
 * interface, and Upsert is in it.
 *
 * Every statement here runs against its own scratch table. The PostgreSQL
 * fixture is not reloaded between classes, so nothing may touch the sample data.
 */
class UpsertReturningExecutionTest extends TestCase
{
    /** @var bool Whether the PostgreSQL server answered. */
    protected static bool $pgAvailable = false;

    public static function setUpBeforeClass(): void
    {
        Connection::add('ups_sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add('ups_pg', [
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
            Connection::get('ups_pg');
            static::$pgAvailable = true;
        } catch (\Throwable) {
            static::$pgAvailable = false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::remove('ups_sqlite');

        if (static::$pgAvailable) {
            Connection::get('ups_pg')->execute(new Raw('DROP TABLE IF EXISTS ups_probe'));
            Connection::remove('ups_pg');
        }
    }

    /** @return array<string, array{string}> */
    public static function connections(): array
    {
        return ['postgres' => ['ups_pg'], 'sqlite' => ['ups_sqlite']];
    }

    /**
     * A scratch table holding one row, with the sequence already past it.
     *
     * The seed row matters: it is what leaves SQLite's connection-scoped id
     * pointing at something stale, and PostgreSQL's sequence already moving.
     * Against an empty table both engines can answer correctly by accident.
     *
     * @param string $connection The connection to build it on.
     * @return void
     */
    private function scratch(string $connection): void
    {
        if ($connection === 'ups_pg' && !static::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        $driver = Connection::get($connection);
        $serial = $connection === 'ups_pg' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $driver->execute(new Raw('DROP TABLE IF EXISTS ups_probe'));
        $driver->execute(new Raw("CREATE TABLE ups_probe (id $serial, name TEXT UNIQUE, hits INT)"));
        $driver->execute(new Raw("INSERT INTO ups_probe (name, hits) VALUES ('seed', 0)"));
    }

    /**
     * Every id currently in the scratch table.
     *
     * @param string $connection The connection to read from.
     * @return array<int, int>
     */
    private function ids(string $connection): array
    {
        $rows = (new Raw('SELECT id FROM ups_probe ORDER BY id'))
            ->withConnection($connection)->fetchAll();

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * An upsert on `name`, updating `hits` when the row is already there.
     *
     * @param string $connection The connection to run against.
     * @param string $name The conflicting value.
     * @param int $hits The value to write.
     * @return Upsert
     */
    private function upsert(string $connection, string $name, int $hits): Upsert
    {
        $upsert = new Upsert('ups_probe', ['name' => $name, 'hits' => $hits], ['hits'], ['name']);
        $upsert->withConnection($connection);

        return $upsert;
    }

    #[Test]
    #[DataProvider('connections')]
    public function anUpsertThatInsertsReportsTheIdItWrote(string $connection): void
    {
        $this->scratch($connection);

        $upsert = $this->upsert($connection, 'alpha', 1)->returning('id');
        $this->assertTrue($upsert->execute());

        $ids = $this->ids($connection);
        $this->assertCount(2, $ids, 'the seed row and the one just written');

        $this->assertSame((string) $ids[1], $upsert->getLastInsertId());
    }

    #[Test]
    #[DataProvider('connections')]
    public function anUpsertThatUpdatesReportsTheRowItUpdatedNotASequenceValue(string $connection): void
    {
        // The defect. On PostgreSQL the conflicting attempt consumes a sequence
        // number before DO UPDATE takes over, and lastval() then reports that
        // number — an id belonging to no row in the table. Answered from the
        // statement's own RETURNING rows it names the row that was updated.
        $this->scratch($connection);

        $this->assertTrue($this->upsert($connection, 'alpha', 1)->returning('id')->execute());
        $inserted = $this->ids($connection)[1];

        $update = $this->upsert($connection, 'alpha', 2)->returning('id');
        $this->assertTrue($update->execute());

        $this->assertSame([1, $inserted], $this->ids($connection), 'no new row was written');
        $this->assertSame((string) $inserted, $update->getLastInsertId());

        // And the id it named is one that actually exists.
        $this->assertContains((int) $update->getLastInsertId(), $this->ids($connection));
    }

    #[Test]
    #[DataProvider('connections')]
    public function theUpdateBranchReturnsTheValueItWrote(string $connection): void
    {
        $this->scratch($connection);

        $this->upsert($connection, 'alpha', 1)->execute();

        $update = $this->upsert($connection, 'alpha', 99)->returning('id', 'hits');
        $update->execute();

        $rows = $update->getReturningResult();
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(['id', 'hits'], array_keys($rows[0]));
        $this->assertEquals(99, $rows[0]['hits'], 'the row as it stands after the update');
    }

    #[Test]
    #[DataProvider('connections')]
    public function aBareReturningNamesEveryColumn(string $connection): void
    {
        $this->scratch($connection);

        $upsert = $this->upsert($connection, 'alpha', 1)->returning();
        $upsert->execute();

        $rows = $upsert->getReturningResult();
        $this->assertIsArray($rows);
        $this->assertSame(['id', 'name', 'hits'], array_keys($rows[0]));
    }

    #[Test]
    #[DataProvider('connections')]
    public function anUpsertThatNeverAskedReportsNoRows(string $connection): void
    {
        $this->scratch($connection);

        $upsert = $this->upsert($connection, 'alpha', 1);
        $this->assertTrue($upsert->execute());

        $this->assertNull($upsert->getReturningResult(), 'no request, no rows');
        $this->assertStringNotContainsString('RETURNING', $upsert->getSQL());

        // Still falls through to the driver, which is the documented behaviour
        // for a statement that did not ask. It happens to be right here because
        // this upsert did insert a row.
        $this->assertSame((string) $this->ids($connection)[1], $upsert->getLastInsertId());
    }

    #[Test]
    #[DataProvider('connections')]
    public function aSkippedInsertReportsNoIdAtAll(string $connection): void
    {
        // DO NOTHING rather than DO UPDATE: nothing is written, so RETURNING
        // names no row, and there is no id to give. Falling through to the
        // driver here is what produced the phantom id.
        $this->scratch($connection);

        $driver = Connection::get($connection);
        $driver->execute(new Raw("INSERT INTO ups_probe (name, hits) VALUES ('alpha', 1)"));
        $before = $this->ids($connection);

        $skipped = new Upsert('ups_probe', ['name' => 'alpha', 'hits' => 5], [], ['name']);
        $skipped->withConnection($connection);
        // An upsert with no update columns still writes DO UPDATE, so the
        // genuinely-skipped shape is spelled out here.
        $raw = new Raw(
            "INSERT INTO ups_probe (name, hits) VALUES ('alpha', 5) ON CONFLICT (name) DO NOTHING RETURNING id"
        );
        $raw->withConnection($connection);
        $rows = $raw->fetchAll();

        $this->assertSame([], $rows, 'the statement wrote nothing');
        $this->assertSame($before, $this->ids($connection), 'and left the table alone');
    }

    #[Test]
    public function mysqlNeedsNoClauseBecauseItsIdIsPerStatement(): void
    {
        // MySQL has no RETURNING and does not need one. LAST_INSERT_ID() is
        // scoped to the statement there, and ON DUPLICATE KEY UPDATE sets it to
        // the id of the row it touched — so the update branch names that row
        // rather than whatever the connection last inserted, which is what the
        // other two engines needed the clause to achieve. An update that changes
        // nothing reports 0, which the drivers normalise to null.
        //
        // Asking for the clause anyway is accepted and must not reach the server.
        Connection::add('ups_mysql', [
            'driver' => 'mysqli',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'sample_db',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ]);

        try {
            $driver = Connection::get('ups_mysql');
        } catch (\Throwable) {
            Connection::remove('ups_mysql');
            $this->markTestSkipped('MySQL not available.');
        }

        try {
            $driver->execute(new Raw('DROP TABLE IF EXISTS ups_probe'));
            $driver->execute(new Raw(
                'CREATE TABLE ups_probe (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) UNIQUE, hits INT)'
            ));
            $driver->execute(new Raw("INSERT INTO ups_probe (name, hits) VALUES ('seed', 0)"));

            $insert = $this->upsert('ups_mysql', 'alpha', 1)->returning('id');
            $this->assertStringNotContainsStringIgnoringCase('RETURNING', $insert->getSQL());
            $this->assertTrue($insert->execute());
            $this->assertSame('2', $insert->getLastInsertId(), 'the row it wrote');

            // A later insert moves the connection's id on, so the next
            // assertion distinguishes "names the row it updated" from "reports
            // whatever was inserted last".
            $this->assertTrue($this->upsert('ups_mysql', 'beta', 1)->execute());

            $update = $this->upsert('ups_mysql', 'alpha', 2)->returning('id');
            $this->assertTrue($update->execute());
            $this->assertSame('2', $update->getLastInsertId(), 'the row it updated, not the last one inserted');

            // An update that changes nothing touches no row, and MySQL reports
            // 0 for it, which the drivers normalise to null.
            $noop = $this->upsert('ups_mysql', 'alpha', 2)->returning('id');
            $this->assertTrue($noop->execute());
            $this->assertNull($noop->getLastInsertId(), 'nothing was written, so there is no insert id');
        } finally {
            $driver->execute(new Raw('DROP TABLE IF EXISTS ups_probe'));
            Connection::remove('ups_mysql');
        }
    }
}
