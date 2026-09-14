<?php

namespace Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;

/**
 * Root JSON paths, checked against what the servers actually return.
 *
 * jsonHas('metadata') — a column with no ->key — used to build
 * jsonb_exists(metadata, '') on PostgreSQL, asking whether a key named ''
 * existed. None does, so the call reported that no row had a metadata
 * document while every row had one, and jsonMissing reported the reverse.
 * MySQL built the path '$.' and the server rejected it. Neither is now
 * reachable: the call is refused.
 *
 * The paths that do have a root meaning — containment, extraction, length —
 * are checked here against the same fixture on both engines, since the
 * PostgreSQL forms are new and a wrong one would answer quietly.
 */
class JsonRootPathTest extends DatabaseTestCase
{
    /** @var bool Whether a PostgreSQL server answered at setup. */
    private static bool $pgAvailable = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        Connection::add('jsonpg', [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: '',
            'charset' => 'utf8',
            'schema' => 'public',
        ]);

        try {
            DB::query('SELECT 1 FROM setting LIMIT 1', [], 'jsonpg');
            self::$pgAvailable = true;
        } catch (\Throwable) {
            self::$pgAvailable = false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Connection::remove('jsonpg');
        } catch (\Throwable) {
            // Already gone.
        }
    }

    /** Count the rows a built query returns. */
    private function rowCount(ActiveQuery $query): int
    {
        $n = 0;

        foreach ($query->all() as $row) {
            $n++;
        }

        return $n;
    }

    private function settings(string $connection): ActiveQuery
    {
        return (new ActiveQuery())->withConnection($connection)->from('setting')->select('id');
    }

    /**
     * The fixture the expectations below rest on: every setting row carries a
     * metadata document. This is the fact the old jsonHas/jsonMissing pair
     * got backwards.
     */
    #[Test]
    public function everySettingRowHasAMetadataDocument(): void
    {
        $rows = DB::query('SELECT COUNT(*) c FROM setting WHERE metadata IS NOT NULL', [], 'mysql');

        $this->assertSame(10, (int)$rows[0]['c']);
        $this->assertSame(10, $this->rowCount($this->settings('mysql')->notNull('metadata')));
    }

    #[Test]
    public function jsonHasOnABareColumnIsRefusedRatherThanAnswered(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON key path is required');

        $this->settings('mysql')->jsonHas('metadata');
    }

    #[Test]
    public function jsonMissingOnABareColumnIsRefusedRatherThanAnswered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings('mysql')->jsonMissing('metadata');
    }

    /**
     * The keyed form still answers, and agrees with the server asked directly.
     */
    #[Test]
    public function jsonHasWithAKeyMatchesTheServer(): void
    {
        $expected = DB::query(
            "SELECT COUNT(*) c FROM setting WHERE JSON_CONTAINS_PATH(metadata, 'one', '$.priority')",
            [],
            'mysql'
        );

        $this->assertSame(
            (int)$expected[0]['c'],
            $this->rowCount($this->settings('mysql')->jsonHas('metadata->priority'))
        );
    }

    #[Test]
    public function jsonMissingWithAKeyMatchesTheServer(): void
    {
        $expected = DB::query(
            "SELECT COUNT(*) c FROM setting WHERE NOT JSON_CONTAINS_PATH(metadata, 'one', '$.nope')",
            [],
            'mysql'
        );

        $this->assertSame(
            (int)$expected[0]['c'],
            $this->rowCount($this->settings('mysql')->jsonMissing('metadata->nope'))
        );
    }

    /**
     * Root containment on MySQL: JSON_CONTAINS against '$'. Three rows carry
     * priority 1, which is the answer the root form must give.
     */
    #[Test]
    public function rootContainmentOnMysqlMatchesTheServer(): void
    {
        $expected = DB::query(
            'SELECT COUNT(*) c FROM setting WHERE JSON_CONTAINS(metadata, \'{"priority":1}\', \'$\')',
            [],
            'mysql'
        );

        $this->assertSame(3, (int)$expected[0]['c'], 'fixture: 3 rows have priority 1');
        $this->assertSame(
            3,
            $this->rowCount($this->settings('mysql')->jsonContains('metadata', ['priority' => 1]))
        );
    }

    /**
     * The same question on PostgreSQL. Before the fix this navigated to a key
     * named '' and returned 0 while MySQL returned 3.
     */
    #[Test]
    public function rootContainmentOnPostgresAgreesWithMysql(): void
    {
        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        $this->assertSame(
            3,
            $this->rowCount($this->settings('jsonpg')->jsonContains('metadata', ['priority' => 1]))
        );
    }

    /**
     * Root extraction on PostgreSQL: `column #>> '{}'` renders the document as
     * text, where `column -> ''` produced NULL for every row.
     */
    #[Test]
    public function rootExtractionOnPostgresReturnsTheDocument(): void
    {
        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        $rows = DB::query("SELECT COUNT(*) c FROM setting WHERE metadata #>> '{}' IS NOT NULL", [], 'jsonpg');
        $this->assertSame(10, (int)$rows[0]['c']);

        $empty = DB::query("SELECT COUNT(*) c FROM setting WHERE metadata -> '' IS NOT NULL", [], 'jsonpg');
        $this->assertSame(0, (int)$empty[0]['c'], 'the old form matched nothing');
    }

    /**
     * A keyed length on both engines, which must agree.
     */
    #[Test]
    public function keyedLengthAgreesAcrossEngines(): void
    {
        $mysql = $this->rowCount($this->settings('mysql')->whereJsonLength('metadata->tags', '=', 2));

        $this->assertSame(5, $mysql, 'fixture: 5 rows have exactly 2 tags');

        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        $this->assertSame(
            $mysql,
            $this->rowCount($this->settings('jsonpg')->whereJsonLength('metadata->tags', '=', 2))
        );
    }

    /**
     * Keyed containment on both engines, likewise.
     */
    #[Test]
    public function keyedContainmentAgreesAcrossEngines(): void
    {
        $mysql = $this->rowCount($this->settings('mysql')->jsonContains('metadata->tags', 'core'));

        $this->assertGreaterThan(0, $mysql);

        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        $this->assertSame(
            $mysql,
            $this->rowCount($this->settings('jsonpg')->jsonContains('metadata->tags', 'core'))
        );
    }

    /**
     * whereJsonValue reaches jsonExtract, so the root branch must not have
     * disturbed the keyed path it normally takes.
     */
    #[Test]
    public function keyedJsonValueAgreesAcrossEngines(): void
    {
        $mysql = $this->rowCount($this->settings('mysql')->whereJsonValue('metadata->priority', 1));

        $this->assertSame(3, $mysql);

        if (!self::$pgAvailable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }

        $this->assertSame(
            $mysql,
            $this->rowCount($this->settings('jsonpg')->whereJsonValue('metadata->priority', 1))
        );
    }
}
