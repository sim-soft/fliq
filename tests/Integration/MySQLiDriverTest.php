<?php

namespace Integration;

use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\MySQLiDriver;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for the MySQLi driver.
 *
 * Requires ext-mysqli. Skipped automatically if not available.
 */
class MySQLiDriverTest extends TestCase
{
    protected static bool $available = false;
    protected static string $connName = 'mysqli_test';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('mysqli')) {
            static::$available = false;
            return;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $database = getenv('DB_DATABASE') ?: 'sample_db';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        Connection::add(static::$connName, [
            'driver' => 'mysqli',
            'host' => $host,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
        ]);

        try {
            Connection::get(static::$connName);
            static::$available = true;
        } catch (\Throwable) {
            static::$available = false;
        }
    }

    protected function setUp(): void
    {
        if (!static::$available) {
            $this->markTestSkipped('MySQLi driver not available or ext-mysqli not loaded.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        Connection::remove(static::$connName);
    }

    #[Test]
    public function connectionEstablished(): void
    {
        $driver = Connection::get(static::$connName);
        $this->assertInstanceOf(MySQLiDriver::class, $driver);
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
        $raw = new Raw('SELECT * FROM `user` WHERE `id` = ?', [1]);
        $raw->withConnection(static::$connName);
        $result = $raw->fetchAll();

        $this->assertCount(1, $result);
        $this->assertEquals('alice', $result[0]['username']);
    }

    #[Test]
    public function activeQueryWorks(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->where('status_code', 1)
            ->orderBy('id')
            ->limit(3)
            ->withConnection(static::$connName);

        $results = $query->query($query);

        $this->assertCount(3, $results);
    }

    #[Test]
    public function insertAndDelete(): void
    {
        $raw = new Raw(
            'INSERT INTO `setting` (`group`, `key`, `value`) VALUES (?, ?, ?)',
            ['mysqli_test', 'test_key', 'test_value']
        );
        $raw->withConnection(static::$connName);
        $result = $raw->execute();
        $this->assertTrue($result);

        $del = new Raw("DELETE FROM `setting` WHERE `group` = 'mysqli_test'");
        $del->withConnection(static::$connName);
        $del->execute();

        $check = new Raw("SELECT * FROM `setting` WHERE `group` = 'mysqli_test'");
        $check->withConnection(static::$connName);
        $rows = $check->fetchAll();
        $this->assertEmpty($rows);
    }

    #[Test]
    public function transaction(): void
    {
        $driver = Connection::get(static::$connName);

        $committed = $driver->transaction(function () {
            $raw = new Raw(
                'INSERT INTO `setting` (`group`, `key`, `value`) VALUES (?, ?, ?)',
                ['mysqli_tx', 'tx_key', 'tx_value']
            );
            $raw->withConnection(static::$connName);
            $raw->execute();
            return true;
        });

        $this->assertTrue($committed);

        // Cleanup
        (new Raw("DELETE FROM `setting` WHERE `group` = 'mysqli_tx'"))
            ->withConnection(static::$connName)->execute();
    }

    #[Test]
    public function transactionRollback(): void
    {
        $driver = Connection::get(static::$connName);

        $driver->transaction(function () {
            $raw = new Raw(
                'INSERT INTO `setting` (`group`, `key`, `value`) VALUES (?, ?, ?)',
                ['mysqli_rollback', 'rb_key', 'rb_value']
            );
            $raw->withConnection(static::$connName);
            $raw->execute();
            return false; // rollback
        });

        $check = new Raw("SELECT * FROM `setting` WHERE `group` = 'mysqli_rollback'");
        $check->withConnection(static::$connName);
        $rows = $check->fetchAll();
        $this->assertEmpty($rows);
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
    public function compositePrimaryKey(): void
    {
        // Insert into post_tag (composite PK: post_id, tag_id)
        $insert = new Raw(
            'INSERT INTO `post_tag` (`post_id`, `tag_id`) VALUES (?, ?)',
            [5, 3]
        );
        $insert->withConnection(static::$connName);
        $result = $insert->execute();
        $this->assertTrue($result);

        // Verify
        $check = new Raw('SELECT * FROM `post_tag` WHERE `post_id` = ? AND `tag_id` = ?', [5, 3]);
        $check->withConnection(static::$connName);
        $rows = $check->fetchAll();
        $this->assertCount(1, $rows);

        // Cleanup
        (new Raw('DELETE FROM `post_tag` WHERE `post_id` = ? AND `tag_id` = ?', [5, 3]))
            ->withConnection(static::$connName)->execute();
    }
}
