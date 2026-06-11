<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Conditions\MatchAgainst;
use Simsoft\DB\Connection;

/**
 * Unit tests for MatchAgainst full-text search condition.
 */
class MatchAgainstTest extends TestCase
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
    public function searchPassesExpressionAsIs(): void
    {
        $match = (new MatchAgainst(['title', 'body']))
            ->search('+laravel -wordpress "service container" php*')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        $this->assertStringContainsString('MATCH(', $sql);
        $this->assertStringContainsString('AGAINST (? IN BOOLEAN MODE)', $sql);
        $this->assertNotNull($binds);
        $this->assertSame('+laravel -wordpress "service container" php*', $binds[0]);
    }

    #[Test]
    public function searchOverridesPreviousWords(): void
    {
        $match = (new MatchAgainst(['title']))
            ->mustHave(['php'])
            ->search('custom expression')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $binds = $query->getBinds();

        // search() replaces all previous words
        $this->assertNotNull($binds);
        $this->assertSame('custom expression', $binds[0]);
    }

    #[Test]
    public function searchWithNaturalLanguageMode(): void
    {
        $match = (new MatchAgainst(['content']))
            ->search('database optimization techniques')
            ->naturalLanguageMode();

        $query = (new ActiveQuery())
            ->from('articles')
            ->where($match);

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        $this->assertStringContainsString('AGAINST (? IN NATURAL LANGUAGE MODE)', $sql);
        $this->assertNotNull($binds);
        $this->assertSame('database optimization techniques', $binds[0]);
    }

    #[Test]
    public function mustHaveBuildsCorrectExpression(): void
    {
        $match = (new MatchAgainst(['title', 'body']))
            ->mustHave(['php', 'mysql'])
            ->mustNot(['java'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $binds = $query->getBinds();

        // 'php' is ≤3 chars so no + prefix; 'mysql' gets +; 'java' gets -
        $this->assertNotNull($binds);
        $this->assertSame('php +mysql -java', $binds[0]);
    }

    #[Test]
    public function wildcardBuildsSuffixedWords(): void
    {
        $match = (new MatchAgainst(['name']))
            ->wildcard(['micro', 'soft'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('products')
            ->where($match);

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('micro* soft*', $binds[0]);
    }

    #[Test]
    public function containsWrapsInQuotes(): void
    {
        $match = (new MatchAgainst(['body']))
            ->contains(['dependency injection', 'service container'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('articles')
            ->where($match);

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('"dependency injection" "service container"', $binds[0]);
    }

    #[Test]
    public function negationPrefixesTilde(): void
    {
        $match = (new MatchAgainst(['title']))
            ->negation(['wordpress'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('~wordpress', $binds[0]);
    }

    #[Test]
    public function singleColumnMatch(): void
    {
        $match = (new MatchAgainst('title'))
            ->search('php')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $sql = $query->getSQL();

        $this->assertStringContainsString('MATCH(`posts`.`title`)', $sql);
    }

    #[Test]
    public function multipleColumnsMatch(): void
    {
        $match = (new MatchAgainst(['title', 'body', 'tags']))
            ->search('php')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $sql = $query->getSQL();

        $this->assertStringContainsString('MATCH(`posts`.`title`, `posts`.`body`, `posts`.`tags`)', $sql);
    }
}
