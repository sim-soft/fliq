<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;

/**
 * Unit tests for EXISTS / NOT EXISTS conditions.
 */
class ExistsConditionTest extends TestCase
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
    public function existsWithSubquery(): void
    {
        $sub = (new ActiveQuery())
            ->from('orders')
            ->selectRaw('1')
            ->whereRaw('{user_id} = {user.id}');

        $sql = (new ActiveQuery())
            ->from('user')
            ->exists($sub)
            ->getSQL();

        $this->assertStringContainsString('EXISTS (SELECT 1 FROM `orders`', $sql);
        $this->assertStringContainsString('`orders`.`user_id` = `user`.`id`', $sql);
    }

    #[Test]
    public function notExistsWithSubquery(): void
    {
        $sub = (new ActiveQuery())
            ->from('orders')
            ->selectRaw('1')
            ->whereRaw('{user_id} = {user.id}');

        $sql = (new ActiveQuery())
            ->from('user')
            ->notExists($sub)
            ->getSQL();

        $this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM `orders`', $sql);
    }

    #[Test]
    public function existsWithAlias(): void
    {
        $sub = (new ActiveQuery())
            ->from('comments')
            ->alias('c')
            ->selectRaw('1')
            ->whereRaw('{author_id} = {u.id}');

        $sql = (new ActiveQuery())
            ->from('user')
            ->alias('u')
            ->exists($sub)
            ->getSQL();

        $this->assertStringContainsString('FROM `user` `u`', $sql);
        $this->assertStringContainsString('FROM `comments` `c`', $sql);
        $this->assertStringContainsString('`c`.`author_id` = `u`.`id`', $sql);
    }

    #[Test]
    public function orExistsJoinsWithOr(): void
    {
        $sub = (new ActiveQuery())
            ->from('orders')
            ->selectRaw('1')
            ->whereRaw('{user_id} = {user.id}');

        $sql = (new ActiveQuery())
            ->from('user')
            ->where('role', 'admin')
            ->orExists($sub)
            ->getSQL();

        $this->assertStringContainsString('OR EXISTS', $sql);
    }

    #[Test]
    public function orNotExistsJoinsWithOr(): void
    {
        $sub = (new ActiveQuery())
            ->from('blacklist')
            ->selectRaw('1')
            ->whereRaw('{email} = {user.email}');

        $sql = (new ActiveQuery())
            ->from('user')
            ->where('status', 'active')
            ->orNotExists($sub)
            ->getSQL();

        $this->assertStringContainsString('OR NOT EXISTS', $sql);
    }

    #[Test]
    public function existsWithRawQuery(): void
    {
        $raw = new Raw('SELECT 1 FROM orders WHERE orders.user_id = user.id');

        $sql = (new ActiveQuery())
            ->from('user')
            ->exists($raw)
            ->getSQL();

        $this->assertStringContainsString('EXISTS (SELECT 1 FROM orders WHERE orders.user_id = user.id)', $sql);
    }
}
