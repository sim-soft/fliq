<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Connection;
use Simsoft\DB\IndexAdvisor;
use Simsoft\DB\QueryLogger;

/**
 * Tests IndexAdvisor grammar-aware SQL generation.
 */
class IndexAdvisorTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        QueryLogger::reset();
    }

    protected function tearDown(): void
    {
        Connection::reset();
        QueryLogger::reset();
        QueryLogger::disable();
    }

    #[Test]
    public function suggestSQLUsesMySQLQuoting(): void
    {
        Connection::add('mysql', ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'username' => 'root', 'password' => '']);

        QueryLogger::enable();
        $start = microtime(true);
        QueryLogger::logQuery('SELECT * FROM user WHERE user.status = ? AND user.role = ?', [1, 'admin'], $start);

        $statements = IndexAdvisor::suggestSQL('mysql');

        $this->assertNotEmpty($statements);
        // MySQL uses backticks
        $this->assertStringContainsString('`', $statements[0]);
    }

    #[Test]
    public function suggestSQLUsesPostgresQuoting(): void
    {
        Connection::add('pg', ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'test', 'username' => 'postgres', 'password' => '']);

        QueryLogger::enable();
        $start = microtime(true);
        QueryLogger::logQuery('SELECT * FROM user WHERE user.status = ? AND user.role = ?', [1, 'admin'], $start);

        $statements = IndexAdvisor::suggestSQL('pg');

        $this->assertNotEmpty($statements);
        // PostgreSQL uses double quotes
        $this->assertStringContainsString('"', $statements[0]);
        $this->assertStringNotContainsString('`', $statements[0]);
    }

    #[Test]
    public function suggestSQLUsesSQLiteQuoting(): void
    {
        Connection::add('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        QueryLogger::enable();
        $start = microtime(true);
        QueryLogger::logQuery('SELECT * FROM user WHERE user.email = ?', ['test@test.com'], $start);

        $statements = IndexAdvisor::suggestSQL('sqlite');

        $this->assertNotEmpty($statements);
        // SQLite uses double quotes
        $this->assertStringContainsString('"', $statements[0]);
    }

    #[Test]
    public function suggestReturnsEmptyWhenNoQueries(): void
    {
        $suggestions = IndexAdvisor::suggest();
        $this->assertEmpty($suggestions);
    }

    #[Test]
    public function suggestDetectsWhereColumns(): void
    {
        QueryLogger::enable();
        $start = microtime(true);
        QueryLogger::logQuery('SELECT * FROM user WHERE user.status = ? AND user.email = ?', [1, 'test@test.com'], $start);

        $suggestions = IndexAdvisor::suggest();

        $this->assertNotEmpty($suggestions);
        $found = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['table'] === 'user' && in_array('status', $suggestion['columns'])) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should suggest index on user.status');
    }

    #[Test]
    public function suggestDetectsJoinColumns(): void
    {
        QueryLogger::enable();
        $start = microtime(true);
        QueryLogger::logQuery('SELECT * FROM user INNER JOIN post ON post.user_id = user.id WHERE user.status = ?', [1], $start);

        $suggestions = IndexAdvisor::suggest();

        $joinSuggestion = null;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['table'] === 'post' && $suggestion['reason'] === 'Used in JOIN condition') {
                $joinSuggestion = $suggestion;
                break;
            }
        }
        $this->assertNotNull($joinSuggestion);
        $this->assertContains('user_id', $joinSuggestion['columns']);
    }
}
