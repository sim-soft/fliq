<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\IndexAdvisor;
use Simsoft\DB\QueryLogger;

/**
 * Integration tests for IndexAdvisor.
 */
class IndexAdvisorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueryLogger::enable();
        QueryLogger::reset();
    }

    protected function tearDown(): void
    {
        QueryLogger::disable();
        QueryLogger::reset();
    }

    #[Test]
    public function suggestReturnsEmptyWhenNoQueries(): void
    {
        $suggestions = IndexAdvisor::suggest();
        $this->assertEmpty($suggestions);
    }

    #[Test]
    public function suggestDetectsWhereClauseColumns(): void
    {
        $this->assertTrue(QueryLogger::isEnabled(), 'QueryLogger should be enabled');

        // Use first() which directly executes the SELECT query
        $user = User::find()->where('user.score', '>', 50)->first();
        $this->assertNotNull($user, 'Query should return a result');

        $queries = QueryLogger::getQueries();
        $this->assertNotEmpty($queries, 'Expected at least one logged query');

        $suggestions = IndexAdvisor::suggest();

        $this->assertNotEmpty($suggestions, 'Expected suggestions');

        $found = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['table'] === 'user' && in_array('score', $suggestion['columns'])) {
                $found = true;
                $this->assertEquals('Used in WHERE clause', $suggestion['reason']);
            }
        }
        $this->assertTrue($found, 'Expected suggestion for user.score in WHERE clause');
    }

    #[Test]
    public function suggestDetectsOrderByColumns(): void
    {
        // Execute query directly (without LIMIT) to test ORDER BY detection
        $query = User::find()->orderBy('user.username', 'DESC');
        $results = iterator_to_array($query->all());
        $this->assertNotEmpty($results, 'Query should return results');

        $queries = QueryLogger::getQueries();
        $this->assertNotEmpty($queries, 'Expected logged queries');

        $suggestions = IndexAdvisor::suggest();

        $found = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['table'] === 'user'
                && in_array('username', $suggestion['columns'])
                && $suggestion['reason'] === 'Used in ORDER BY without LIMIT'
            ) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected suggestion for user.username in ORDER BY without LIMIT');
    }

    #[Test]
    public function suggestDoesNotFlagOrderByWithLimit(): void
    {
        QueryLogger::reset();

        // Query with ORDER BY and LIMIT — not a full scan
        User::find()->orderBy('user.username', 'DESC')->limit(5)->first();

        $suggestions = IndexAdvisor::suggest();

        $found = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['reason'] === 'Used in ORDER BY without LIMIT'
                && $suggestion['table'] === 'user'
                && in_array('username', $suggestion['columns'])
            ) {
                $found = true;
            }
        }
        $this->assertFalse($found, 'Should not suggest ORDER BY index when LIMIT is present');
    }

    #[Test]
    public function suggestDetectsJoinColumns(): void
    {
        // Use all() to execute the query directly
        $results = iterator_to_array(
            User::find()->join('user_profile', ['user_id' => 'id'])->all()
        );
        $this->assertNotEmpty($results, 'Query should return results');

        $suggestions = IndexAdvisor::suggest();

        $found = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['table'] === 'user_profile'
                && $suggestion['reason'] === 'Used in JOIN condition'
            ) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Expected suggestion for JOIN condition on user_profile');
    }

    #[Test]
    public function suggestDeduplicatesIdenticalSuggestions(): void
    {
        // Run the same query pattern twice using first()
        User::find()->where('user.score', '>', 50)->first();
        User::find()->where('user.score', '>', 80)->first();

        $suggestions = IndexAdvisor::suggest();

        // Should only have one suggestion for user.score WHERE clause
        $whereScoreCount = 0;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['table'] === 'user'
                && $suggestion['columns'] === ['score']
                && $suggestion['reason'] === 'Used in WHERE clause'
            ) {
                $whereScoreCount++;
            }
        }
        $this->assertEquals(1, $whereScoreCount, 'Duplicate suggestions should be deduplicated');
    }

    #[Test]
    public function suggestSqlReturnsCreateIndexStatements(): void
    {
        User::find()->where('user.score', '>', 50)->first();

        $statements = IndexAdvisor::suggestSQL();

        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('CREATE INDEX', $statements[0]);
        $this->assertStringContainsString('user', $statements[0]);
        $this->assertStringContainsString('score', $statements[0]);
    }

    #[Test]
    public function suggestSqlReturnsEmptyWhenNoSuggestions(): void
    {
        $statements = IndexAdvisor::suggestSQL();
        $this->assertEmpty($statements);
    }
}
