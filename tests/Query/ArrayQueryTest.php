<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * Unit tests for PostgreSQL array column query methods.
 */
class ArrayQueryTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('pgsql', [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'postgres',
            'password' => '',
        ]);
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    // ------------------------------------------------------------------
    // PostgreSQL array queries
    // ------------------------------------------------------------------

    #[Test]
    public function whereArrayContainsPostgres(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('pgsql')
            ->arrayContains('tags', 'php');

        $sql = $query->getSQL();
        $this->assertStringContainsString('@>', $sql);
        $this->assertStringContainsString('ARRAY[?]::text[]', $sql);
        $this->assertEquals(['php'], $query->getBinds());
    }

    #[Test]
    public function whereArrayContainsWithIntType(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('pgsql')
            ->arrayContains('role_ids', 5, 'int');

        $sql = $query->getSQL();
        $this->assertStringContainsString('@>', $sql);
        $this->assertStringContainsString('ARRAY[?]::int[]', $sql);
        $this->assertEquals([5], $query->getBinds());
    }

    #[Test]
    public function whereArrayOverlapsPostgres(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('pgsql')
            ->arrayOverlaps('tags', ['php', 'python', 'go']);

        $sql = $query->getSQL();
        $this->assertStringContainsString('&&', $sql);
        $this->assertStringContainsString('ARRAY[?,?,?]::text[]', $sql);
        $this->assertEquals(['php', 'python', 'go'], $query->getBinds());
    }

    #[Test]
    public function whereArrayOverlapsWithIntType(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('pgsql')
            ->arrayOverlaps('department_ids', [1, 3], 'int');

        $sql = $query->getSQL();
        $this->assertStringContainsString('&&', $sql);
        $this->assertStringContainsString('ARRAY[?,?]::int[]', $sql);
        $this->assertEquals([1, 3], $query->getBinds());
    }

    #[Test]
    public function orWhereArrayContains(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('pgsql')
            ->arrayContains('tags', 'php')
            ->orArrayContains('tags', 'python');

        $sql = $query->getSQL();
        $this->assertStringContainsString('OR', $sql);
        $this->assertEquals(['php', 'python'], $query->getBinds());
    }

    #[Test]
    public function orWhereArrayOverlaps(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('pgsql')
            ->where('status', 'active')
            ->orArrayOverlaps('skills', ['docker', 'k8s']);

        $sql = $query->getSQL();
        $this->assertStringContainsString('OR', $sql);
        $this->assertEquals(['active', 'docker', 'k8s'], $query->getBinds());
    }

    #[Test]
    public function whereArrayContainsCombinedWithOtherConditions(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('pgsql')
            ->where('status_code', 1)
            ->arrayContains('tags', 'senior')
            ->orderBy('name');

        $sql = $query->getSQL();
        $this->assertStringContainsString('"user"."status_code" = ?', $sql);
        $this->assertStringContainsString('@>', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertEquals([1, 'senior'], $query->getBinds());
    }

    // ------------------------------------------------------------------
    // MySQL fallback (JSON-based)
    // ------------------------------------------------------------------

    #[Test]
    public function whereArrayContainsMysqlFallback(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('mysql')
            ->arrayContains('tags', 'php');

        $sql = $query->getSQL();
        $this->assertStringContainsString('JSON_CONTAINS', $sql);
        $this->assertEquals(['php'], $query->getBinds());
    }

    #[Test]
    public function whereArrayOverlapsMysqlFallback(): void
    {
        $query = (new ActiveQuery())
            ->from('user')
            ->withConnection('mysql')
            ->arrayOverlaps('tags', ['php', 'go']);

        $sql = $query->getSQL();
        $this->assertStringContainsString('JSON_OVERLAPS', $sql);
        $this->assertStringContainsString('JSON_ARRAY', $sql);
        $this->assertEquals(['php', 'go'], $query->getBinds());
    }
}
