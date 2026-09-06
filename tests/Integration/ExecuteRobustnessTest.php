<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Cache\CacheInterface;
use Simsoft\DB\Cache\QueryCache;

/**
 * An in-memory cache that counts the writes it is asked to make.
 */
class RecordingCache implements CacheInterface
{
    /** @var array<string, mixed> The stored values. */
    public array $store = [];

    /** @var int Number of set() calls. */
    public int $writes = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->writes++;
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
}

/**
 * Running one builder's query through another builder.
 *
 * query() and execute() take an optional Executable, so the builder holding the
 * connection need not be the one holding the statement. That second path was
 * barely exercised, and three things went wrong on it.
 */
class ExecuteRobustnessTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        QueryCache::reset();
    }

    /**
     * A cache that records what it was asked to do.
     *
     * @return RecordingCache
     */
    private function recordingCache(): RecordingCache
    {
        return new RecordingCache();
    }

    #[Test]
    public function aCachedQueryRunThroughAnotherBuilderIsServedFromCache(): void
    {
        // The TTL was read as $target->cacheTtl. property_exists() reports a
        // protected property as present, but reading one from outside the
        // declaring class is a fatal Error rather than a catchable exception —
        // so this shape killed the process outright.
        $cache = $this->recordingCache();
        QueryCache::setDriver($cache);

        $target = (new ActiveQuery())->from('user u')->where('role', 'admin')->cache(60);
        $runner = (new Raw('SELECT 1'))->withConnection('mysql');

        $first = $runner->query($target);
        $second = $runner->query($target);

        $this->assertCount(2, $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $cache->writes, 'The second run should have been a cache hit.');
    }

    #[Test]
    public function aQueryWithNoTtlIsNotCached(): void
    {
        $cache = $this->recordingCache();
        QueryCache::setDriver($cache);

        $target = (new ActiveQuery())->from('user u')->where('role', 'admin');
        $runner = (new Raw('SELECT 1'))->withConnection('mysql');

        $this->assertCount(2, $runner->query($target));
        $this->assertSame(0, $cache->writes);
    }

    #[Test]
    public function aCacheThatRefusesTheWriteDoesNotFailTheQuery(): void
    {
        // The write sat inside the try that wraps the query, so a full Redis or
        // an unreachable memcached surfaced as a QueryException naming the
        // SELECT — sending the caller after a statement that had already run
        // and returned its rows, and discarding those rows.
        QueryCache::setDriver(new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function set(string $key, mixed $value, int $ttl = 0): bool
            {
                throw new RuntimeException('cache backend down');
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        });

        $rows = (new ActiveQuery())
            ->from('user u')
            ->where('role', 'admin')
            ->withConnection('mysql')
            ->cache(60)
            ->query();

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function aCacheThatCannotAnswerIsTreatedAsAMiss(): void
    {
        // The rows it failed to hand over are always obtainable from the
        // database, so a read failure has no reason to fail the query either.
        QueryCache::setDriver(new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new RuntimeException('cache backend down');
            }

            public function set(string $key, mixed $value, int $ttl = 0): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        });

        $rows = (new ActiveQuery())
            ->from('user u')
            ->where('role', 'admin')
            ->withConnection('mysql')
            ->cache(60)
            ->query();

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function executeWritesItsResultBackOntoTheQueryItWasGiven(): void
    {
        // execute() cloned its target before running, so anything the driver
        // wrote back onto it landed on the copy and was discarded with it. On
        // MySQL there is no RETURNING to lose, but the clone also meant the
        // caller's own builder never saw the run at all.
        $username = 'exec_' . bin2hex(random_bytes(5));

        $insert = (new \Simsoft\DB\Builder\Insert('user', [
            'username' => $username,
            'email' => $username . '@example.test',
            'password' => 'x',
            'role' => 'member',
            'score' => 1,
            'status_code' => 1,
        ]))->withConnection('mysql');

        $runner = (new Raw('SELECT 1'))->withConnection('mysql');

        $this->assertTrue($runner->execute($insert));

        $found = (new ActiveQuery())
            ->from('user')
            ->where('username', $username)
            ->withConnection('mysql')
            ->first();

        $this->assertNotEmpty($found);
    }
}
