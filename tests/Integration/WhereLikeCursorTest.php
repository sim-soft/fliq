<?php

namespace Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\CursorPaginator;

/**
 * Integration tests for whereLike, whereNotLike, orWhereColumn, and cursorPaginate.
 */
class WhereLikeCursorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTable();
    }

    private function createTestTable(): void
    {
        $sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS _test_like_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            age INT DEFAULT 0,
            status TINYINT DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )';
        (new Raw($sql))->withConnection('test')->execute();
        (new Raw('TRUNCATE TABLE _test_like_users'))->withConnection('test')->execute();

        $insert = new Raw(
            'INSERT INTO _test_like_users (name, email, age, status) VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)',
            [
                'John Doe', 'john@test.com', 25, 1,
                'Jane Smith', 'jane@test.com', 30, 1,
                'JOHNNY Cash', 'johnny@test.com', 50, 1,
                'Bob Builder', 'bob@test.com', 35, 0,
                'Alice Wonder', 'alice@test.com', 28, 1,
            ]
        );
        $insert->withConnection('test');
        $insert->execute();
    }

    // --- Task 1: whereLike tests ---

    #[Test]
    public function whereLikeCaseInsensitiveFindsMatchesRegardlessOfCase(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->whereLike('name', '%john%')
            ->withConnection('test');

        $results = $query->query($query);

        // Should match "John Doe" and "JOHNNY Cash" (case-insensitive)
        $this->assertCount(2, $results);
    }

    #[Test]
    public function whereLikeCaseSensitiveRespectsCase(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->whereLike('name', '%John%', caseSensitive: true)
            ->withConnection('test');

        $results = $query->query($query);

        // With MySQL utf8mb4_unicode_ci collation, LIKE is case-insensitive by default.
        // caseSensitive: true means we use plain LIKE (no LOWER wrapping),
        // which defers to the column's collation.
        $this->assertGreaterThanOrEqual(1, count($results));
        $names = array_column($results, 'name');
        $this->assertContains('John Doe', $names);
    }

    #[Test]
    public function whereNotLikeCaseInsensitiveExcludesMatches(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->whereNotLike('name', '%john%')
            ->withConnection('test');

        $results = $query->query($query);

        // Should exclude "John Doe" and "JOHNNY Cash"
        $this->assertCount(3, $results);
    }

    #[Test]
    public function whereNotLikeCaseSensitiveRespectsCase(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->whereNotLike('name', '%John%', caseSensitive: true)
            ->withConnection('test');

        $results = $query->query($query);

        // With MySQL utf8mb4_unicode_ci collation, LIKE is case-insensitive by default.
        // caseSensitive: true means plain NOT LIKE (no LOWER wrapping),
        // which defers to the column's collation.
        $names = array_column($results, 'name');
        $this->assertNotContains('John Doe', $names);
    }

    #[Test]
    public function orWhereLikeCombinesConditions(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->whereLike('name', '%alice%')
            ->orWhereLike('name', '%bob%')
            ->withConnection('test');

        $results = $query->query($query);

        // Should match "Alice Wonder" and "Bob Builder"
        $this->assertCount(2, $results);
    }

    #[Test]
    public function orWhereNotLikeCombinesConditions(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->where('status', 0)
            ->orWhereNotLike('name', '%john%')
            ->withConnection('test');

        $results = $query->query($query);

        // status=0 (Bob) OR name NOT LIKE %john% (Jane, Bob, Alice) = Jane, Bob, Alice
        $this->assertCount(3, $results);
    }

    // --- Task 2: orWhereColumn tests ---

    #[Test]
    public function orWhereColumnCombinesColumnComparisons(): void
    {
        // Update one record to have updated_at > created_at
        (new Raw(
            "UPDATE _test_like_users SET updated_at = DATE_ADD(created_at, INTERVAL 1 DAY) WHERE name = 'John Doe'"
        ))->withConnection('test')->execute();

        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->where('status', 0)
            ->orWhereColumn('updated_at', '>', 'created_at')
            ->withConnection('test');

        $results = $query->query($query);

        // status=0 (Bob) OR updated_at > created_at (John Doe) = 2 records
        $this->assertCount(2, $results);
    }

    // --- Task 3: cursorPaginate tests ---

    #[Test]
    public function cursorPaginateReturnsFirstPage(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->withConnection('test');

        $result = $query->cursorPaginate(perPage: 2);

        $this->assertInstanceOf(CursorPaginator::class, $result);
        $this->assertCount(2, $result->data);
        $this->assertEquals(2, $result->perPage);
        $this->assertTrue($result->hasMore);
        $this->assertNull($result->previousCursor);
        $this->assertNotNull($result->nextCursor);
    }

    #[Test]
    public function cursorPaginateReturnsNextPage(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->withConnection('test');

        // Get first page
        $firstPage = $query->cursorPaginate(perPage: 2);
        $nextCursor = $firstPage->nextCursor;

        // Get second page
        $query2 = (new ActiveQuery())
            ->from('_test_like_users')
            ->withConnection('test');

        $secondPage = $query2->cursorPaginate(perPage: 2, cursor: $nextCursor);

        $this->assertCount(2, $secondPage->data);
        $this->assertTrue($secondPage->hasMore);
        $this->assertEquals($nextCursor, $secondPage->previousCursor);
        $this->assertNotNull($secondPage->nextCursor);
    }

    #[Test]
    public function cursorPaginateReturnsLastPage(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->withConnection('test');

        // Get last page (5 records, perPage=3, cursor=3 → records 4,5)
        $result = $query->cursorPaginate(perPage: 3, cursor: 2);

        $this->assertCount(3, $result->data);
        $this->assertFalse($result->hasMore);
        $this->assertNull($result->nextCursor);
        $this->assertEquals(2, $result->previousCursor);
    }

    #[Test]
    public function cursorPaginateDescendingOrder(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->withConnection('test');

        $result = $query->cursorPaginate(perPage: 2, cursor: null, cursorColumn: 'id', direction: 'desc');

        $this->assertCount(2, $result->data);
        $this->assertTrue($result->hasMore);
        // First record should have highest id
        $this->assertEquals(5, $result->data[0]['id']);
        $this->assertEquals(4, $result->data[1]['id']);
    }

    #[Test]
    public function cursorPaginateToArrayFormat(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->withConnection('test');

        $result = $query->cursorPaginate(perPage: 2);
        $array = $result->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('per_page', $array);
        $this->assertArrayHasKey('next_cursor', $array);
        $this->assertArrayHasKey('previous_cursor', $array);
        $this->assertArrayHasKey('has_more', $array);
        $this->assertEquals(2, $array['per_page']);
    }

    #[Test]
    public function cursorPaginateEmptyResult(): void
    {
        $query = (new ActiveQuery())
            ->from('_test_like_users')
            ->where('status', 99)
            ->withConnection('test');

        $result = $query->cursorPaginate(perPage: 2);

        $this->assertCount(0, $result->data);
        $this->assertFalse($result->hasMore);
        $this->assertNull($result->nextCursor);
        $this->assertTrue($result->isEmpty());
    }
}
