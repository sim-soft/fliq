<?php

namespace Integration;

use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Drivers\PDODriver;

/**
 * Integration tests for database connection and basic operations.
 */
class ConnectionTest extends DatabaseTestCase
{
    public function testConnectionEstablished(): void
    {
        $driver = Connection::get('test');
        $this->assertInstanceOf(PDODriver::class, $driver);
    }

    public function testPingReturnsTrue(): void
    {
        /** @var \Simsoft\DB\Drivers\PDODriver $driver */
        $driver = Connection::get('test');
        $this->assertTrue($driver->ping());
    }

    public function testRawSelectQuery(): void
    {
        $raw = new Raw('SELECT 1 AS result');
        $raw->withConnection('test');
        $result = $raw->fetchAll();

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['result']);
    }

    public function testCreateAndDropTable(): void
    {
        $create = new Raw('CREATE TEMPORARY TABLE _test_conn (id INT PRIMARY KEY, name VARCHAR(50))');
        $create->withConnection('test');
        $create->execute();

        $insert = new Raw('INSERT INTO _test_conn (id, name) VALUES (?, ?)', [1, 'test']);
        $insert->withConnection('test');
        $result = $insert->execute();
        $this->assertTrue($result);

        $select = new Raw('SELECT * FROM _test_conn WHERE id = ?', [1]);
        $select->withConnection('test');
        $rows = $select->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertEquals('test', $rows[0]['name']);
    }

    public function testTransaction(): void
    {
        $driver = Connection::get('test');

        $create = new Raw('CREATE TEMPORARY TABLE _test_tx (id INT PRIMARY KEY, val INT)');
        $create->withConnection('test');
        $create->execute();

        // Commit
        $committed = $driver->transaction(function () {
            $insert = new Raw('INSERT INTO _test_tx (id, val) VALUES (?, ?)', [1, 100]);
            $insert->withConnection('test');
            $insert->execute();
            return true;
        });
        $this->assertTrue($committed);

        $select = new Raw('SELECT val FROM _test_tx WHERE id = ?', [1]);
        $select->withConnection('test');
        $rows = $select->fetchAll();
        $this->assertEquals(100, $rows[0]['val']);

        // Rollback
        $rolledBack = $driver->transaction(function () {
            $insert = new Raw('INSERT INTO _test_tx (id, val) VALUES (?, ?)', [2, 200]);
            $insert->withConnection('test');
            $insert->execute();
            return false; // triggers rollback
        });
        $this->assertFalse($rolledBack);

        $select2 = new Raw('SELECT * FROM _test_tx WHERE id = ?', [2]);
        $select2->withConnection('test');
        $rows2 = $select2->fetchAll();
        $this->assertEmpty($rows2);
    }
}
