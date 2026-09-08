<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Cache\ArrayCache;
use Simsoft\DB\Cache\QueryCache;
use Simsoft\DB\Connection;
use Simsoft\DB\Exceptions\QueryException;

/**
 * cursor() and cache() cannot both be honoured.
 *
 * Caching stores the whole result set, which is the one thing a cursor exists
 * not to do, so which of the two won came down to the driver: PDO never
 * consulted the cache and streamed, while mysqli fell back to all(), buffered
 * the set, and served it from cache on the next call. The same code read the
 * database on one driver and returned pre-UPDATE rows on the other. Asking for
 * both now raises, identically everywhere.
 */
class CursorCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('cc_sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        $driver = Connection::get('cc_sqlite');
        $driver->execute(new Raw('CREATE TABLE cc (id INTEGER PRIMARY KEY, name TEXT)'));
        $driver->execute(new Raw("INSERT INTO cc (id, name) VALUES (1, 'a'), (2, 'b'), (3, 'c')"));

        QueryCache::reset();
    }

    protected function tearDown(): void
    {
        QueryCache::reset();
        Connection::reset();
    }

    /**
     * @return ActiveQuery A query over the scratch table.
     */
    private function query(): ActiveQuery
    {
        return new ActiveQuery()->from('cc')->select('id', 'name')->withConnection('cc_sqlite');
    }

    #[Test]
    public function askingToCacheACursorIsRefused(): void
    {
        QueryCache::setDriver(new ArrayCache());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cursor() cannot cache');

        iterator_to_array($this->query()->cache(60)->cursor());
    }

    #[Test]
    public function theRefusalDoesNotDependOnACacheDriverBeingRegistered(): void
    {
        // The request is contradictory whether or not a backend happens to be
        // configured; answering differently would put the driver's state into
        // what the call means.
        $this->assertFalse(QueryCache::isEnabled());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cursor() cannot cache');

        iterator_to_array($this->query()->cache(60)->cursor());
    }

    #[Test]
    public function theMessageSaysWhatToDoInstead(): void
    {
        try {
            iterator_to_array($this->query()->cache(60)->cursor());
            self::fail('expected a QueryException');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('all()', $exception->getMessage());
            $this->assertStringContainsString('drop cache()', $exception->getMessage());
        }
    }

    #[Test]
    public function theRefusalHappensBeforeAnyRowIsFetched(): void
    {
        // cursor() is a generator, so a check placed after the first yield
        // would not fire until the caller iterated. This one must fire on the
        // first advance, before the statement runs.
        $generator = $this->query()->cache(60)->cursor();

        $this->expectException(QueryException::class);
        iterator_to_array($generator);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonCachingTtls(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'large negative' => [-3600]];
    }

    #[Test]
    #[DataProvider('nonCachingTtls')]
    public function aTtlThatCachesNothingDoesNotBlockTheCursor(int $ttl): void
    {
        // resolveCacheDriver() treats ttl <= 0 as "not cached", so such a query
        // asks for nothing the cursor cannot give.
        QueryCache::setDriver(new ArrayCache());

        $rows = iterator_to_array($this->query()->cache($ttl)->cursor());

        $this->assertCount(3, $rows);
    }

    #[Test]
    public function aCursorWithoutCacheStillStreams(): void
    {
        QueryCache::setDriver(new ArrayCache());

        $rows = iterator_to_array($this->query()->cursor());

        $this->assertCount(3, $rows);
        $this->assertSame('a', $rows[0]['name']);
    }

    #[Test]
    public function theEagerLoadRefusalStillTakesPrecedence(): void
    {
        // Both are refused; the one named first should be the one reported, so
        // a caller fixing them does not have to discover them one at a time.
        $query = $this->query()->cache(60);
        $query->with('whatever');

        try {
            iterator_to_array($query->cursor());
            self::fail('expected a QueryException');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('eager load', $exception->getMessage());
        }
    }

    #[Test]
    public function theBufferedReadsAreStillCacheable(): void
    {
        // The refusal is specific to streaming: everything that materialises
        // its rows anyway keeps caching them.
        QueryCache::setDriver(new ArrayCache());

        $this->assertCount(3, iterator_to_array($this->query()->cache(60)->getArray()));
        $this->assertCount(3, iterator_to_array($this->query()->cache(60)->all()));
        $this->assertSame(3, $this->query()->cache(60)->cursorPaginate(3)->count());
    }

    #[Test]
    public function aCachedReadIsServedFromTheCacheRatherThanTheDatabase(): void
    {
        // Establishes that cache(60) really is caching on the buffered path,
        // which is what makes the cursor's refusal meaningful rather than
        // a check on a flag nothing acts upon.
        QueryCache::setDriver(new ArrayCache());

        $first = iterator_to_array($this->query()->cache(60)->getArray());
        $this->assertSame('a', $first[0]['name']);

        Connection::get('cc_sqlite')->execute(new Raw("UPDATE cc SET name = 'changed' WHERE id = 1"));

        $second = iterator_to_array($this->query()->cache(60)->getArray());
        $this->assertSame('a', $second[0]['name'], 'the cached rows should still be served');

        // And the cursor, which refuses to cache, reads the new value.
        $streamed = iterator_to_array($this->query()->cursor());
        $this->assertSame('changed', $streamed[0]['name']);
    }
}
