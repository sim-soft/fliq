<?php

namespace Integration;

use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\PostgresDriver;
use Simsoft\DB\Exceptions\ConnectionException;

/**
 * Integration tests for PostgreSQL driver features.
 *
 * PostgresDriverTest covers query building through the driver. This class
 * covers the driver's own surface — advisory locks, LISTEN/NOTIFY, the
 * statement cache and connection recovery — which had no tests at all.
 *
 * Several tests need two independent sessions: an advisory lock is only
 * observable from another connection, and a notification is only interesting
 * when someone else sends it. A third connection issues pg_terminate_backend()
 * so the victim does not notice its own death until it next talks to the
 * server, which is how a connection really drops.
 *
 * Requires ext-pdo_pgsql and a running PostgreSQL server.
 */
class PostgresDriverFeaturesTest extends TestCase
{
    /** @var bool Whether a PostgreSQL server answered at setup. */
    private static bool $available = false;

    /** @var array<string, mixed> Connection config shared by every session. */
    private static array $config = [];

    /** @var int Base for lock keys, kept away from anything else in the suite. */
    private const LOCK_KEY = 918_000;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            return;
        }

        self::$config = [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'database' => getenv('PG_DATABASE') ?: 'sample_db',
            'username' => getenv('PG_USERNAME') ?: 'postgres',
            'password' => getenv('PG_PASSWORD') ?: '',
            'charset' => 'utf8',
            'schema' => 'public',
        ];

        Connection::add('pgf1', self::$config);

        try {
            Connection::get('pgf1');
            self::$available = true;
        } catch (\Throwable) {
            self::$available = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    protected function tearDown(): void
    {
        // Every test names its own connections; drop them all so no session
        // carries a lock or a subscription into the next test.
        foreach (['pgf1', 'pgf2', 'pgf3'] as $name) {
            try {
                Connection::remove($name);
            } catch (\Throwable) {
                // Already gone.
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['pgf1', 'pgf2', 'pgf3'] as $name) {
            try {
                Connection::remove($name);
            } catch (\Throwable) {
                // Already gone.
            }
        }
    }

    /**
     * Open a named PostgreSQL session.
     *
     * @param string $name The connection name.
     * @param array<string, mixed> $overrides Config overrides.
     * @return PostgresDriver
     */
    private function session(string $name, array $overrides = []): PostgresDriver
    {
        try {
            Connection::remove($name);
        } catch (\Throwable) {
            // Not registered yet.
        }

        Connection::add($name, $overrides + self::$config);

        /** @var PostgresDriver $driver */
        $driver = Connection::get($name);

        return $driver;
    }

    /**
     * A lock key unique to the calling test.
     *
     * @param int $offset Distinguishes keys within one test.
     * @return int
     */
    private function lockKey(int $offset = 0): int
    {
        return self::LOCK_KEY + (abs(crc32($this->name())) % 1000) * 10 + $offset;
    }

    /**
     * Collect notifications, waiting for ones still in flight.
     *
     * NOTIFY is asynchronous: the publisher's statement returns before the
     * subscriber's socket has the message, so an immediate poll reports an
     * empty queue and the test fails at random. Wait for the expected count,
     * then keep draining until the queue is genuinely empty so an unexpected
     * extra notification is still reported rather than silently left behind.
     *
     * @param PostgresDriver $driver The subscriber.
     * @param int $expected How many notifications to wait for.
     * @return array<int, string> "channel/payload" for each notification.
     */
    private function drain(PostgresDriver $driver, int $expected = 0): array
    {
        $seen = [];
        $deadline = microtime(true) + 5.0;

        while (count($seen) < $expected && microtime(true) < $deadline) {
            $notification = $driver->getNotification(100);

            if ($notification !== null) {
                $seen[] = $notification['channel'] . '/' . $notification['payload'];
            }
        }

        while (($notification = $driver->getNotification()) !== null) {
            $seen[] = $notification['channel'] . '/' . $notification['payload'];
        }

        return $seen;
    }

    /**
     * Prove a notification is not merely late.
     *
     * A negative assertion cannot just poll and find nothing — the message may
     * still be in flight. Publishing a sentinel afterwards from the same
     * session pins the ordering: PostgreSQL delivers one session's
     * notifications in order, so once the sentinel arrives anything sent
     * before it has either arrived or was never addressed to this subscriber.
     *
     * @param PostgresDriver $subscriber The listening session.
     * @param PostgresDriver $publisher The session that sent the earlier messages.
     * @param string $channel A channel the subscriber is listening on.
     * @return array<int, string> Everything received, minus the sentinel.
     */
    private function drainAfterSentinel(
        PostgresDriver $subscriber,
        PostgresDriver $publisher,
        string $channel
    ): array {
        $sentinel = $channel . '/__sentinel__';
        $publisher->notify($channel, '__sentinel__');

        $seen = $this->drain($subscriber, 1);

        $this->assertContains($sentinel, $seen, 'The sentinel never arrived.');

        return array_values(array_filter($seen, fn(string $item): bool => $item !== $sentinel));
    }

    /**
     * Kill a session from a different connection.
     *
     * Terminating from elsewhere means the victim only discovers the loss when
     * it next sends a statement, which is how an idle drop actually behaves.
     *
     * @param PostgresDriver $killer The connection issuing the terminate.
     * @param PostgresDriver $victim The connection to terminate.
     * @return void
     */
    private function kill(PostgresDriver $killer, PostgresDriver $victim): void
    {
        $pid = $this->backendPid($victim);
        $killer->query(new Raw('SELECT pg_terminate_backend(?)', [$pid]));
    }

    /**
     * Read a session's server-side backend process id.
     *
     * @param PostgresDriver $driver The session to identify.
     * @return int The backend pid.
     */
    private function backendPid(PostgresDriver $driver): int
    {
        return (int)$driver->query(new Raw('SELECT pg_backend_pid() AS pid'))[0]['pid'];
    }

    // ---------------------------------------------------------------
    // Advisory locks
    // ---------------------------------------------------------------

    #[Test]
    public function advisoryLockTryAcquiresAndReleases(): void
    {
        $driver = $this->session('pgf1');
        $key = $this->lockKey();

        $this->assertTrue($driver->advisoryLockTry($key));
        $this->assertTrue($driver->advisoryUnlock($key));
    }

    #[Test]
    public function advisoryLockBlocksAnotherSession(): void
    {
        $holder = $this->session('pgf1');
        $other = $this->session('pgf2');
        $key = $this->lockKey();

        $this->assertTrue($holder->advisoryLockTry($key));

        // The point of an advisory lock: a second session must be refused.
        $this->assertFalse($other->advisoryLockTry($key));

        $this->assertTrue($holder->advisoryUnlock($key));
        $this->assertTrue($other->advisoryLockTry($key));
        $this->assertTrue($other->advisoryUnlock($key));
    }

    #[Test]
    public function advisoryLocksWithDifferentKeysDoNotConflict(): void
    {
        $first = $this->session('pgf1');
        $second = $this->session('pgf2');

        $this->assertTrue($first->advisoryLockTry($this->lockKey(1)));
        $this->assertTrue($second->advisoryLockTry($this->lockKey(2)));

        $this->assertTrue($first->advisoryUnlock($this->lockKey(1)));
        $this->assertTrue($second->advisoryUnlock($this->lockKey(2)));
    }

    #[Test]
    public function advisoryLockIsReentrantWithinOneSession(): void
    {
        $driver = $this->session('pgf1');
        $key = $this->lockKey();

        // PostgreSQL counts session locks, so the same session takes it twice
        // and must release it twice.
        $this->assertTrue($driver->advisoryLockTry($key));
        $this->assertTrue($driver->advisoryLockTry($key));

        $this->assertTrue($driver->advisoryUnlock($key));
        $this->assertTrue($driver->advisoryUnlock($key));
        $this->assertFalse($driver->advisoryUnlock($key));
    }

    #[Test]
    public function advisoryUnlockReturnsFalseWhenNotHeld(): void
    {
        $driver = $this->session('pgf1');

        $this->assertFalse($driver->advisoryUnlock($this->lockKey()));
    }

    #[Test]
    public function advisoryLockBlockingAcquiresAnUncontendedLock(): void
    {
        $driver = $this->session('pgf1');
        $key = $this->lockKey();

        // Only ever call the blocking form on a free key: contending here would
        // wait forever and hang the suite.
        $this->assertTrue($driver->advisoryLock($key));
        $this->assertTrue($driver->advisoryUnlock($key));
    }

    #[Test]
    public function advisoryLockIsReleasedWhenTheSessionEnds(): void
    {
        $holder = $this->session('pgf1');
        $other = $this->session('pgf2');
        $key = $this->lockKey();

        $this->assertTrue($holder->advisoryLockTry($key));
        $this->assertFalse($other->advisoryLockTry($key));

        // The session ends when the last reference to its PDO goes, so the
        // registry entry and the local both have to be dropped.
        Connection::remove('pgf1');
        unset($holder);

        // The server tears the backend down asynchronously.
        for ($i = 0; $i < 50 && !$other->advisoryLockTry($key); $i++) {
            usleep(20_000);
        }

        $this->assertTrue($other->advisoryUnlock($key));
    }

    #[Test]
    public function transactionAdvisoryLockIsReleasedOnCommit(): void
    {
        $holder = $this->session('pgf1');
        $other = $this->session('pgf2');
        $key = $this->lockKey();

        $holder->getPdo()?->beginTransaction();
        $this->assertTrue($holder->advisoryLockTransactionTry($key));
        $this->assertFalse($other->advisoryLockTransactionTry($key));

        $holder->getPdo()?->commit();

        // The lock is tied to the transaction, so committing frees it.
        $this->assertTrue($other->advisoryLockTransactionTry($key));
    }

    #[Test]
    public function transactionAdvisoryLockIsReleasedOnRollback(): void
    {
        $holder = $this->session('pgf1');
        $other = $this->session('pgf2');
        $key = $this->lockKey();

        $holder->getPdo()?->beginTransaction();
        $this->assertTrue($holder->advisoryLockTransaction($key));
        $this->assertFalse($other->advisoryLockTransactionTry($key));

        $holder->getPdo()?->rollBack();

        $this->assertTrue($other->advisoryLockTransactionTry($key));
    }

    // ---------------------------------------------------------------
    // LISTEN / NOTIFY
    // ---------------------------------------------------------------

    #[Test]
    public function notificationIsDeliveredBetweenSessions(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $this->assertTrue($subscriber->listen('pgf_basic'));
        $this->assertNull($subscriber->getNotification());

        $this->assertTrue($publisher->notify('pgf_basic', 'hello'));

        $this->assertSame(['pgf_basic/hello'], $this->drain($subscriber, 1));
    }

    #[Test]
    public function notificationCarriesChannelPayloadAndPid(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');
        $senderPid = $this->backendPid($publisher);

        $subscriber->listen('pgf_shape');
        $publisher->notify('pgf_shape', 'hello');

        // Wait for arrival before inspecting the notification itself.
        $this->assertSame(['pgf_shape/hello'], $this->drain($subscriber, 1));

        $publisher->notify('pgf_shape', 'again');
        $deadline = microtime(true) + 5.0;
        $notification = null;

        while ($notification === null && microtime(true) < $deadline) {
            $notification = $subscriber->getNotification(100);
        }

        $this->assertIsArray($notification);
        $this->assertSame('pgf_shape', $notification['channel']);
        $this->assertSame('again', $notification['payload']);
        $this->assertSame($senderPid, $notification['pid']);
    }

    #[Test]
    public function getNotificationReturnsNullWhenNothingIsPending(): void
    {
        $subscriber = $this->session('pgf1');
        $subscriber->listen('pgf_quiet');

        $this->assertNull($subscriber->getNotification());
    }

    #[Test]
    public function unlistenStopsDelivery(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $subscriber->listen('pgf_stop');
        $subscriber->listen('pgf_stop_sentinel');
        $this->assertTrue($subscriber->unlisten('pgf_stop'));

        $publisher->notify('pgf_stop', 'ignored');

        $this->assertSame(
            [],
            $this->drainAfterSentinel($subscriber, $publisher, 'pgf_stop_sentinel')
        );
    }

    #[Test]
    public function notificationWithAnEmptyPayloadIsDelivered(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $subscriber->listen('pgf_empty');
        $this->assertTrue($publisher->notify('pgf_empty'));

        $this->assertSame(['pgf_empty/'], $this->drain($subscriber, 1));
    }

    #[Test]
    public function channelNameIsMatchedExactly(): void
    {
        // Channel names used to be stripped of everything outside [a-zA-Z0-9_],
        // so 'user-1' and 'user1' became the same channel and a subscriber
        // received another tenant's messages.
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $subscriber->listen('pgf-user-1');

        $publisher->notify('pgfuser1', 'wrong recipient');
        $this->assertSame(
            [],
            $this->drainAfterSentinel($subscriber, $publisher, 'pgf-user-1')
        );

        $publisher->notify('pgf-user-1', 'right recipient');
        $this->assertSame(['pgf-user-1/right recipient'], $this->drain($subscriber, 1));
    }

    #[Test]
    public function subscriberReceivesNotificationsSentByTheDatabase(): void
    {
        // The usual pattern is a trigger calling pg_notify() with the real
        // channel name. Rewriting the name on the LISTEN side made those
        // notifications vanish with no error anywhere.
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $subscriber->listen('pgf-order-created');

        // Deliberately not through notify() — this is the database publishing
        // under the name a trigger would use.
        $publisher->query(new Raw('SELECT pg_notify(?, ?)', ['pgf-order-created', '{"id":42}']));

        $this->assertSame(['pgf-order-created/{"id":42}'], $this->drain($subscriber, 1));
    }

    #[Test]
    public function channelNameIsCaseSensitiveWithOrWithoutAPayload(): void
    {
        // notify() used to take a different code path for an empty payload,
        // where the unquoted identifier was folded to lowercase — so the two
        // calls below published to two different channels.
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $subscriber->listen('PgfMixedCase');

        $publisher->notify('PgfMixedCase', 'with payload');
        $this->assertSame(['PgfMixedCase/with payload'], $this->drain($subscriber, 1));

        $publisher->notify('PgfMixedCase');
        $this->assertSame(['PgfMixedCase/'], $this->drain($subscriber, 1));

        $publisher->notify('pgfmixedcase', 'lowercase is a different channel');
        $this->assertSame(
            [],
            $this->drainAfterSentinel($subscriber, $publisher, 'PgfMixedCase')
        );
    }

    #[Test]
    public function channelNamesNeedingQuotingRoundTrip(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        foreach (['pgf-dashed', 'pgf spaced', 'pgf.dotted', 'PgfCased'] as $channel) {
            $this->assertTrue($subscriber->listen($channel));
            $this->assertTrue($publisher->notify($channel, 'p'));
            $this->assertSame([$channel . '/p'], $this->drain($subscriber, 1));
            $this->assertTrue($subscriber->unlisten($channel));
        }
    }

    #[Test]
    public function aQuoteInTheChannelNameCannotInjectSql(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $subscriber->execute(new Raw('DROP TABLE IF EXISTS pgf_injection_probe'));
        $subscriber->execute(new Raw('CREATE TABLE pgf_injection_probe (id int)'));

        // Closing the quoted identifier would let the rest run as SQL.
        $channel = 'pgf"; DROP TABLE pgf_injection_probe; --';

        $this->assertTrue($subscriber->listen($channel));
        $this->assertTrue($publisher->notify($channel, 'q'));
        $this->assertSame([$channel . '/q'], $this->drain($subscriber, 1));
        $this->assertTrue($subscriber->unlisten($channel));

        $survived = $subscriber->query(new Raw(
            'SELECT count(*) AS c FROM information_schema.tables WHERE table_name = ?',
            ['pgf_injection_probe']
        ));

        $this->assertSame(1, (int)$survived[0]['c']);

        $subscriber->execute(new Raw('DROP TABLE IF EXISTS pgf_injection_probe'));
    }

    #[Test]
    public function payloadsSurviveTheRoundTripIntact(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        $subscriber->listen('pgf_payload');

        foreach (["it's", 'has "quotes"', "line\nbreak", '{"id":42,"t":9.5}'] as $payload) {
            $publisher->notify('pgf_payload', $payload);

            $this->assertSame(
                ['pgf_payload/' . $payload],
                $this->drain($subscriber, 1)
            );
        }
    }

    #[Test]
    public function anUnusableChannelNameIsRejectedByEveryMethod(): void
    {
        $driver = $this->session('pgf1');

        // An over-long name must not reach the server: LISTEN silently
        // truncates to 63 bytes while pg_notify() refuses the same string, so
        // the pair could never round trip and names differing only past byte
        // 63 would collapse onto one another.
        foreach (['', str_repeat('c', 64), str_repeat('c', 70)] as $channel) {
            foreach (['listen', 'unlisten', 'notify'] as $method) {
                try {
                    $driver->$method($channel);
                    $this->fail(sprintf(
                        '%s() accepted a %d-byte channel name.',
                        $method,
                        strlen($channel)
                    ));
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        // Rejection happens before anything is sent, so the session is intact.
        $this->assertTrue($driver->ping());
    }

    #[Test]
    public function theLongestUsableChannelNameRoundTrips(): void
    {
        $subscriber = $this->session('pgf1');
        $publisher = $this->session('pgf2');

        // 63 bytes is the server's limit, so it must be accepted, not rejected
        // by an off-by-one in the guard.
        $channel = str_repeat('c', 63);

        $this->assertTrue($subscriber->listen($channel));
        $this->assertTrue($publisher->notify($channel, 'p'));
        $this->assertSame([$channel . '/p'], $this->drain($subscriber, 1));
    }

    // ---------------------------------------------------------------
    // Statement cache
    // ---------------------------------------------------------------

    #[Test]
    public function statementCacheIsEnabledByDefault(): void
    {
        $driver = $this->session('pgf1');

        $this->assertTrue($driver->isStatementCacheEnabled());
    }

    #[Test]
    public function statementCacheCanBeDisabledAndReEnabled(): void
    {
        $driver = $this->session('pgf1');

        $driver->disableStatementCache();
        $this->assertFalse($driver->isStatementCacheEnabled());

        // Queries must still work on the uncached path.
        $this->assertSame(1, (int)$driver->query(new Raw('SELECT 1 AS n'))[0]['n']);

        $driver->enableStatementCache();
        $this->assertTrue($driver->isStatementCacheEnabled());
        $this->assertSame(1, (int)$driver->query(new Raw('SELECT 1 AS n'))[0]['n']);
    }

    #[Test]
    public function statementCacheHonoursItsSizeLimit(): void
    {
        $driver = $this->session('pgf1');
        $driver->setStatementCacheSize(2);

        foreach (['SELECT 1 AS n', 'SELECT 2 AS n', 'SELECT 3 AS n'] as $sql) {
            $driver->query(new Raw($sql));
        }

        $this->assertSame(2, $this->cacheSize($driver));

        $driver->setStatementCacheSize(100);
    }

    #[Test]
    public function clearStatementCacheEmptiesIt(): void
    {
        $driver = $this->session('pgf1');

        $driver->query(new Raw('SELECT 1 AS n'));
        $this->assertGreaterThan(0, $this->cacheSize($driver));

        $driver->clearStatementCache();
        $this->assertSame(0, $this->cacheSize($driver));

        $this->assertSame(1, (int)$driver->query(new Raw('SELECT 1 AS n'))[0]['n']);
    }

    #[Test]
    public function repeatingAQueryReusesOneCachedStatement(): void
    {
        $driver = $this->session('pgf1');
        $driver->clearStatementCache();

        for ($i = 0; $i < 5; $i++) {
            $driver->query(new Raw('SELECT 1 AS n'));
        }

        $this->assertSame(1, $this->cacheSize($driver));
    }

    /**
     * Read the driver's private statement cache.
     *
     * @param PostgresDriver $driver The driver to inspect.
     * @return int Number of cached statements.
     */
    private function cacheSize(PostgresDriver $driver): int
    {
        $property = new \ReflectionProperty(PostgresDriver::class, 'statementCache');
        $property->setAccessible(true);

        /** @var array<string, mixed> $cache */
        $cache = $property->getValue($driver);

        return count($cache);
    }

    // ---------------------------------------------------------------
    // Connection lifecycle
    // ---------------------------------------------------------------

    #[Test]
    public function getPdoExposesTheUnderlyingConnection(): void
    {
        $driver = $this->session('pgf1');

        $this->assertInstanceOf(PDO::class, $driver->getPdo());
    }

    #[Test]
    public function pingReportsALostConnection(): void
    {
        $driver = $this->session('pgf1');
        $killer = $this->session('pgf2');

        $this->assertTrue($driver->ping());

        $this->kill($killer, $driver);

        $this->assertFalse($driver->ping());
    }

    #[Test]
    public function reconnectIfNeededRestoresALostConnection(): void
    {
        // ping_idle_seconds = 0 makes every call check liveness; with the
        // default window a recently-used connection is trusted without a ping.
        $driver = $this->session('pgf1', ['ping_idle_seconds' => 0]);
        $killer = $this->session('pgf2');

        $driver->query(new Raw('SELECT 1'));
        $this->kill($killer, $driver);

        $driver->reconnectIfNeeded();

        $this->assertTrue($driver->ping());
        $this->assertSame(7, (int)$driver->query(new Raw('SELECT 7 AS n'))[0]['n']);
    }

    #[Test]
    public function queryRecoversFromALostConnection(): void
    {
        $driver = $this->session('pgf1');
        $killer = $this->session('pgf2');

        $driver->query(new Raw('SELECT 1'));
        $this->kill($killer, $driver);

        // No ping is due, so the statement itself fails and triggers the retry.
        $this->assertSame(5, (int)$driver->query(new Raw('SELECT 5 AS n'))[0]['n']);
    }

    #[Test]
    public function executeRecoversFromALostConnection(): void
    {
        $driver = $this->session('pgf1');
        $killer = $this->session('pgf2');

        $driver->query(new Raw('SELECT 1'));
        $this->kill($killer, $driver);

        $this->assertTrue($driver->execute(new Raw('SELECT 1')));
    }

    #[Test]
    public function reconnectDropsTheStatementCache(): void
    {
        // Prepared statements belong to the connection that made them, so a
        // reconnect must not leave the old ones behind.
        $driver = $this->session('pgf1', ['ping_idle_seconds' => 0]);
        $killer = $this->session('pgf2');

        $driver->query(new Raw('SELECT 1 AS n'));
        $driver->query(new Raw('SELECT 2 AS n'));
        $this->assertSame(2, $this->cacheSize($driver));

        $this->kill($killer, $driver);
        $driver->reconnectIfNeeded();

        $this->assertSame(0, $this->cacheSize($driver));
        $this->assertSame(9, (int)$driver->query(new Raw('SELECT 9 AS n'))[0]['n']);
    }

    #[Test]
    public function losingTheConnectionInsideATransactionThrows(): void
    {
        // Reconnecting would discard the work already written and let the rest
        // commit alone, so the driver must fail instead of retrying.
        $driver = $this->session('pgf1', ['ping_idle_seconds' => 0]);
        $killer = $this->session('pgf2');

        $driver->query(new Raw('SELECT 1'));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('lost during a transaction');

        $driver->transaction(function () use ($driver, $killer): bool {
            $driver->query(new Raw('SELECT 1 AS n'));
            $this->kill($killer, $driver);
            $driver->query(new Raw('SELECT 2 AS n'));

            return true;
        });
    }

    #[Test]
    public function theDriverIsUsableAfterATransactionLosesItsConnection(): void
    {
        $driver = $this->session('pgf1', ['ping_idle_seconds' => 0]);
        $killer = $this->session('pgf2');

        $driver->query(new Raw('SELECT 1'));

        try {
            $driver->transaction(function () use ($driver, $killer): bool {
                $this->kill($killer, $driver);
                $driver->query(new Raw('SELECT 1 AS n'));

                return true;
            });
        } catch (ConnectionException) {
            // Expected — asserted in the preceding test.
        }

        // The transaction depth must have unwound, or every later transaction
        // would be treated as nested inside a transaction that no longer exists.
        $this->assertSame(3, (int)$driver->query(new Raw('SELECT 3 AS n'))[0]['n']);
        $this->assertTrue($driver->transaction(fn(): bool => true));
    }

    #[Test]
    public function uncommittedWorkIsLostWhenTheConnectionDies(): void
    {
        $driver = $this->session('pgf1', ['ping_idle_seconds' => 0]);
        $killer = $this->session('pgf2');

        $driver->getPdo()?->exec('DROP TABLE IF EXISTS pgf_txn_probe');
        $driver->getPdo()?->exec('CREATE TABLE pgf_txn_probe (id serial primary key, v text)');
        $driver->query(new Raw('SELECT 1'));

        try {
            $driver->transaction(function () use ($driver, $killer): bool {
                $driver->execute(new Raw("INSERT INTO pgf_txn_probe (v) VALUES ('inside')"));
                $this->kill($killer, $driver);
                $driver->query(new Raw('SELECT 1 AS n'));

                return true;
            });
        } catch (ConnectionException) {
            // Expected.
        }

        // The server discarded the transaction with the connection, so nothing
        // from inside it may survive.
        $rows = $this->session('pgf3')->query(new Raw('SELECT count(*) AS c FROM pgf_txn_probe'));
        $this->assertSame(0, (int)$rows[0]['c']);

        $this->session('pgf3')->getPdo()?->exec('DROP TABLE IF EXISTS pgf_txn_probe');
    }

    #[Test]
    public function connectingToAMissingDatabaseThrows(): void
    {
        $this->expectException(ConnectionException::class);

        $this->session('pgf1', ['database' => 'no_such_database_zzz']);
    }

    #[Test]
    public function connectingToAClosedPortThrows(): void
    {
        $this->expectException(ConnectionException::class);

        $this->session('pgf1', ['port' => 59_999]);
    }

    // ---------------------------------------------------------------
    // execute() paths
    // ---------------------------------------------------------------

    #[Test]
    public function executeRunsStatementsWithAndWithoutBinds(): void
    {
        $driver = $this->session('pgf1');

        $driver->getPdo()?->exec('DROP TABLE IF EXISTS pgf_exec_probe');

        // No binds takes exec(); binds take a prepared statement.
        $this->assertTrue($driver->execute(
            new Raw('CREATE TABLE pgf_exec_probe (id serial primary key, v text)')
        ));
        $this->assertTrue($driver->execute(
            new Raw('INSERT INTO pgf_exec_probe (v) VALUES (?)', ['first'])
        ));
        $this->assertTrue($driver->execute(
            new Raw('UPDATE pgf_exec_probe SET v = ? WHERE v = ?', ['second', 'first'])
        ));

        $rows = $driver->query(new Raw('SELECT v FROM pgf_exec_probe'));
        $this->assertSame('second', $rows[0]['v']);

        $this->assertTrue($driver->execute(new Raw('DELETE FROM pgf_exec_probe')));
        $this->assertSame([], $driver->query(new Raw('SELECT v FROM pgf_exec_probe')));

        $driver->getPdo()?->exec('DROP TABLE IF EXISTS pgf_exec_probe');
    }

    #[Test]
    public function lastInsertIdReturnsTheGeneratedSequenceValue(): void
    {
        $driver = $this->session('pgf1');

        $driver->getPdo()?->exec('DROP TABLE IF EXISTS pgf_seq_probe');
        $driver->getPdo()?->exec('CREATE TABLE pgf_seq_probe (id serial primary key, v text)');

        $driver->execute(new Raw("INSERT INTO pgf_seq_probe (v) VALUES ('a')"));
        $this->assertSame('1', $driver->lastInsertId());

        $driver->execute(new Raw("INSERT INTO pgf_seq_probe (v) VALUES ('b')"));
        $this->assertSame('2', $driver->lastInsertId());

        $driver->getPdo()?->exec('DROP TABLE IF EXISTS pgf_seq_probe');
    }

    #[Test]
    public function executeThrowsOnInvalidSql(): void
    {
        $driver = $this->session('pgf1');

        $this->expectException(PDOException::class);

        $driver->execute(new Raw('NOT VALID SQL AT ALL'));
    }
}
