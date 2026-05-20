<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Cache\ArrayCache;
use Simsoft\DB\Cache\QueryCache;

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        QueryCache::reset();
    }

    protected function tearDown(): void
    {
        QueryCache::reset();
    }

    #[Test]
    public function cacheMethodSetsTtl(): void
    {
        $query = (new ActiveQuery())->from('user')->cache(120);
        $this->assertSame(120, $query->getCacheTtl());
    }

    #[Test]
    public function cacheMethodDefaultsTtlToSixty(): void
    {
        $query = (new ActiveQuery())->from('user')->cache();
        $this->assertSame(60, $query->getCacheTtl());
    }

    #[Test]
    public function cacheMethodReturnsSelf(): void
    {
        $query = (new ActiveQuery())->from('user');
        $result = $query->cache(30);
        $this->assertSame($query, $result);
    }

    #[Test]
    public function queryCacheSetDriverEnablesCaching(): void
    {
        $this->assertFalse(QueryCache::isEnabled());

        $cache = new ArrayCache();
        QueryCache::setDriver($cache);

        $this->assertTrue(QueryCache::isEnabled());
        $this->assertSame($cache, QueryCache::getDriver());
    }

    #[Test]
    public function queryCacheResetDisablesCaching(): void
    {
        QueryCache::setDriver(new ArrayCache());
        $this->assertTrue(QueryCache::isEnabled());

        QueryCache::reset();
        $this->assertFalse(QueryCache::isEnabled());
        $this->assertNull(QueryCache::getDriver());
    }

    #[Test]
    public function queryCacheGenerateKeyIsConsistent(): void
    {
        $key1 = QueryCache::generateKey('SELECT * FROM user WHERE id = ?', [1]);
        $key2 = QueryCache::generateKey('SELECT * FROM user WHERE id = ?', [1]);
        $key3 = QueryCache::generateKey('SELECT * FROM user WHERE id = ?', [2]);

        $this->assertSame($key1, $key2);
        $this->assertNotSame($key1, $key3);
    }

    #[Test]
    public function queryCacheGenerateKeyStartsWithPrefix(): void
    {
        $key = QueryCache::generateKey('SELECT 1', null);
        $this->assertStringStartsWith('query:', $key);
    }

    #[Test]
    public function arrayCacheGetSetWorks(): void
    {
        $cache = new ArrayCache();

        $this->assertNull($cache->get('missing'));
        $this->assertSame('default', $cache->get('missing', 'default'));

        $cache->set('key1', ['data' => 'value'], 60);
        $this->assertSame(['data' => 'value'], $cache->get('key1'));
    }

    #[Test]
    public function arrayCacheHasWorks(): void
    {
        $cache = new ArrayCache();

        $this->assertFalse($cache->has('missing'));

        $cache->set('exists', 'value', 60);
        $this->assertTrue($cache->has('exists'));
    }

    #[Test]
    public function arrayCacheDeleteWorks(): void
    {
        $cache = new ArrayCache();

        $cache->set('key', 'value', 60);
        $this->assertTrue($cache->has('key'));

        $cache->delete('key');
        $this->assertFalse($cache->has('key'));
    }

    #[Test]
    public function arrayCacheClearWorks(): void
    {
        $cache = new ArrayCache();

        $cache->set('key1', 'value1', 60);
        $cache->set('key2', 'value2', 60);

        $cache->clear();

        $this->assertFalse($cache->has('key1'));
        $this->assertFalse($cache->has('key2'));
    }

    #[Test]
    public function arrayCacheNoExpirationWhenTtlIsZero(): void
    {
        $cache = new ArrayCache();

        $cache->set('forever', 'value', 0);
        $this->assertSame('value', $cache->get('forever'));
        $this->assertTrue($cache->has('forever'));
    }
}
