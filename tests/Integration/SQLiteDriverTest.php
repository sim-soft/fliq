<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\SQLiteDriver;

/**
 * Integration tests for the SQLite driver.
 *
 * Uses an in-memory SQLite database — no external server required.
 */
class SQLiteDriverTest extends TestCase
{
    protected static string $connName = 'sqlite_test';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            return;
        }

        Connection::add(static::$connName, [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        try {
            /** @var SQLiteDriver $driver */
            $driver = Connection::get(static::$connName);
            $pdo = $driver->getPdo();

            if ($pdo === null) {
                return;
            }

            // Load schema and test data
            $sqlFile = __DIR__ . '/../../resources/sample_db_sqlite.sql';
            $sql = file_get_contents($sqlFile);

            if ($sql === false) {
                return;
            }

            // Execute statements one by one (SQLite doesn't support multi-statement exec well)
            $statements = self::splitStatements($sql);
            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                if ($trimmed !== '') {
                    $pdo->exec($trimmed);
                }
            }
        } catch (\Throwable) {
            // Will be caught in setUp
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('ext-pdo_sqlite not available.');
        }

        try {
            Connection::get(static::$connName);
        } catch (\Throwable) {
            $this->markTestSkipped('SQLite connection not available.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::remove(static::$connName);
    }

    /**
     * Split SQL file into individual statements.
     *
     * @param string $sql The full SQL content.
     * @return array<int, string>
     */
    private static function splitStatements(string $sql): array
    {
        // Remove single-line comments
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;

        // Split on semicolons (simple approach for our controlled SQL files)
        $parts = explode(';', $sql);

        return array_filter(
            array_map('trim', $parts),
            fn(string $part) => $part !== ''
        );
    }

    #[Test]
    public function connectionEstablished(): void
    {
        $driver = Connection::get(static::$connName);
        $this->assertInstanceOf(SQLiteDriver::class, $driver);
    }

    #[Test]
    public function pingReturnsTrue(): void
    {
        /** @var SQLiteDriver $driver */
        $driver = Connection::get(static::$connName);
        $this->assertTrue($driver->ping());
    }

    #[Test]
    public function rawSelectQuery(): void
    {
        $raw = new Raw('SELECT 1 AS result');
        $raw->withConnection(static::$connName);
        $result = $raw->fetchAll();

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['result']);
    }

    #[Test]
    public function selectWithBinding(): void
    {
        $raw = new Raw('SELECT * FROM "user" WHERE id = ?', [1]);
        $raw->withConnection(static::$connName);
        $result = $raw->fetchAll();

        $this->assertCount(1, $result);
        $this->assertEquals('alice', $result[0]['username']);
    }

    #[Test]
    public function selectAllUsers(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user');

        $results = $query->query($query);
        $this->assertCount(10, $results);
    }

    #[Test]
    public function selectWithWhereCondition(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('role', 'admin');

        $results = $query->query($query);
        $this->assertCount(2, $results);
    }

    #[Test]
    public function selectWithLike(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->like('email', '%@example.com');

        $results = $query->query($query);
        $this->assertCount(10, $results);
    }

    #[Test]
    public function selectWithIn(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->in('id', [1, 2, 3]);

        $results = $query->query($query);
        $this->assertCount(3, $results);
    }

    #[Test]
    public function selectWithBetween(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->between('score', 50, 80);

        $results = $query->query($query);
        foreach ($results as $row) {
            $this->assertGreaterThanOrEqual(50, $row['score']);
            $this->assertLessThanOrEqual(80, $row['score']);
        }
    }

    #[Test]
    public function selectWithOrderAndLimit(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->orderBy('score', 'DESC')
            ->limit(3);

        $results = $query->query($query);
        $this->assertCount(3, $results);
        $this->assertEquals(95, $results[0]['score']);
    }

    #[Test]
    public function countAggregation(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('status_code', 1);

        $count = $query->count();
        $this->assertEquals(8, $count);
    }

    #[Test]
    public function sumAggregation(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('order')
            ->where('status_code', 4);

        $total = $query->sum('total');
        $this->assertGreaterThan(0, $total);
    }

    #[Test]
    public function avgAggregation(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('status_code', 1);

        $avg = $query->avg('score');
        $this->assertGreaterThan(0, $avg);
        $this->assertLessThan(100, $avg);
    }

    #[Test]
    public function minMaxAggregation(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('status_code', 1);

        $min = $query->min('score');
        $this->assertEquals(40, $min);

        $query2 = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('status_code', 1);

        $max = $query2->max('score');
        $this->assertEquals(95, $max);
    }

    #[Test]
    public function joinQuery(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('!post.title', '!user.username')
            ->from('post')
            ->join('user', ['id' => '!post.user_id'])
            ->where('!user.username', 'alice');

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    #[Test]
    public function leftJoinWithNull(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('!user.username')
            ->selectRaw('"order".id AS order_id')
            ->from('user')
            ->leftJoin('order', ['user_id' => '!user.id'])
            ->isNull('!order.id');

        $results = $query->query($query);
        // At least judy (no orders)
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function multipleJoins(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('!post.title', '!user.username', '!category.name')
            ->from('post')
            ->join('user', ['id' => '!post.user_id'])
            ->join('category', ['id' => '!post.category_id'])
            ->where('!user.username', 'alice');

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    #[Test]
    public function groupByHaving(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('!user.role')
            ->selectRaw('COUNT(*) as total')
            ->from('user')
            ->groupBy('role')
            ->havingRaw('COUNT(*) > ?', [2]);

        $results = $query->query($query);
        // 'member' has 6 users
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function unionQuery(): void
    {
        $admins = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('id', 'username', 'role')
            ->from('user')
            ->where('role', 'admin');

        $editors = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('id', 'username', 'role')
            ->from('user')
            ->where('role', 'editor');

        $results = $admins->union($editors)->query($admins);
        // 2 admins + 2 editors = 4
        $this->assertCount(4, $results);
    }

    #[Test]
    public function insertUpdateDelete(): void
    {
        // Insert
        $insert = new Raw(
            'INSERT INTO setting ("group", key, value) VALUES (?, ?, ?)',
            ['sqlite_test', 'test_key', 'test_value']
        );
        $insert->withConnection(static::$connName);
        $result = $insert->execute();
        $this->assertTrue($result);

        // Verify insert
        $check = new Raw('SELECT * FROM setting WHERE "group" = ?', ['sqlite_test']);
        $check->withConnection(static::$connName);
        $rows = $check->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertEquals('test_value', $rows[0]['value']);

        // Update
        $update = new Raw(
            'UPDATE setting SET value = ? WHERE "group" = ? AND key = ?',
            ['updated_value', 'sqlite_test', 'test_key']
        );
        $update->withConnection(static::$connName);
        $update->execute();

        // Verify update
        $check2 = new Raw('SELECT * FROM setting WHERE "group" = ?', ['sqlite_test']);
        $check2->withConnection(static::$connName);
        $rows2 = $check2->fetchAll();
        $this->assertEquals('updated_value', $rows2[0]['value']);

        // Delete
        $del = new Raw('DELETE FROM setting WHERE "group" = ?', ['sqlite_test']);
        $del->withConnection(static::$connName);
        $del->execute();

        // Verify deleted
        $check3 = new Raw('SELECT * FROM setting WHERE "group" = ?', ['sqlite_test']);
        $check3->withConnection(static::$connName);
        $this->assertEmpty($check3->fetchAll());
    }

    #[Test]
    public function transaction(): void
    {
        /** @var SQLiteDriver $driver */
        $driver = Connection::get(static::$connName);

        $committed = $driver->transaction(function () {
            $raw = new Raw(
                'INSERT INTO setting ("group", key, value) VALUES (?, ?, ?)',
                ['sqlite_tx', 'tx_key', 'tx_value']
            );
            $raw->withConnection(static::$connName);
            $raw->execute();
            return true;
        });

        $this->assertTrue($committed);

        // Verify committed
        $check = new Raw('SELECT * FROM setting WHERE "group" = ?', ['sqlite_tx']);
        $check->withConnection(static::$connName);
        $rows = $check->fetchAll();
        $this->assertCount(1, $rows);

        // Cleanup
        (new Raw('DELETE FROM setting WHERE "group" = ?', ['sqlite_tx']))
            ->withConnection(static::$connName)->execute();
    }

    #[Test]
    public function transactionRollback(): void
    {
        /** @var SQLiteDriver $driver */
        $driver = Connection::get(static::$connName);

        $committed = $driver->transaction(function () {
            $raw = new Raw(
                'INSERT INTO setting ("group", key, value) VALUES (?, ?, ?)',
                ['sqlite_rb', 'rb_key', 'rb_value']
            );
            $raw->withConnection(static::$connName);
            $raw->execute();
            return false; // Trigger rollback
        });

        $this->assertFalse($committed);

        // Verify rolled back
        $check = new Raw('SELECT * FROM setting WHERE "group" = ?', ['sqlite_rb']);
        $check->withConnection(static::$connName);
        $this->assertEmpty($check->fetchAll());
    }

    #[Test]
    public function jsonQuery(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('setting')
            ->whereJson('metadata->priority', '=', 1);

        $results = $query->query($query);
        $this->assertCount(3, $results);
    }

    #[Test]
    public function jsonContains(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('setting')
            ->whereJsonContains('metadata->tags', 'core');

        $results = $query->query($query);
        $this->assertCount(3, $results);
    }

    #[Test]
    public function jsonDoesntContain(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('setting')
            ->whereJsonDoesntContain('metadata->tags', 'core');

        $results = $query->query($query);
        $this->assertCount(7, $results);
    }

    #[Test]
    public function jsonLength(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('setting')
            ->whereJsonLength('metadata->tags', '>=', 2);

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(4, count($results));
    }

    #[Test]
    public function subqueryIn(): void
    {
        // Users who have published posts
        $subquery = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->select('user_id')
            ->from('post')
            ->where('status_code', 2);

        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->in('id', $subquery);

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(5, count($results));
    }

    #[Test]
    public function compositePrimaryKeyInsert(): void
    {
        // Insert into post_tag (composite PK: post_id, tag_id)
        $insert = new Raw(
            'INSERT INTO post_tag (post_id, tag_id) VALUES (?, ?)',
            [5, 3]
        );
        $insert->withConnection(static::$connName);
        $result = $insert->execute();
        $this->assertTrue($result);

        // Verify
        $check = new Raw('SELECT * FROM post_tag WHERE post_id = ? AND tag_id = ?', [5, 3]);
        $check->withConnection(static::$connName);
        $rows = $check->fetchAll();
        $this->assertCount(1, $rows);

        // Cleanup
        (new Raw('DELETE FROM post_tag WHERE post_id = ? AND tag_id = ?', [5, 3]))
            ->withConnection(static::$connName)->execute();
    }

    #[Test]
    public function compositePrimaryKeyQuery(): void
    {
        // Query pivot table with both keys
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('post_tag')
            ->where('post_id', 1);

        $results = $query->query($query);
        // Post 1 has 2 tags
        $this->assertCount(2, $results);
    }

    #[Test]
    public function whereAnyMultiColumn(): void
    {
        // whereAny matches if ANY of the columns satisfies the condition (OR logic)
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->whereAny(['username', 'email'], 'LIKE', '%alice%');

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals('alice', $results[0]['username']);
    }

    #[Test]
    public function whereAllMultiColumn(): void
    {
        // whereAll matches if ALL columns satisfy the condition (AND logic)
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->whereAll(['username', 'email'], 'LIKE', '%alice%');

        $results = $query->query($query);
        $this->assertCount(1, $results);
        $this->assertEquals('alice', $results[0]['username']);
    }

    #[Test]
    public function whereNoneMultiColumn(): void
    {
        // whereNone matches if NONE of the columns satisfy the condition
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->whereNone(['username', 'email'], 'LIKE', '%alice%');

        $results = $query->query($query);
        // All users except alice
        $this->assertCount(9, $results);
    }

    #[Test]
    public function whereDateFilter(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('order')
            ->whereDate('ordered_at', '=', '2024-01-05');

        $results = $query->query($query);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['id']);
    }

    #[Test]
    public function whereMonthFilter(): void
    {
        // January orders
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('order')
            ->whereMonth('ordered_at', '=', 1);

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(3, count($results));
    }

    #[Test]
    public function whereYearFilter(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('order')
            ->whereYear('ordered_at', '=', 2024);

        $results = $query->query($query);
        $this->assertCount(20, $results);
    }

    #[Test]
    public function whereTimeFilter(): void
    {
        // Order 1 was at 10:00:00
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('order')
            ->whereTime('ordered_at', '=', '10:00:00');

        $results = $query->query($query);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function batchInsert(): void
    {
        // Insert multiple rows in a single statement
        $insert = new Raw(
            'INSERT INTO setting ("group", key, value) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)',
            ['sqlite_batch', 'key1', 'value1', 'sqlite_batch', 'key2', 'value2', 'sqlite_batch', 'key3', 'value3']
        );
        $insert->withConnection(static::$connName);
        $result = $insert->execute();
        $this->assertTrue($result);

        // Verify all 3 rows inserted
        $check = new Raw('SELECT * FROM setting WHERE "group" = ? ORDER BY key', ['sqlite_batch']);
        $check->withConnection(static::$connName);
        $rows = $check->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertEquals('key1', $rows[0]['key']);
        $this->assertEquals('key2', $rows[1]['key']);
        $this->assertEquals('key3', $rows[2]['key']);

        // Cleanup
        (new Raw('DELETE FROM setting WHERE "group" = ?', ['sqlite_batch']))
            ->withConnection(static::$connName)->execute();
    }

    #[Test]
    public function paginateResults(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('status_code', 1);

        $paginator = $query->paginate(perPage: 3, page: 1);

        $this->assertInstanceOf(\Simsoft\DB\Paginator::class, $paginator);
        $this->assertCount(3, $paginator->data);
        $this->assertEquals(8, $paginator->total);
        $this->assertEquals(3, $paginator->perPage);
        $this->assertEquals(1, $paginator->currentPage);
        $this->assertEquals(3, $paginator->lastPage);
        $this->assertTrue($paginator->hasMorePages());
    }

    #[Test]
    public function cursorPaginateResults(): void
    {
        $query = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('status_code', 1);

        $result = $query->cursorPaginate(perPage: 3);

        $this->assertInstanceOf(\Simsoft\DB\CursorPaginator::class, $result);
        $this->assertCount(3, $result->data);
        $this->assertEquals(3, $result->perPage);
        $this->assertTrue($result->hasMore);
        $this->assertNotNull($result->nextCursor);
        $this->assertNull($result->previousCursor);

        // Get second page
        $query2 = (new ActiveQuery())
            ->withConnection(static::$connName)
            ->from('user')
            ->where('status_code', 1);

        $page2 = $query2->cursorPaginate(perPage: 3, cursor: $result->nextCursor);
        $this->assertCount(3, $page2->data);
        $this->assertTrue($page2->hasMore);
    }

    #[Test]
    public function foreignKeysEnabled(): void
    {
        // Attempt to insert a comment referencing a non-existent post
        $insert = new Raw(
            'INSERT INTO comment (post_id, user_id, body) VALUES (?, ?, ?)',
            [9999, 1, 'This should fail']
        );
        $insert->withConnection(static::$connName);

        $this->expectException(\Simsoft\DB\Exceptions\QueryException::class);
        $insert->execute();
    }

    #[Test]
    public function lastInsertId(): void
    {
        /** @var SQLiteDriver $driver */
        $driver = Connection::get(static::$connName);

        $insert = new Raw(
            'INSERT INTO setting ("group", key, value) VALUES (?, ?, ?)',
            ['sqlite_lid', 'lid_key', 'lid_value']
        );
        $insert->withConnection(static::$connName);
        $insert->execute();

        $lastId = $driver->lastInsertId();
        $this->assertNotFalse($lastId);
        $this->assertGreaterThan(0, (int)$lastId);

        // Cleanup
        (new Raw('DELETE FROM setting WHERE "group" = ?', ['sqlite_lid']))
            ->withConnection(static::$connName)->execute();
    }
}
