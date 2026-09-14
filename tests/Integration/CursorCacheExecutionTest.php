<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Cache\ArrayCache;
use Simsoft\DB\Cache\QueryCache;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;
use Simsoft\DB\Exceptions\QueryException;

/**
 * The cursor/cache conflict, against the drivers that disagreed about it.
 *
 * The unit tests run on SQLite, which is PDO and so only ever saw one half of
 * the divergence. These run the same code on MySQLi — which falls back to
 * all() and therefore did cache — and on PDO, to show that both now answer the
 * same way, and that the buffered reads they share still cache.
 */
class CursorCacheExecutionTest extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!static::$dbAvailable) {
            return;
        }

        // The same server through PDO rather than mysqli: same rows, the other
        // half of the fallback in cursor().
        Connection::add('cc_pdo', [
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        QueryCache::reset();
    }

    protected function tearDown(): void
    {
        QueryCache::reset();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return ['mysqli' => ['mysql'], 'pdo' => ['cc_pdo']];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function cachingACursorIsRefusedOnEveryDriver(string $connection): void
    {
        QueryCache::setDriver(new ArrayCache());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cursor() cannot cache');

        iterator_to_array(User::find()->withConnection($connection)->cache(60)->cursor());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function anUncachedCursorReadsTheSameRowsOnEveryDriver(string $connection): void
    {
        QueryCache::setDriver(new ArrayCache());

        $rows = iterator_to_array(
            User::find()->withConnection($connection)->where('status_code', 1)->cursor()
        );

        $this->assertCount(8, $rows);
    }

    #[Test]
    #[DataProvider('drivers')]
    public function aCursorNeverServesRowsFromBeforeAWrite(string $connection): void
    {
        // This is the defect as a caller met it: on mysqli the second read came
        // from the cache and reported the pre-UPDATE value, while the identical
        // code on PDO reported the new one.
        QueryCache::setDriver(new ArrayCache());

        $read = static function () use ($connection): int {
            $query = new ActiveQuery()
                ->from('user')
                ->select('status_code')
                ->where('id', 9)
                ->withConnection($connection);

            foreach ($query->cursor() as $row) {
                return (int)$row['status_code'];
            }

            return -1;
        };

        $before = $read();
        DB::raw('UPDATE user SET status_code = ? WHERE id = 9', [$before + 50], 'mysql');

        $this->assertSame($before + 50, $read(), 'a cursor must read the database, not a cache');

        DB::raw('UPDATE user SET status_code = ? WHERE id = 9', [$before], 'mysql');
    }

    #[Test]
    #[DataProvider('drivers')]
    public function theBufferedReadsStillServeFromTheCache(string $connection): void
    {
        // The refusal must not have disabled caching generally: all() still
        // answers from the cache after the underlying row has changed.
        QueryCache::setDriver(new ArrayCache());

        $read = static fn(): int => (int)iterator_to_array(
            new ActiveQuery()
                ->from('user')
                ->select('status_code')
                ->where('id', 10)
                ->withConnection($connection)
                ->cache(60)
                ->getArray()
        )[0]['status_code'];

        $before = $read();
        DB::raw('UPDATE user SET status_code = ? WHERE id = 10', [$before + 50], 'mysql');

        $this->assertSame($before, $read(), 'the cached rows should still be served');

        QueryCache::reset();
        DB::raw('UPDATE user SET status_code = ? WHERE id = 10', [$before], 'mysql');
    }

    #[Test]
    public function aRefusedCursorRunsNoQueryAtAll(): void
    {
        // The check sits ahead of getDriver(), so a contradictory request costs
        // no connection and no statement.
        QueryCache::setDriver(new ArrayCache());

        $query = User::find()->withConnection('mysql')->cache(60);

        try {
            iterator_to_array($query->cursor());
            self::fail('expected a QueryException');
        } catch (QueryException) {
            // The connection is still usable for an ordinary read.
            $rows = DB::query('SELECT id FROM user WHERE id = 1', [], 'mysql');
            $this->assertSame(1, (int)$rows[0]['id']);
        }
    }
}
