<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * Unit tests for table alias in query builder.
 */
class AliasTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
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

    #[Test]
    public function aliasAfterFrom(): void
    {
        $sql = (new ActiveQuery())
            ->from('user')
            ->alias('u')
            ->where('status', 1)
            ->getSQL();

        $this->assertStringContainsString('FROM `user` `u`', $sql);
        $this->assertStringContainsString('`u`.`status`', $sql);
    }

    #[Test]
    public function aliasInFromString(): void
    {
        $sql = (new ActiveQuery())
            ->from('user u')
            ->where('status', 1)
            ->getSQL();

        $this->assertStringContainsString('FROM `user` `u`', $sql);
        $this->assertStringContainsString('`u`.`status`', $sql);
    }

    #[Test]
    public function aliasOverridesPreviousAlias(): void
    {
        $sql = (new ActiveQuery())
            ->from('user u')
            ->alias('m')
            ->where('role', 'admin')
            ->getSQL();

        $this->assertStringContainsString('FROM `user` `m`', $sql);
        $this->assertStringContainsString('`m`.`role`', $sql);
        $this->assertStringNotContainsString('`u`', $sql);
    }

    #[Test]
    public function noAliasUsesTableName(): void
    {
        $sql = (new ActiveQuery())
            ->from('users')
            ->where('status', 1)
            ->getSQL();

        $this->assertStringContainsString('FROM `users`', $sql);
        $this->assertStringContainsString('`users`.`status`', $sql);
        $this->assertStringNotContainsString('FROM `users` `users`', $sql);
    }

    #[Test]
    public function aliasInCorrelatedSubquery(): void
    {
        $sub = (new ActiveQuery())
            ->from('orders')
            ->alias('o')
            ->selectRaw('1')
            ->whereRaw('{user_id} = {u.id}');

        $sql = (new ActiveQuery())
            ->from('users')
            ->alias('u')
            ->notExists($sub)
            ->getSQL();

        $this->assertStringContainsString('FROM `users` `u`', $sql);
        $this->assertStringContainsString('FROM `orders` `o`', $sql);
        $this->assertStringContainsString('`o`.`user_id` = `u`.`id`', $sql);
    }

    #[Test]
    public function selectUsesAlias(): void
    {
        $sql = (new ActiveQuery())
            ->from('user')
            ->alias('c')
            ->select('id', 'name')
            ->getSQL();

        $this->assertStringContainsString('SELECT `c`.`id`, `c`.`name`', $sql);
    }
}
