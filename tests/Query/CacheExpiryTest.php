<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Cache\ArrayCache;
use Simsoft\DB\Cache\CacheInterface;
use Simsoft\DB\Cache\QueryCache;
use Simsoft\DB\Connection;

/**
 * The part of PSR-16 the adapter test needs.
 *
 * Stands in for `Psr\SimpleCache\CacheInterface`, which is not a dependency of
 * this package. The signatures that matter are the ones that differ from
 * `Simsoft\DB\Cache\CacheInterface` — `$ttl` is nullable here, and "no expiry"
 * is `null` rather than `0`, which is the whole reason an adapter is needed.
 */
interface Psr16LikeCache
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, null|int $ttl = null): bool;

    public function delete(string $key): bool;

    public function has(string $key): bool;
}

/**
 * What happens to a cache entry once its TTL runs out.
 *
 * The existing cache tests all read entries back well inside their lifetime,
 * so the expiry branches of get() and has() were never reached. Expiry is not
 * just "the value stops being returned" — the entry has to leave the store,
 * or a long-lived process caching many short-TTL queries grows without bound.
 */
class CacheExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        QueryCache::reset();
        Connection::reset();
    }

    protected function tearDown(): void
    {
        QueryCache::reset();
        Connection::reset();
    }

    /**
     * Read the private store, which is where eviction is observable.
     *
     * @param ArrayCache $cache The cache to inspect.
     * @return array<string, mixed> The raw entries.
     */
    private function store(ArrayCache $cache): array
    {
        $value = new ReflectionProperty(ArrayCache::class, 'store')->getValue($cache);
        $this->assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Backdate an entry's expiry so the clock does not have to be waited out.
     *
     * @param ArrayCache $cache The cache holding the entry.
     * @param string $key The entry to age.
     * @param int $expires The absolute expiry timestamp to write.
     * @return void
     */
    private function age(ArrayCache $cache, string $key, int $expires): void
    {
        $property = new ReflectionProperty(ArrayCache::class, 'store');
        $store = $this->store($cache);
        $this->assertArrayHasKey($key, $store);
        $this->assertIsArray($store[$key]);
        $store[$key]['expires'] = $expires;
        $property->setValue($cache, $store);
    }

    #[Test]
    public function anExpiredEntryReadsBackAsTheDefault(): void
    {
        $cache = new ArrayCache();
        $cache->set('stale', 'cached value', 60);
        $this->age($cache, 'stale', time() - 1);

        $this->assertNull($cache->get('stale'));
    }

    #[Test]
    public function anExpiredEntryReturnsTheCallersOwnDefault(): void
    {
        $cache = new ArrayCache();
        $cache->set('stale', 'cached value', 60);
        $this->age($cache, 'stale', time() - 1);

        $this->assertSame('MISS', $cache->get('stale', 'MISS'));
    }

    #[Test]
    public function readingAnExpiredEntryEvictsIt(): void
    {
        // Not merely hidden: a process caching thousands of short-lived
        // queries would otherwise hold every one of them forever.
        $cache = new ArrayCache();
        $cache->set('stale', 'cached value', 60);
        $this->age($cache, 'stale', time() - 1);

        $this->assertArrayHasKey('stale', $this->store($cache));
        $cache->get('stale');
        $this->assertArrayNotHasKey('stale', $this->store($cache));
    }

    #[Test]
    public function checkingAnExpiredEntryReportsItMissing(): void
    {
        $cache = new ArrayCache();
        $cache->set('stale', 'cached value', 60);
        $this->age($cache, 'stale', time() - 1);

        $this->assertFalse($cache->has('stale'));
    }

    #[Test]
    public function checkingAnExpiredEntryAlsoEvictsIt(): void
    {
        // has() is a read too, and it prunes on the same terms as get().
        $cache = new ArrayCache();
        $cache->set('stale', 'cached value', 60);
        $this->age($cache, 'stale', time() - 1);

        $this->assertArrayHasKey('stale', $this->store($cache));
        $cache->has('stale');
        $this->assertArrayNotHasKey('stale', $this->store($cache));
    }

    #[Test]
    public function anEntryExpiringThisVerySecondIsStillServed(): void
    {
        // The test is expires < time(), so the second an entry is stamped for
        // is inclusive. A TTL therefore never rounds down to nothing.
        $cache = new ArrayCache();
        $cache->set('edge', 'value', 60);
        $this->age($cache, 'edge', time());

        $this->assertSame('value', $cache->get('edge'));
        $this->assertTrue($cache->has('edge'));
        $this->assertArrayHasKey('edge', $this->store($cache));
    }

    #[Test]
    public function expiryOnlyTouchesTheEntryBeingRead(): void
    {
        $cache = new ArrayCache();
        $cache->set('stale', 'old', 60);
        $cache->set('fresh', 'new', 60);
        $this->age($cache, 'stale', time() - 1);

        $this->assertNull($cache->get('stale'));
        $this->assertSame('new', $cache->get('fresh'));
        $this->assertArrayHasKey('fresh', $this->store($cache));
    }

    #[Test]
    public function anExpiredKeyCanBeSetAgain(): void
    {
        $cache = new ArrayCache();
        $cache->set('key', 'first', 60);
        $this->age($cache, 'key', time() - 1);
        $cache->get('key');

        $cache->set('key', 'second', 60);
        $this->assertSame('second', $cache->get('key'));
    }

    #[Test]
    public function anEntryWithoutATtlSurvivesAnyAmountOfTime(): void
    {
        // ttl <= 0 stores expires = 0, and the guard requires expires > 0,
        // so the expiry branch is never entered for these at all.
        $cache = new ArrayCache();

        foreach ([0, -1, -3600] as $ttl) {
            $key = 'ttl_' . $ttl;
            $cache->set($key, 'value', $ttl);

            $entry = $this->store($cache)[$key];
            $this->assertIsArray($entry);
            $this->assertSame(0, $entry['expires']);
            $this->assertSame('value', $cache->get($key));
            $this->assertTrue($cache->has($key));
        }
    }

    #[Test]
    public function expiryIsDrivenByTheRealClock(): void
    {
        // Every other test here backdates the stamp. This one actually waits,
        // to show the stamp is compared against wall-clock time and not
        // against something the tests are quietly in control of.
        $cache = new ArrayCache();
        $cache->set('ticking', 'value', 1);
        $this->assertSame('value', $cache->get('ticking'));

        sleep(2);

        $this->assertNull($cache->get('ticking'));
        $this->assertFalse($cache->has('ticking'));
    }

    #[Test]
    public function anExpiredQueryResultIsFetchedFromTheDatabaseAgain(): void
    {
        // The branch as the framework reaches it: once the entry lapses the
        // next read must hit the connection, and must see rows written since.
        Connection::add('expiry_db', ['driver' => 'sqlite', 'database' => ':memory:']);
        $driver = Connection::get('expiry_db');
        $driver->execute(new Raw('CREATE TABLE expiry_probe (id INTEGER PRIMARY KEY, name TEXT)'));
        $driver->execute(new Raw("INSERT INTO expiry_probe (id, name) VALUES (1, 'before')"));

        $cache = new ArrayCache();
        QueryCache::setDriver($cache);

        $read = static function (): string {
            $query = new ActiveQuery()
                ->from('expiry_probe')
                ->select('name')
                ->withConnection('expiry_db')
                ->cache(60);

            $rows = iterator_to_array($query->getArray());

            return (string)$rows[0]['name'];
        };

        $this->assertSame('before', $read());

        $driver->execute(new Raw("UPDATE expiry_probe SET name = 'after' WHERE id = 1"));

        // Still inside the TTL, so the pre-update row is served. Nothing in
        // the framework invalidates entries on write.
        $this->assertSame('before', $read());

        foreach (array_keys($this->store($cache)) as $key) {
            $this->age($cache, $key, time() - 1);
        }

        $this->assertSame('after', $read());
    }

    #[Test]
    public function aPsr16CacheReachesTheQueryCacheThroughAShortAdapter(): void
    {
        // The interface docblock says a PSR-16 cache needs an adapter, and
        // that writing one is a few lines. This is that adapter, and it works.
        $psr16 = new class implements Psr16LikeCache {
            /** @var array<string, mixed> Values by key. */
            private array $values = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value, null|int $ttl = null): bool
            {
                $this->values[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->values[$key]);
                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }
        };

        $adapter = new class ($psr16) implements CacheInterface {
            public function __construct(private Psr16LikeCache $psr16)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->psr16->get($key, $default);
            }

            public function set(string $key, mixed $value, int $ttl = 0): bool
            {
                // The translation the adapter exists for: 0 means "no expiry"
                // here, null means it there.
                return $this->psr16->set($key, $value, $ttl > 0 ? $ttl : null);
            }

            public function delete(string $key): bool
            {
                return $this->psr16->delete($key);
            }

            public function has(string $key): bool
            {
                return $this->psr16->has($key);
            }
        };

        Connection::add('psr_db', ['driver' => 'sqlite', 'database' => ':memory:']);
        $driver = Connection::get('psr_db');
        $driver->execute(new Raw('CREATE TABLE psr_probe (id INTEGER PRIMARY KEY, name TEXT)'));
        $driver->execute(new Raw("INSERT INTO psr_probe (id, name) VALUES (1, 'stored')"));

        QueryCache::setDriver($adapter);

        $read = static function (): string {
            $query = new ActiveQuery()
                ->from('psr_probe')
                ->select('name')
                ->withConnection('psr_db')
                ->cache(60);

            $rows = iterator_to_array($query->getArray());

            return (string)$rows[0]['name'];
        };

        $this->assertSame('stored', $read());

        // Served from the adapter this time: the row underneath has changed.
        $driver->execute(new Raw("UPDATE psr_probe SET name = 'changed' WHERE id = 1"));
        $this->assertSame('stored', $read());
    }
}
