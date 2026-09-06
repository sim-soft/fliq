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
 * Three full-text search modes are supported; anything else used to fall
 * through to the plain branch in silence.
 *
 * That is not a wording difference. On MySQL a caller asking for 'boolean' —
 * MySQL's own name for the mode — was answered IN NATURAL LANGUAGE MODE, where
 * '+' and '-' are stripped as punctuation, so '+database -systems' matched
 * every row instead of the two that satisfy it. On PostgreSQL the same request
 * was answered by plainto_tsquery, which ANDs every word and ignores the
 * operators. Nothing in either result said the mode had been ignored.
 */
class FulltextModeTest extends TestCase
{
    /** @return array<string, array{0: Grammar}> */
    public static function grammarProvider(): array
    {
        return [
            'mysql' => [new MySQLGrammar()],
            'postgres' => [new PostgresGrammar()],
            'sqlite' => [new SQLiteGrammar()],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function unsupportedModeProvider(): array
    {
        return [
            // MySQL's own name for what this library calls 'websearch'. The
            // most likely thing a caller reaches for, and the most damaging to
            // answer in plain mode.
            'boolean' => ['boolean'],
            'natural' => ['natural'],
            'typo' => ['websarch'],
            'empty' => [''],
            'plural' => ['phrases'],
        ];
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function everySupportedModeBuilds(Grammar $grammar): void
    {
        foreach (['plain', 'phrase', 'websearch'] as $mode) {
            $this->assertNotSame(
                '',
                $grammar->fulltextSearch(['body'], $mode),
                "$mode should build on " . $grammar->getDriverName()
            );
        }
    }

    #[Test]
    #[DataProvider('unsupportedModeProvider')]
    public function mysqlRefusesAnUnsupportedMode(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported full-text search mode '$mode' for mysql");

        (new MySQLGrammar())->fulltextSearch(['body'], $mode);
    }

    #[Test]
    #[DataProvider('unsupportedModeProvider')]
    public function postgresRefusesAnUnsupportedMode(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported full-text search mode '$mode' for pgsql");

        (new PostgresGrammar())->fulltextSearch(['body'], $mode);
    }

    #[Test]
    #[DataProvider('unsupportedModeProvider')]
    public function sqliteRefusesAnUnsupportedMode(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported full-text search mode '$mode' for sqlite");

        (new SQLiteGrammar())->fulltextSearch(['body'], $mode);
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function theMessageNamesTheSupportedModes(Grammar $grammar): void
    {
        try {
            $grammar->fulltextSearch(['body'], 'boolean');
            $this->fail('An unsupported mode should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('plain, phrase, websearch', $e->getMessage());
            // 'boolean' is the name a MySQL user reaches for, so the message
            // points at the mode that actually reads +, - and " as operators.
            $this->assertStringContainsString("use 'websearch'", $e->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function caseProvider(): array
    {
        return [
            'upper' => ['PHRASE', 'phrase'],
            'mixed' => ['WebSearch', 'websearch'],
            'padded' => ['  plain  ', 'plain'],
            'padded and upper' => [" \tWEBSEARCH\n", 'websearch'],
        ];
    }

    #[Test]
    #[DataProvider('caseProvider')]
    public function caseAndPaddingAreNormalisedNotRefused(string $given, string $canonical): void
    {
        // 'Phrase' names the phrase mode as plainly as 'phrase' does, and the
        // EXPLAIN formats already normalise the same way.
        foreach (self::grammarProvider() as $row) {
            $grammar = $row[0];
            $this->assertSame(
                $grammar->fulltextSearch(['body'], $canonical),
                $grammar->fulltextSearch(['body'], $given),
                'case should not change the SQL on ' . $grammar->getDriverName()
            );
        }
    }

    #[Test]
    public function mysqlUsesBooleanModeOnlyForWebsearchAndPhrase(): void
    {
        $grammar = new MySQLGrammar();

        // websearch is the mode that reads + and - as operators.
        $this->assertStringContainsString(
            'IN BOOLEAN MODE',
            $grammar->fulltextSearch(['`body`'], 'websearch')
        );
        // plain must not, or an ordinary term like 'C++ database' is a syntax
        // error and 'database -systems' silently excludes.
        $this->assertStringContainsString(
            'IN NATURAL LANGUAGE MODE',
            $grammar->fulltextSearch(['`body`'], 'plain')
        );
    }

    #[Test]
    public function postgresPicksTheQueryFunctionPerMode(): void
    {
        $grammar = new PostgresGrammar();

        $this->assertStringContainsString(
            'plainto_tsquery',
            $grammar->fulltextSearch(['"body"'], 'plain')
        );
        $this->assertStringContainsString(
            'phraseto_tsquery',
            $grammar->fulltextSearch(['"body"'], 'phrase')
        );
        $this->assertStringContainsString(
            'websearch_to_tsquery',
            $grammar->fulltextSearch(['"body"'], 'websearch')
        );
    }

    #[Test]
    public function theModeIsCheckedBeforeTheColumnCount(): void
    {
        // SQLite rejects multiple columns too. Whichever is reported first, a
        // caller who got both wrong must not be told the mode is fine.
        $this->expectException(InvalidArgumentException::class);

        (new SQLiteGrammar())->fulltextSearch(['a', 'b'], 'boolean');
    }
}
