<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Grammar\Grammar;
use Simsoft\DB\Grammar\MySQLGrammar;
use Simsoft\DB\Grammar\PostgresGrammar;
use Simsoft\DB\Grammar\SQLiteGrammar;

/**
 * ActiveQuery's JSON methods carry the path inside the column string, so
 * jsonContains('meta') leaves the path empty. That empty path means the
 * document root, and the three grammars disagreed about it: MySQL and SQLite
 * emitted '$', while PostgreSQL navigated to a key named '' and matched
 * nothing.
 *
 * Key existence is the exception — the root is not a key, so there is nothing
 * to test for and the call is refused.
 */
class JsonRootPathTest extends TestCase
{
    /**
     * @return array<string, array{0: Grammar}>
     */
    public static function grammars(): array
    {
        return [
            'mysql' => [new MySQLGrammar()],
            'pgsql' => [new PostgresGrammar()],
            'sqlite' => [new SQLiteGrammar()],
        ];
    }

    #[Test]
    #[DataProvider('grammars')]
    public function keyExistenceRejectsAnEmptyPath(Grammar $grammar): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON key path is required');

        $grammar->jsonKeyExists('`meta`', '');
    }

    #[Test]
    #[DataProvider('grammars')]
    public function keyExistenceRejectsAWhitespacePath(Grammar $grammar): void
    {
        $this->expectException(InvalidArgumentException::class);

        $grammar->jsonKeyExists('`meta`', '   ');
    }

    /**
     * The message has to say what to write instead, since the caller wrote a
     * column name and the path is not visibly a separate argument.
     */
    #[Test]
    #[DataProvider('grammars')]
    public function keyExistenceErrorNamesTheDriverAndTheFix(Grammar $grammar): void
    {
        try {
            $grammar->jsonKeyExists('`meta`', '');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($grammar->getDriverName(), $e->getMessage());
            $this->assertStringContainsString("'column->key'", $e->getMessage());
        }
    }

    #[Test]
    #[DataProvider('grammars')]
    public function keyExistenceStillBuildsForARealPath(Grammar $grammar): void
    {
        $this->assertNotSame('', $grammar->jsonKeyExists('`meta`', 'priority'));
    }

    /**
     * The defect that made the empty path dangerous rather than merely broken:
     * MySQL built the path '$.', which the server rejects, but PostgreSQL
     * built valid SQL asking a different question.
     */
    #[Test]
    #[DataProvider('grammars')]
    public function noGrammarEmitsATrailingDotPath(Grammar $grammar): void
    {
        $sql = $grammar->jsonKeyExists('`meta`', 'priority');

        $this->assertStringNotContainsString("'\$.'", $sql);
        $this->assertStringNotContainsString(".'", str_replace("'\$.priority'", '', $sql));
    }

    /**
     * Containment, extraction and length all have a meaningful root form, and
     * each grammar must express it rather than navigate to an empty key.
     */
    #[Test]
    #[DataProvider('grammars')]
    public function containmentAtTheRootDoesNotNavigateToAnEmptyKey(Grammar $grammar): void
    {
        $sql = $grammar->jsonContains('`meta`', '');

        $this->assertStringNotContainsString("''", $sql);
        $this->assertStringNotContainsString("-> ''", $sql);
    }

    #[Test]
    #[DataProvider('grammars')]
    public function extractionAtTheRootDoesNotNavigateToAnEmptyKey(Grammar $grammar): void
    {
        foreach ([true, false] as $asText) {
            $sql = $grammar->jsonExtract('`meta`', '', $asText);
            $this->assertStringNotContainsString("-> ''", $sql);
            $this->assertStringNotContainsString("->> ''", $sql);
        }
    }

    #[Test]
    #[DataProvider('grammars')]
    public function lengthAtTheRootDoesNotNavigateToAnEmptyKey(Grammar $grammar): void
    {
        $sql = $grammar->jsonLength('`meta`', '');

        $this->assertStringNotContainsString("-> ''", $sql);
    }

    /**
     * PostgreSQL specifically: these are the forms checked against the server,
     * and each was confirmed to give the same answer as its MySQL counterpart.
     */
    #[Test]
    public function postgresUsesItsDocumentRootForms(): void
    {
        $grammar = new PostgresGrammar();

        $this->assertSame('"meta" @> ?::jsonb', $grammar->jsonContains('"meta"', ''));
        $this->assertSame('"meta"', $grammar->jsonExtract('"meta"', '', false));
        $this->assertSame('"meta" #>> \'{}\'', $grammar->jsonExtract('"meta"', '', true));
        $this->assertSame('jsonb_array_length("meta")', $grammar->jsonLength('"meta"', ''));
    }

    /**
     * MySQL and SQLite already handled the root correctly in these three; the
     * fix must not have disturbed them.
     */
    #[Test]
    public function mysqlAndSqliteStillUseDollarForTheRoot(): void
    {
        $mysql = new MySQLGrammar();
        $sqlite = new SQLiteGrammar();

        $this->assertStringContainsString("'\$'", $mysql->jsonContains('`meta`', ''));
        $this->assertStringContainsString("'\$'", $mysql->jsonLength('`meta`', ''));
        $this->assertStringContainsString("'\$'", $sqlite->jsonContains('`meta`', ''));
        $this->assertStringContainsString("'\$'", $sqlite->jsonLength('`meta`', ''));
    }

    /**
     * A path with a key must keep working unchanged on every grammar — the
     * root branch is an addition, not a replacement.
     */
    #[Test]
    #[DataProvider('grammars')]
    public function aKeyedPathIsUnaffected(Grammar $grammar): void
    {
        $this->assertStringContainsString('tags', $grammar->jsonContains('`meta`', 'tags'));
        $this->assertStringContainsString('tags', $grammar->jsonLength('`meta`', 'tags'));
        $this->assertStringContainsString('tags', $grammar->jsonExtract('`meta`', 'tags'));
    }

    #[Test]
    #[DataProvider('grammars')]
    public function aNestedPathIsUnaffected(Grammar $grammar): void
    {
        foreach ([
            $grammar->jsonContains('`meta`', 'address.city'),
            $grammar->jsonExtract('`meta`', 'address.city'),
            $grammar->jsonKeyExists('`meta`', 'address.city'),
        ] as $sql) {
            $this->assertStringContainsString('city', $sql);
        }
    }
}
