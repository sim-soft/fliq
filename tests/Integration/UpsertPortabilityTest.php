<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;

/**
 * Upsert against the databases it claims to work the same on.
 *
 * The unit tests hold the generated statement still; these check the row that
 * comes back. `setting` carries a composite unique key on (group, key), which
 * is the shape that showed the defects: an explicit update value reached MySQL
 * and was dropped everywhere else, and the facade could not name a conflict
 * target at all, so a composite key was unusable on the engines that require
 * one.
 *
 * Every test restores what it touched, from tearDown rather than from the end of
 * the test body — the PostgreSQL fixture is not reloaded between classes, so a
 * row left modified by a failing assertion stays modified for the whole run.
 */
class UpsertPortabilityTest extends DatabaseTestCase
{
    /** @var array<int, string> Connections these tests run against. */
    private const ENGINES = ['upsert_my', 'upsert_pg'];

    /** @var bool Whether the PostgreSQL fixture answered. */
    private static bool $pgAvailable = false;

    /** @var array<string, array<string, mixed>> Rows read before a test wrote to them, keyed by connection|group|key. */
    private array $snapshots = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!static::$dbAvailable) {
            return;
        }

        Connection::add('upsert_my', [
            'driver' => 'pdo_mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'sample_db',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ]);

        Connection::add('upsert_pg', [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: 'postgres',
        ]);

        try {
            (new Raw('SELECT 1'))->withConnection('upsert_pg')->fetchAll();
            self::$pgAvailable = true;
        } catch (\Throwable) {
            self::$pgAvailable = false;
        }
    }

    /**
     * The engines available in this environment.
     *
     * @return array<int, string>
     */
    private function engines(): array
    {
        return self::$pgAvailable ? self::ENGINES : ['upsert_my'];
    }

    /**
     * Read one setting row, whichever engine it lives on.
     *
     * @param string $connection The connection name.
     * @param string $group The setting group.
     * @param string $key The setting key.
     * @return array<string, mixed>|null
     */
    private function settingRow(string $connection, string $group, string $key): ?array
    {
        $rows = (new Raw(
            'SELECT ' . $this->q($connection, 'value') . ', metadata FROM setting'
            . ' WHERE ' . $this->q($connection, 'group') . ' = ?'
            . ' AND ' . $this->q($connection, 'key') . ' = ?',
            [$group, $key]
        ))->withConnection($connection)->fetchAll();

        return $rows[0] ?? null;
    }

    /**
     * Quote an identifier the way this connection's engine spells it.
     *
     * `group`, `key` and `value` are reserved words on both engines.
     *
     * @param string $connection The connection name.
     * @param string $name The identifier.
     * @return string
     */
    private function q(string $connection, string $name): string
    {
        return $connection === 'upsert_my' ? "`$name`" : "\"$name\"";
    }

    /**
     * How many rows the setting table holds.
     *
     * @param string $connection The connection name.
     * @return int
     */
    private function settingCount(string $connection): int
    {
        return (int)(new Raw('SELECT COUNT(*) AS c FROM setting'))
            ->withConnection($connection)->fetchAll()[0]['c'];
    }

    /**
     * Read a row and remember it, so tearDown can put it back.
     *
     * Every test here writes to a seed row, and the PostgreSQL fixture is not
     * reloaded between classes — so a row left modified stays modified for the
     * rest of the run. Restoring at the end of the test body is not enough: a
     * failing assertion aborts the body before it, which is how this class left
     * `mail/driver` and `mail/port` holding a NULL metadata and starved the
     * PostgreSQL JSON tests of rows they count. The snapshot is taken on read
     * and replayed from tearDown, which runs either way.
     *
     * @param string $connection The connection name.
     * @param string $group The setting group.
     * @param string $key The setting key.
     * @return array<string, mixed>|null
     */
    private function snapshotRow(string $connection, string $group, string $key): ?array
    {
        $row = $this->settingRow($connection, $group, $key);

        if ($row !== null) {
            $this->snapshots["$connection|$group|$key"] = $row;
        }

        return $row;
    }

    /**
     * Put every snapshotted row back, and drop any row this class inserted.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->snapshots as $id => $row) {
            [$connection, $group, $key] = explode('|', $id, 3);

            (new Raw(
                'UPDATE setting SET ' . $this->q($connection, 'value') . ' = ?, metadata = ?'
                . ' WHERE ' . $this->q($connection, 'group') . ' = ?'
                . ' AND ' . $this->q($connection, 'key') . ' = ?',
                [$row['value'], $row['metadata'], $group, $key]
            ))->withConnection($connection)->execute();
        }

        $this->snapshots = [];

        foreach ($this->engines() as $engine) {
            $this->deleteProbeRows($engine);
        }

        parent::tearDown();
    }

    #[Test]
    public function anExistingRowIsUpdatedInPlaceOnEveryEngine(): void
    {
        foreach ($this->engines() as $engine) {
            $this->assertIsArray($this->snapshotRow($engine, 'general', 'timezone'), "no seed row on $engine");
            $count = $this->settingCount($engine);

            (new Upsert(
                'setting',
                ['group' => 'general', 'key' => 'timezone', 'value' => 'Etc/UTC'],
                ['value'],
                ['group', 'key']
            ))->withConnection($engine)->execute();

            $after = $this->settingRow($engine, 'general', 'timezone');
            $this->assertIsArray($after);
            $this->assertSame('Etc/UTC', $after['value'], "value not updated on $engine");
            $this->assertSame($count, $this->settingCount($engine), "a row was inserted on $engine");
        }
    }

    #[Test]
    public function anExplicitUpdateValueReachesTheRowOnEveryEngine(): void
    {
        // The defect: ['value' => X] wrote X on MySQL and the inserted value on
        // PostgreSQL, from the same call, with no error either way.
        foreach ($this->engines() as $engine) {
            $this->assertIsArray($this->snapshotRow($engine, 'mail', 'host'), "no seed row on $engine");

            (new Upsert(
                'setting',
                ['group' => 'mail', 'key' => 'host', 'value' => 'INSERTED'],
                ['value' => 'FORCED'],
                ['group', 'key']
            ))->withConnection($engine)->execute();

            $after = $this->settingRow($engine, 'mail', 'host');
            $this->assertIsArray($after);
            $this->assertSame('FORCED', $after['value'], "explicit value dropped on $engine");
        }
    }

    #[Test]
    public function numericAndStringEntriesTakeTheirOwnValuesOnEveryEngine(): void
    {
        foreach ($this->engines() as $engine) {
            $this->assertIsArray($this->snapshotRow($engine, 'mail', 'port'), "no seed row on $engine");

            (new Upsert(
                'setting',
                ['group' => 'mail', 'key' => 'port', 'value' => 'FROM_ROW'],
                ['value', 'metadata' => '{"source":"override"}'],
                ['group', 'key']
            ))->withConnection($engine)->execute();

            $after = $this->settingRow($engine, 'mail', 'port');
            $this->assertIsArray($after);
            $this->assertSame('FROM_ROW', $after['value'], "numeric entry wrong on $engine");
            $this->assertIsString($after['metadata']);
            $this->assertStringContainsString('override', $after['metadata'], "explicit entry wrong on $engine");
        }
    }

    #[Test]
    public function aKeyThatDoesNotExistYetIsInsertedAsGiven(): void
    {
        foreach ($this->engines() as $engine) {
            $this->deleteProbeRows($engine);
            $count = $this->settingCount($engine);

            (new Upsert(
                'setting',
                ['group' => 'upsert_probe', 'key' => 'k', 'value' => 'INSERTED'],
                ['value' => 'FORCED'],
                ['group', 'key']
            ))->withConnection($engine)->execute();

            $after = $this->settingRow($engine, 'upsert_probe', 'k');
            $this->assertIsArray($after);

            // No conflict fired, so the assignment never ran: the row holds what
            // the INSERT carried, not the value named for a conflict.
            $this->assertSame('INSERTED', $after['value'], "insert path wrong on $engine");
            $this->assertSame($count + 1, $this->settingCount($engine));

            $this->deleteProbeRows($engine);
            $this->assertSame($count, $this->settingCount($engine));
        }
    }

    #[Test]
    public function theFacadeCanNameAConflictTargetOnEveryEngine(): void
    {
        // Without the fifth argument this could only ever name one column, so
        // an upsert against a composite key failed outright on PostgreSQL while
        // working on MySQL, which needs no target at all.
        foreach ($this->engines() as $engine) {
            $this->assertIsArray($this->snapshotRow($engine, 'cache', 'ttl'), "no seed row on $engine");

            DB::upsert(
                'setting',
                ['group' => 'cache', 'key' => 'ttl', 'value' => '999'],
                ['value'],
                $engine,
                ['group', 'key']
            );

            $after = $this->settingRow($engine, 'cache', 'ttl');
            $this->assertIsArray($after);
            $this->assertSame('999', $after['value'], "facade upsert wrong on $engine");
        }
    }

    #[Test]
    public function anExplicitValueIsBoundRatherThanWrittenIntoTheStatement(): void
    {
        foreach ($this->engines() as $engine) {
            $this->assertIsArray($this->snapshotRow($engine, 'general', 'site_name'), "no seed row on $engine");

            $hostile = "O'Brien'); DROP TABLE setting; --";

            (new Upsert(
                'setting',
                ['group' => 'general', 'key' => 'site_name', 'value' => 'x'],
                ['value' => $hostile],
                ['group', 'key']
            ))->withConnection($engine)->execute();

            $after = $this->settingRow($engine, 'general', 'site_name');
            $this->assertIsArray($after);
            $this->assertSame($hostile, $after['value'], "value not bound on $engine");

            // The table is still there, which is the actual claim.
            $this->assertGreaterThan(0, $this->settingCount($engine));
        }
    }

    #[Test]
    public function omittingTheConflictTargetIsRefusedWherePostgresRequiresOne(): void
    {
        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL is not available.');
        }

        // The documented behaviour: the fallback target is the first inserted
        // column, which is no unique constraint, so the engine says so rather
        // than updating on the wrong key.
        $this->expectException(\Simsoft\DB\Exceptions\QueryException::class);
        $this->expectExceptionMessageMatches('/ON CONFLICT/');

        (new Upsert(
            'setting',
            ['group' => 'general', 'key' => 'timezone', 'value' => 'z'],
            ['value']
        ))->withConnection('upsert_pg')->execute();
    }

    #[Test]
    public function mysqlNeedsNoTargetAndUpdatesOnItsOwnUniqueKey(): void
    {
        $this->assertIsArray($this->snapshotRow('upsert_my', 'general', 'locale'));
        $count = $this->settingCount('upsert_my');

        (new Upsert(
            'setting',
            ['group' => 'general', 'key' => 'locale', 'value' => 'xx'],
            ['value']
        ))->withConnection('upsert_my')->execute();

        $after = $this->settingRow('upsert_my', 'general', 'locale');
        $this->assertIsArray($after);
        $this->assertSame('xx', $after['value']);
        $this->assertSame($count, $this->settingCount('upsert_my'));
    }

    #[Test]
    public function anUpsertWithNoColumnsIsRefusedBeforeItReachesTheServer(): void
    {
        foreach ($this->engines() as $engine) {
            try {
                (new Upsert('setting', [], ['value'], ['group', 'key']))
                    ->withConnection($engine)->execute();
                $this->fail("an empty upsert should not have run on $engine");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('at least one column', $e->getMessage());
            }
        }
    }

    #[Test]
    public function bothEnginesLeaveTheSameRowBehindForTheSameCall(): void
    {
        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL is not available.');
        }

        foreach (self::ENGINES as $engine) {
            $this->assertIsArray($this->snapshotRow($engine, 'mail', 'driver'));

            (new Upsert(
                'setting',
                ['group' => 'mail', 'key' => 'driver', 'value' => 'FROM_ROW'],
                ['value', 'metadata' => '{"agree":true}'],
                ['group', 'key']
            ))->withConnection($engine)->execute();
        }

        $my = $this->settingRow('upsert_my', 'mail', 'driver');
        $pg = $this->settingRow('upsert_pg', 'mail', 'driver');
        $this->assertIsArray($my);
        $this->assertIsArray($pg);

        $this->assertSame($my['value'], $pg['value']);
        $this->assertIsString($my['metadata']);
        $this->assertIsString($pg['metadata']);
        $this->assertSame(
            json_decode($my['metadata'], true),
            json_decode($pg['metadata'], true)
        );
    }

    /**
     * Remove any row this class inserted.
     *
     * @param string $connection The connection name.
     * @return void
     */
    private function deleteProbeRows(string $connection): void
    {
        (new Raw(
            'DELETE FROM setting WHERE ' . $this->q($connection, 'group') . ' = ?',
            ['upsert_probe']
        ))->withConnection($connection)->execute();
    }
}
