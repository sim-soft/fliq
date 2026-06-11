<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Exceptions\QueryException;

/**
 * Unit tests for merge() and orMerge() query operations.
 */
class MergeQueryTest extends TestCase
{
    #[Test]
    public function mergeWithAndCombinesConditions(): void
    {
        $baseQuery = (new ActiveQuery())->from('user')->where('status', 'active');
        $ageQuery = (new ActiveQuery())->from('user')->where('age', '>', 18);

        $baseQuery->merge($ageQuery);
        $sql = $baseQuery->getSQL();

        $this->assertStringContainsString('`user`.`status` = ?', $sql);
        $this->assertStringContainsString('AND', $sql);
        $this->assertStringContainsString('`user`.`age` > ?', $sql);
    }

    #[Test]
    public function orMergeCombinesWithOr(): void
    {
        $admins = (new ActiveQuery())->from('user')->where('role', 'admin');
        $verified = (new ActiveQuery())->from('user')->where('verified', 1);

        $admins->orMerge($verified);
        $sql = $admins->getSQL();

        $this->assertStringContainsString('`user`.`role` = ?', $sql);
        $this->assertStringContainsString('OR', $sql);
        $this->assertStringContainsString('`user`.`verified` = ?', $sql);
    }

    #[Test]
    public function mergeBindsParameters(): void
    {
        $baseQuery = (new ActiveQuery())->from('user')->where('status', 'active');
        $ageQuery = (new ActiveQuery())->from('user')->where('age', '>', 18);

        $baseQuery->merge($ageQuery);
        $binds = $baseQuery->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('active', $binds[0]);
        $this->assertSame(18, $binds[1]);
    }

    #[Test]
    public function mergeDifferentTableThrowsException(): void
    {
        $userQuery = (new ActiveQuery())->from('user')->where('status', 'active');
        $postQuery = (new ActiveQuery())->from('post')->where('published', 1);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Cannot merge queries from different tables");

        $userQuery->merge($postQuery);
    }

    #[Test]
    public function orMergeDifferentTableThrowsException(): void
    {
        $userQuery = (new ActiveQuery())->from('user')->where('role', 'admin');
        $orderQuery = (new ActiveQuery())->from('order')->where('total', '>', 100);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Cannot merge queries from different tables");

        $userQuery->orMerge($orderQuery);
    }

    #[Test]
    public function mergeSameTableSucceeds(): void
    {
        $query1 = (new ActiveQuery())->from('user')->where('status', 'active');
        $query2 = (new ActiveQuery())->from('user')->where('role', 'admin');

        $result = $query1->merge($query2);

        // Returns self for chaining
        $this->assertSame($query1, $result);
    }
}
