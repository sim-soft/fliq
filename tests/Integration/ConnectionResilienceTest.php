<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\ConnectionException;

/**
 * Tests how the drivers behave when the server drops a connection.
 *
 * Uses MySQL's KILL to sever a connection for real rather than simulating it,
 * since the whole point is what happens against a live server.
 */
class ConnectionResilienceTest extends TestCase
{
    protected static bool $available = false;

    /** @var array<string, mixed> */
    private static array $config = [];

    public static function setUpBeforeClass(): void
    {
        self::$config = [
            'driver' => 'pdo_mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'database' => getenv('DB_DATABASE') ?: 'sample_db',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ];

        Connection::add('resilience', self::$config);
        Connection::add('resilience_killer', self::$config);

        try {
            Connection::get('resilience');
            Connection::get('resilience_killer');
            static::$available = true;
        } catch (\Throwable) {
            static::$available = false;
        }
    }

    protected function setUp(): void
    {
        if (!static::$available) {
            $this->markTestSkipped('MySQL not available.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::reset();
    }

    /**
     * Sever the given driver's connection from another session.
     *
     * @param \Simsoft\DB\Drivers\Driver $driver The driver to disconnect.
     * @return void
     */
    private function killConnection(\Simsoft\DB\Drivers\Driver $driver): void
    {
        $id = $driver->query(new Raw('SELECT CONNECTION_ID() AS id'))[0]['id'];

        Connection::get('resilience_killer')->execute(new Raw("KILL $id"));

        // Give the server a moment to actually drop it
        usleep(300000);
    }

    /**
     * The username of user 1, read over a connection that was never killed.
     *
     * These tests are about whether a reconnected connection reads what a live
     * one reads, so the expected value comes from a live one rather than from
     * the fixture. Asserting the literal 'alice' coupled them to a column that
     * other test classes legitimately write to: this class extends TestCase and
     * so never reloads sample_db.sql, and a run that happened to order
     * BuilderRebuildExecutionTest first — which sets user 1's username to
     * 'ALPHA' by design and does not put it back — failed here instead of
     * there. It was a seed-dependent failure in a test that had nothing to do
     * with the change that exposed it.
     *
     * @return string
     */
    private function currentUsername(): string
    {
        $rows = Connection::get('resilience_killer')
            ->query(new Raw('SELECT `username` FROM `user` WHERE `id` = ?', [1]));

        $this->assertCount(1, $rows, 'user 1 is part of the fixture and must exist');

        return (string)$rows[0]['username'];
    }

    #[Test]
    public function aDroppedConnectionIsReestablishedTransparently(): void
    {
        $driver = Connection::get('resilience');
        $driver->query(new Raw('SELECT 1'));

        $expected = $this->currentUsername();

        $this->killConnection($driver);

        // Skipping the ping means the dead connection is not noticed until the
        // statement fails, so the statement itself has to drive the recovery.
        $rows = $driver->query(new Raw('SELECT `username` FROM `user` WHERE `id` = ?', [1]));

        $this->assertCount(1, $rows);
        $this->assertEquals($expected, $rows[0]['username']);
    }

    #[Test]
    public function writesAlsoRecoverFromADroppedConnection(): void
    {
        $driver = Connection::get('resilience');
        $driver->query(new Raw('SELECT 1'));

        $this->killConnection($driver);

        $written = $driver->execute(new Raw(
            'INSERT INTO `user` (`username`, `email`, `password`, `role`) VALUES (?, ?, ?, ?)',
            ['resilience_probe', 'resilience_probe@example.com', 'x', 'admin']
        ));

        $this->assertTrue($written);

        // Cleanup
        $driver->execute(new Raw('DELETE FROM `user` WHERE `username` = ?', ['resilience_probe']));
    }

    #[Test]
    public function aConnectionLostInsideATransactionThrowsRatherThanRetrying(): void
    {
        $driver = Connection::get('resilience');

        try {
            $driver->transaction(function () use ($driver) {
                $driver->execute(new Raw(
                    'INSERT INTO `user` (`username`, `email`, `password`, `role`) VALUES (?, ?, ?, ?)',
                    ['tx_probe_1', 'tx_probe_1@example.com', 'x', 'admin']
                ));

                $this->killConnection($driver);

                // Retrying this on a new connection would commit it alone,
                // leaving the first insert behind — a partial write.
                $driver->execute(new Raw(
                    'INSERT INTO `user` (`username`, `email`, `password`, `role`) VALUES (?, ?, ?, ?)',
                    ['tx_probe_2', 'tx_probe_2@example.com', 'x', 'admin']
                ));

                return true;
            });

            $this->fail('losing the connection mid-transaction did not throw');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('lost', $exception->getMessage());
        }

        // Neither row survives, and the depth counter is not left stale
        $this->assertEquals(0, $driver->getTransactionLevel());

        $left = Connection::get('resilience_killer')->query(new Raw(
            "SELECT `username` FROM `user` WHERE `username` IN ('tx_probe_1', 'tx_probe_2')"
        ));
        $this->assertCount(0, $left, 'a partial write survived');
    }

    #[Test]
    public function aGenuineSqlErrorIsNotRetriedOrSwallowed(): void
    {
        $driver = Connection::get('resilience');

        $this->expectException(\PDOException::class);

        $driver->query(new Raw('SELECT * FROM `no_such_table_at_all`'));
    }

    #[Test]
    public function aLiveConnectionIsNotPingedBeforeEveryQuery(): void
    {
        // The ping costs a full round trip, which was most of the cost of a
        // small query. A connection used moments ago is not re-checked.
        // Counted rather than timed, so a slow CI machine cannot make this flap.
        $driver = Connection::get('resilience');
        $driver->query(new Raw('SELECT 1'));

        $runs = 20;
        $before = $this->selectCount($driver);

        for ($i = 0; $i < $runs; ++$i) {
            $driver->query(new Raw('SELECT `id` FROM `user` WHERE `id` = ?', [1]));
        }

        // One SELECT per query and nothing more: no pings were sent.
        $this->assertEquals(
            $before + $runs,
            $this->selectCount($driver),
            'a live connection was pinged during the idle window'
        );
    }

    #[Test]
    public function pingIdleSecondsOfZeroPingsEveryTime(): void
    {
        // The old always-ping behaviour stays available for anyone who wants it.
        Connection::add('resilience_eager', [...self::$config, 'ping_idle_seconds' => 0]);
        $driver = Connection::get('resilience_eager');
        $driver->query(new Raw('SELECT 1'));

        $runs = 5;
        $before = $this->selectCount($driver);

        for ($i = 0; $i < $runs; ++$i) {
            $driver->query(new Raw('SELECT `id` FROM `user` WHERE `id` = ?', [1]));
        }

        // Each query now costs two SELECTs: the ping and the query itself.
        // The closing selectCount() pings as well, hence the extra one.
        $this->assertEquals(
            $before + ($runs * 2) + 1,
            $this->selectCount($driver),
            'expected one ping per query'
        );

        Connection::remove('resilience_eager');
    }

    /**
     * Read this session's cumulative SELECT count from the server.
     *
     * Every query counts one, and each `SELECT 1` ping counts one more, so
     * comparing the delta against the number of queries issued reveals whether
     * pings were sent. `SHOW` does not count towards it, so reading the counter
     * does not disturb it.
     *
     * @param \Simsoft\DB\Drivers\Driver $driver The driver to inspect.
     * @return int
     */
    private function selectCount(\Simsoft\DB\Drivers\Driver $driver): int
    {
        $rows = $driver->query(new Raw("SHOW SESSION STATUS LIKE 'Com_select'"));

        return (int)$rows[0]['Value'];
    }

    #[Test]
    public function anIdleConnectionIsStillChecked(): void
    {
        // The point of the idle window is to keep checking connections that
        // have gone quiet, which is when they actually get dropped.
        Connection::add('resilience_impatient', [...self::$config, 'ping_idle_seconds' => 0.05]);
        $driver = Connection::get('resilience_impatient');
        $driver->query(new Raw('SELECT 1'));

        $expected = $this->currentUsername();

        $this->killConnection($driver);

        // The kill took longer than the idle window, so the ping runs and
        // reconnects before the statement is even attempted.
        $rows = $driver->query(new Raw('SELECT `username` FROM `user` WHERE `id` = ?', [1]));
        $this->assertEquals($expected, $rows[0]['username']);

        Connection::remove('resilience_impatient');
    }
}
