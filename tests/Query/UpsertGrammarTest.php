<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Builder\Upsert;
use Simsoft\DB\Connection;

/**
 * Tests Upsert builder with different Grammar implementations.
 */
class UpsertGrammarTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function mysqlUpsertAllColumns(): void
    {
        Connection::add('mysql', ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'username' => 'root', 'password' => '']);

        $upsert = new Upsert('user', ['name' => 'John', 'email' => 'john@test.com', 'status' => 1]);
        $upsert->withConnection('mysql');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('INSERT INTO `user`', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`name` = VALUES(`name`)', $sql);
        $this->assertStringContainsString('`email` = VALUES(`email`)', $sql);
        $this->assertStringContainsString('`status` = VALUES(`status`)', $sql);
        $this->assertEquals(['John', 'john@test.com', 1], $upsert->getBinds());
    }

    #[Test]
    public function mysqlUpsertSpecificColumns(): void
    {
        Connection::add('mysql', ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'username' => 'root', 'password' => '']);

        $upsert = new Upsert('user', ['name' => 'John', 'email' => 'john@test.com', 'status' => 1], ['email', 'status']);
        $upsert->withConnection('mysql');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`email` = VALUES(`email`)', $sql);
        $this->assertStringContainsString('`status` = VALUES(`status`)', $sql);
        $this->assertStringNotContainsString('`name` = VALUES(`name`)', $sql);
    }

    #[Test]
    public function mysqlUpsertWithExplicitValues(): void
    {
        Connection::add('mysql', ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'username' => 'root', 'password' => '']);

        $upsert = new Upsert('user', ['name' => 'John', 'email' => 'john@test.com'], ['name' => 'Updated']);
        $upsert->withConnection('mysql');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('`name` = ?', $sql);
        $this->assertEquals(['John', 'john@test.com', 'Updated'], $upsert->getBinds());
    }

    #[Test]
    public function postgresUpsertUsesOnConflict(): void
    {
        Connection::add('pg', ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'test', 'username' => 'postgres', 'password' => '']);

        $upsert = new Upsert('user', ['name' => 'John', 'email' => 'john@test.com'], ['name'], ['email']);
        $upsert->withConnection('pg');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('INSERT INTO "user"', $sql);
        $this->assertStringContainsString('ON CONFLICT ("email") DO UPDATE SET', $sql);
        $this->assertStringContainsString('"name" = EXCLUDED."name"', $sql);
        $this->assertEquals(['John', 'john@test.com'], $upsert->getBinds());
    }

    #[Test]
    public function postgresUpsertDefaultsToFirstColumnConflict(): void
    {
        Connection::add('pg', ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'test', 'username' => 'postgres', 'password' => '']);

        $upsert = new Upsert('user', ['id' => 1, 'name' => 'John'], ['name']);
        $upsert->withConnection('pg');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET', $sql);
    }

    #[Test]
    public function postgresUpsertCompositeConflictColumns(): void
    {
        Connection::add('pg', ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'test', 'username' => 'postgres', 'password' => '']);

        $upsert = new Upsert('org_users', ['org_id' => 1, 'user_id' => 42, 'role' => 'admin'], ['role'], ['org_id', 'user_id']);
        $upsert->withConnection('pg');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('ON CONFLICT ("org_id", "user_id") DO UPDATE SET', $sql);
        $this->assertStringContainsString('"role" = EXCLUDED."role"', $sql);
    }

    #[Test]
    public function sqliteUpsertUsesOnConflict(): void
    {
        Connection::add('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        $upsert = new Upsert('user', ['id' => 1, 'name' => 'John', 'email' => 'john@test.com'], ['name', 'email'], ['id']);
        $upsert->withConnection('sqlite');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('INSERT INTO "user"', $sql);
        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET', $sql);
        $this->assertStringContainsString('"name" = excluded."name"', $sql);
        $this->assertStringContainsString('"email" = excluded."email"', $sql);
    }

    #[Test]
    public function sqliteUpsertCompositeConflict(): void
    {
        Connection::add('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        $upsert = new Upsert('org_users', ['org_id' => 1, 'user_id' => 42, 'role' => 'admin'], ['role'], ['org_id', 'user_id']);
        $upsert->withConnection('sqlite');
        $sql = $upsert->getSQL();

        $this->assertStringContainsString('ON CONFLICT ("org_id", "user_id") DO UPDATE SET', $sql);
    }
}
