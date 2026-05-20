<?php

namespace Integration;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * Integration tests for the query builder against a real database.
 */
class QueryBuilderTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTable();
    }

    private function createTestTable(): void
    {
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS _test_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            age INT DEFAULT 0,
            status TINYINT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )';
        (new Raw($sql))->withConnection('test')->execute();

        // Clear any existing data from previous test methods
        (new Raw('TRUNCATE TABLE _test_users'))->withConnection('test')->execute();

        // Seed data
        $insert = new Raw(
            'INSERT INTO _test_users (name, email, age, status) VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)',
            ['Alice', 'alice@test.com', 25, 1, 'Bob', 'bob@test.com', 30, 1, 'Charlie', null, 35, 0]
        );
        $insert->withConnection('test');
        $insert->execute();
    }

    public function testSelectAll(): void
    {
        $query = (new ActiveQuery())->from('_test_users')->withConnection('test');
        $results = $query->query($query);

        $this->assertCount(3, $results);
    }

    public function testSelectWithWhere(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->where('status', 1)
            ->withConnection('test');

        $results = $query->query($query);
        $this->assertCount(2, $results);
    }

    public function testSelectWithWhereNull(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->where('email', null)
            ->withConnection('test');

        $results = $query->query($query);
        $this->assertCount(1, $results);
        $this->assertEquals('Charlie', $results[0]['name']);
    }

    public function testSelectWithIn(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->in('name', ['Alice', 'Bob'])
            ->withConnection('test');

        $results = $query->query($query);
        $this->assertCount(2, $results);
    }

    public function testSelectWithBetween(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->between('age', 26, 36)
            ->withConnection('test');

        $results = $query->query($query);
        $this->assertCount(2, $results);
    }

    public function testSelectWithLike(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->like('name', '%li%')
            ->withConnection('test');

        $results = $query->query($query);
        $this->assertCount(2, $results); // Alice, Charlie
    }

    public function testCount(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->where('status', 1)
            ->withConnection('test');

        $count = $query->count();
        $this->assertEquals(2, $count);
    }

    public function testOrderByAndLimit(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->orderBy('age', 'DESC')
            ->limit(2)
            ->withConnection('test');

        $results = $query->query($query);
        $this->assertCount(2, $results);
        $this->assertEquals('Charlie', $results[0]['name']);
        $this->assertEquals('Bob', $results[1]['name']);
    }

    public function testFirst(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->where('name', 'Alice')
            ->withConnection('test');

        /** @var array<string, mixed> $result */
        $result = $query->first();
        $this->assertEquals('Alice', $result['name']);
        $this->assertEquals('alice@test.com', $result['email']);
    }

    public function testGroupedConditions(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_users')
            ->where('status', 1)
            ->where(function ($q) {
                $q->where('age', '>', 28)
                    ->orWhere('name', 'Alice');
            })
            ->withConnection('test');

        $results = $query->query($query);
        $this->assertCount(2, $results); // Alice (age 25, matches name) + Bob (age 30, matches age)
    }
}
