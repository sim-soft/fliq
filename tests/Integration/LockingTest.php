<?php

namespace Integration;

use Models\User;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for row-level locking against a live MySQL server.
 *
 * The lock methods had no tests, and NOWAIT / SKIP LOCKED were absent from the
 * MySQL grammar's match, so both fell through to a plain FOR UPDATE. Asserting
 * on the generated SQL alone would not have caught what that costs: a caller
 * asking to skip locked rows got the blocking behaviour instead, which is the
 * opposite of the job queue pattern the docs recommend it for.
 *
 * So the modifiers are tested under real contention — a second connection
 * holds a row lock in an open transaction while the query under test runs.
 *
 * MySQL supports NOWAIT and SKIP LOCKED from 8.0. Tests needing them are
 * skipped on older servers rather than failing.
 */
class LockingTest extends DatabaseTestCase
{
    /** @var PDO|null A second connection used to hold a competing lock. */
    private ?PDO $rival = null;

    protected function setUp(): void
    {
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available.');
        }
    }

    protected function tearDown(): void
    {
        // Roll back whatever the rival still holds, so a failing test cannot
        // leave a lock behind and stall the rest of the class.
        if ($this->rival !== null) {
            if ($this->rival->inTransaction()) {
                $this->rival->rollBack();
            }
            $this->rival = null;
        }
    }

    /**
     * Open a second connection and lock one row inside an open transaction.
     *
     * @param int $id The user id to lock.
     * @return void
     */
    private function rivalLocks(int $id): void
    {
        $this->rival = new PDO(
            'mysql:host=127.0.0.1;dbname=sample_db;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->rival->beginTransaction();

        $stmt = $this->rival->prepare('SELECT id FROM user WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $stmt->fetchAll();
    }

    /**
     * Whether the server understands the NOWAIT / SKIP LOCKED modifiers.
     *
     * @return bool
     */
    private function supportsLockModifiers(): bool
    {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=sample_db', 'root', '');
        $statement = $pdo->query('SELECT VERSION()');
        $this->assertNotFalse($statement, 'The server did not answer a version query.');

        return version_compare((string)$statement->fetchColumn(), '8.0.0', '>=');
    }

    // ---------------------------------------------------------------
    // Generated SQL
    // ---------------------------------------------------------------

    #[Test]
    public function forUpdateAppendsTheLockClause(): void
    {
        $this->assertStringEndsWith('FOR UPDATE', User::find()->forUpdate()->getSQL());
    }

    #[Test]
    public function forShareAppendsASharedLock(): void
    {
        $this->assertStringEndsWith('FOR SHARE', User::find()->forShare()->getSQL());
    }

    #[Test]
    public function noWaitKeepsItsModifier(): void
    {
        // This is the regression: 'noWait' was missing from the MySQL grammar's
        // match, so it fell to the default and the modifier disappeared.
        $this->assertStringEndsWith('FOR UPDATE NOWAIT', User::find()->forUpdateNoWait()->getSQL());
    }

    #[Test]
    public function skipLockedKeepsItsModifier(): void
    {
        $this->assertStringEndsWith(
            'FOR UPDATE SKIP LOCKED',
            User::find()->forUpdateSkipLocked()->getSQL()
        );
    }

    #[Test]
    public function noLockMethodMeansNoLockClause(): void
    {
        $this->assertStringNotContainsString('FOR UPDATE', User::find()->getSQL());
        $this->assertStringNotContainsString('FOR SHARE', User::find()->getSQL());
    }

    #[Test]
    public function theLastLockMethodWins(): void
    {
        // The lock type is a single slot, not a list.
        $this->assertStringEndsWith(
            'FOR UPDATE SKIP LOCKED',
            User::find()->forUpdate()->forUpdateSkipLocked()->getSQL()
        );
    }

    // ---------------------------------------------------------------
    // Behaviour under contention
    // ---------------------------------------------------------------

    #[Test]
    public function skipLockedOmitsRowsAnotherTransactionHolds(): void
    {
        if (!$this->supportsLockModifiers()) {
            $this->markTestSkipped('SKIP LOCKED needs MySQL 8.0+.');
        }

        $this->rivalLocks(1);

        $ids = [];
        foreach (User::find()->where('id', '<=', 3)->forUpdateSkipLocked()->get() as $user) {
            $ids[] = $user->id;
        }
        sort($ids);

        // The whole point of the modifier: the locked row is passed over and
        // the rest are returned, rather than the query blocking on row 1.
        $this->assertSame([2, 3], $ids);
    }

    #[Test]
    public function skipLockedReturnsEverythingWhenNothingIsLocked(): void
    {
        if (!$this->supportsLockModifiers()) {
            $this->markTestSkipped('SKIP LOCKED needs MySQL 8.0+.');
        }

        $ids = [];
        foreach (User::find()->where('id', '<=', 3)->forUpdateSkipLocked()->get() as $user) {
            $ids[] = $user->id;
        }
        sort($ids);

        $this->assertSame([1, 2, 3], $ids);
    }

    #[Test]
    public function noWaitFailsImmediatelyOnALockedRow(): void
    {
        if (!$this->supportsLockModifiers()) {
            $this->markTestSkipped('NOWAIT needs MySQL 8.0+.');
        }

        $this->rivalLocks(1);

        $started = microtime(true);

        try {
            User::find()->where('id', 1)->forUpdateNoWait()->get();
            $this->fail('NOWAIT should have raised an error on a locked row.');
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $started;

            // "Immediately" is the contract: without the modifier this would
            // sit on the lock until innodb_lock_wait_timeout, 50s by default.
            $this->assertLessThan(5.0, $elapsed, 'NOWAIT waited instead of failing at once.');
            $this->assertStringContainsStringIgnoringCase('lock', $e->getMessage());
        }
    }

    #[Test]
    public function aPlainForUpdateStillReadsAnUnlockedRow(): void
    {
        $ids = [];
        foreach (User::find()->where('id', 1)->forUpdate()->get() as $user) {
            $ids[] = $user->id;
        }

        $this->assertSame([1], $ids);
    }
}
