<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Connection;

/**
 * Integration tests for Upsert execution against a real database.
 */
class UpsertExecutionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a temp table for upsert tests
        (new Raw('CREATE TEMPORARY TABLE IF NOT EXISTS _upsert_test (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(100) NOT NULL,
            name VARCHAR(100) NOT NULL,
            score INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY idx_email (email)
        )'))->withConnection('mysql')->execute();

        (new Raw('TRUNCATE TABLE _upsert_test'))->withConnection('mysql')->execute();
    }

    #[Test]
    public function upsertInsertsNewRecord(): void
    {
        $upsert = new Upsert(
            '_upsert_test',
            ['email' => 'test@example.com', 'name' => 'Test User', 'score' => 10],
            ['name', 'score']
        );
        $upsert->withConnection('mysql');
        $result = $upsert->execute();

        $this->assertTrue($result);

        $row = (new Raw('SELECT * FROM _upsert_test WHERE email = ?', ['test@example.com']))
            ->withConnection('mysql')
            ->fetchAll();

        $this->assertCount(1, $row);
        $this->assertEquals('Test User', $row[0]['name']);
        $this->assertEquals(10, $row[0]['score']);
    }

    #[Test]
    public function upsertUpdatesExistingRecord(): void
    {
        // Insert first
        (new Raw('INSERT INTO _upsert_test (email, name, score) VALUES (?, ?, ?)', ['dup@example.com', 'Original', 5]))
            ->withConnection('mysql')->execute();

        // Upsert with same email — should update name and score
        $upsert = new Upsert(
            '_upsert_test',
            ['email' => 'dup@example.com', 'name' => 'Updated', 'score' => 99],
            ['name', 'score']
        );
        $upsert->withConnection('mysql');
        $result = $upsert->execute();

        $this->assertTrue($result);

        $row = (new Raw('SELECT * FROM _upsert_test WHERE email = ?', ['dup@example.com']))
            ->withConnection('mysql')
            ->fetchAll();

        $this->assertCount(1, $row);
        $this->assertEquals('Updated', $row[0]['name']);
        $this->assertEquals(99, $row[0]['score']);
    }

    #[Test]
    public function upsertWithAllColumnsUpdated(): void
    {
        (new Raw('INSERT INTO _upsert_test (email, name, score) VALUES (?, ?, ?)', ['all@example.com', 'Before', 1]))
            ->withConnection('mysql')->execute();

        // Upsert with empty update columns = update all
        $upsert = new Upsert(
            '_upsert_test',
            ['email' => 'all@example.com', 'name' => 'After', 'score' => 50],
            []
        );
        $upsert->withConnection('mysql');
        $result = $upsert->execute();

        $this->assertTrue($result);

        $row = (new Raw('SELECT * FROM _upsert_test WHERE email = ?', ['all@example.com']))
            ->withConnection('mysql')
            ->fetchAll();

        $this->assertEquals('After', $row[0]['name']);
        $this->assertEquals(50, $row[0]['score']);
    }
}
