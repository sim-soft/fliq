<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Connection;
use Simsoft\DB\Grammar\PostgresGrammar;

/**
 * The PostgreSQL grammar's dialect-specific renderings.
 *
 * Each expression here was verified to run against a live PostgreSQL 14.5
 * server; these tests pin the SQL the grammar produces for it.
 */
class PostgresGrammarTest extends TestCase
{
    private PostgresGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new PostgresGrammar();
        Connection::reset();
        Connection::add('pgg', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'x']);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /** @return array<string, array{int, int|null, string}> */
    public static function limits(): array
    {
        return [
            'no offset' => [10, null, 'LIMIT 10'],
            'zero offset' => [10, 0, 'LIMIT 10'],
            'positive offset' => [10, 5, 'LIMIT 10 OFFSET 5'],
            'negative offset' => [10, -1, 'LIMIT 10'],
        ];
    }

    #[Test]
    #[DataProvider('limits')]
    public function anOffsetIsAppendedOnlyWhenItSkipsRows(int $limit, ?int $offset, string $expected): void
    {
        $this->assertSame($expected, $this->grammar->limitSQL($limit, $offset));
    }

    #[Test]
    public function theBuilderPagesWithLimitAndOffset(): void
    {
        $query = new ActiveQuery()->from('user')->select('id')->withConnection('pgg')->limit(3)->offset(2);

        $this->assertStringEndsWith('LIMIT 3 OFFSET 2', $query->getSQL());
    }

    #[Test]
    public function insertIgnoreIsAPlainInsertBecauseTheConflictClauseCarriesIt(): void
    {
        // PostgreSQL has no INSERT IGNORE keyword; the suppression is expressed
        // as ON CONFLICT DO NOTHING, which insertIgnoreFullSQL() renders. The
        // keyword method must therefore stay a bare INSERT rather than emitting
        // something the server would reject.
        $this->assertSame('INSERT', $this->grammar->insertIgnoreSQL());
    }

    #[Test]
    public function anIgnoredInsertUsesTheConflictClause(): void
    {
        $insert = new Insert('t', ['a' => 1]);
        $insert->withConnection('pgg')->ignore();

        $sql = $insert->getSQL();
        $this->assertStringContainsString('ON CONFLICT DO NOTHING', $sql);
        $this->assertStringNotContainsString('IGNORE', $sql);
    }

    /** @return array<string, array{string, string}> */
    public static function jsonPaths(): array
    {
        return [
            'root' => ['', 'jsonb_array_length("doc")'],
            'single key' => ['tags', 'jsonb_array_length("doc" -> \'tags\')'],
            'nested' => ['meta.tags', 'jsonb_array_length("doc" -> \'meta\' -> \'tags\')'],
            'deeply nested' => ['a.b.c', 'jsonb_array_length("doc" -> \'a\' -> \'b\' -> \'c\')'],
        ];
    }

    #[Test]
    #[DataProvider('jsonPaths')]
    public function jsonLengthWalksEverySegmentOfThePath(string $path, string $expected): void
    {
        $this->assertSame($expected, $this->grammar->jsonLength('"doc"', $path));
    }

    /** @return array<string, array{string, string}> */
    public static function lockTypes(): array
    {
        return [
            'update' => ['update', 'FOR UPDATE'],
            'share' => ['share', 'FOR SHARE'],
            'no wait' => ['noWait', 'FOR UPDATE NOWAIT'],
            'skip locked' => ['skipLocked', 'FOR UPDATE SKIP LOCKED'],
            'unknown falls back' => ['bogus', 'FOR UPDATE'],
        ];
    }

    #[Test]
    #[DataProvider('lockTypes')]
    public function everyLockTypeHasARendering(string $type, string $expected): void
    {
        $this->assertSame($expected, $this->grammar->lockSQL($type));
    }

    /** @return array<string, array{string, non-empty-string}> */
    public static function lockMethods(): array
    {
        return [
            'forUpdate' => ['forUpdate', 'FOR UPDATE'],
            'forShare' => ['forShare', 'FOR SHARE'],
            'forUpdateNoWait' => ['forUpdateNoWait', 'FOR UPDATE NOWAIT'],
            'forUpdateSkipLocked' => ['forUpdateSkipLocked', 'FOR UPDATE SKIP LOCKED'],
        ];
    }

    /**
     * @param string $method The builder method to call.
     * @param non-empty-string $expected The lock clause it must produce.
     */
    #[Test]
    #[DataProvider('lockMethods')]
    public function theBuilderLockMethodsReachTheGrammar(string $method, string $expected): void
    {
        $query = new ActiveQuery()->from('user')->select('id')->where('id', 1)->withConnection('pgg');
        $query->$method();

        $this->assertStringEndsWith($expected, $query->getSQL());
    }

    #[Test]
    public function aQueryWithoutALockAsksForNone(): void
    {
        $query = new ActiveQuery()->from('user')->select('id')->withConnection('pgg');

        $this->assertStringNotContainsString('FOR UPDATE', $query->getSQL());
        $this->assertStringNotContainsString('FOR SHARE', $query->getSQL());
    }

    #[Test]
    public function fulltextIsSupported(): void
    {
        $this->assertTrue($this->grammar->supportsFulltext());
    }

    #[Test]
    public function aFulltextConditionUsesTheTextSearchOperator(): void
    {
        $query = new ActiveQuery()->from('post')->select('id')->withConnection('pgg');
        $query->whereFulltext(['title'], 'lorem');

        $this->assertStringContainsString('to_tsvector', $query->getSQL());
    }
}
