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
 * The expressions the grammars build for array columns and full-text search.
 *
 * PostgreSQL has both natively; MySQL and SQLite are emulated over JSON and
 * FTS5. The emulations disagreed with the real thing — a value bound into
 * JSON_CONTAINS was rejected outright, an int compared as a string, and every
 * MySQL search mode collapsed onto BOOLEAN MODE, which reads punctuation in the
 * caller's term as query operators.
 */
class GrammarExpressionTest extends TestCase
{
    /** @return array<string, array{0: Grammar}> */
    public static function grammarProvider(): array
    {
        return [
            'mysql' => [new MySQLGrammar()],
            'pg' => [new PostgresGrammar()],
            'sqlite' => [new SQLiteGrammar()],
        ];
    }

    // ------------------------------------------------------------------
    // ARRAY COLUMNS
    // ------------------------------------------------------------------

    #[Test]
    public function mysqlBuildsTheContainsCandidateAsJsonRatherThanBindingItBare(): void
    {
        // JSON_CONTAINS(col, ?, '$') was rejected for every string value:
        // "Invalid JSON text in argument 1 ... at position 0". Integers passed
        // only because 2 is itself valid JSON.
        $this->assertSame(
            "JSON_CONTAINS(`tags`, JSON_ARRAY(CAST(? AS CHAR)), '$')",
            (new MySQLGrammar())->arrayContains('`tags`')
        );
    }

    #[Test]
    public function mysqlCastsTheCandidateToTheElementTypeTheCallerNamed(): void
    {
        // PDO binds every value as a string, so JSON_ARRAY(?) given 2 builds
        // ["2"], which does not match a stored [1,2].
        $grammar = new MySQLGrammar();

        $this->assertSame(
            "JSON_CONTAINS(`nums`, JSON_ARRAY(CAST(? AS SIGNED)), '$')",
            $grammar->arrayContains('`nums`', 'int')
        );
        $this->assertSame(
            'JSON_OVERLAPS(`nums`, JSON_ARRAY(CAST(? AS SIGNED),CAST(? AS SIGNED)))',
            $grammar->arrayOverlaps('`nums`', 2, 'int')
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function elementTypeProvider(): array
    {
        return [
            'text' => ['text', 'CHAR'],
            'varchar' => ['varchar', 'CHAR'],
            'int' => ['int', 'SIGNED'],
            'integer' => ['integer', 'SIGNED'],
            'bigint' => ['bigint', 'SIGNED'],
            'int4 spelled as pg does' => ['int4', 'SIGNED'],
            'numeric' => ['numeric', 'DECIMAL(65,30)'],
            'double precision' => ['double precision', 'DECIMAL(65,30)'],
            'date' => ['date', 'DATE'],
            'timestamp' => ['timestamp', 'DATETIME'],
            'upper case' => ['INT', 'SIGNED'],
            'padded' => ['  int  ', 'SIGNED'],
            'unrecognised falls back to text' => ['nosuchtype', 'CHAR'],
        ];
    }

    #[Test]
    #[DataProvider('elementTypeProvider')]
    public function mysqlMapsEachElementTypeToACastTarget(string $type, string $cast): void
    {
        $this->assertSame(
            "JSON_CONTAINS(`c`, JSON_ARRAY(CAST(? AS $cast)), '$')",
            (new MySQLGrammar())->arrayContains('`c`', $type)
        );
    }

    #[Test]
    public function theElementTypeNeverReachesTheStatementAsSql(): void
    {
        // The cast target comes from a fixed map, so a hostile type name
        // cannot become SQL — it falls through to CHAR like any other
        // unrecognised word.
        $this->assertSame(
            "JSON_CONTAINS(`c`, JSON_ARRAY(CAST(? AS CHAR)), '$')",
            (new MySQLGrammar())->arrayContains('`c`', "int) -- ")
        );
    }

    #[Test]
    public function postgresRejectsAnElementTypeThatIsNotAnIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid array element type');

        (new PostgresGrammar())->arrayContains('"c"', 'int); DROP TABLE x; --');
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function anOverlapWithNoValuesMatchesNothingWithoutAnEmptyInList(Grammar $grammar): void
    {
        // SQLite emitted IN (), which it happens to accept but which is a
        // syntax error on other engines; MySQL emitted JSON_ARRAY().
        $sql = $grammar->arrayOverlaps('"c"', 0);

        $this->assertStringNotContainsString('IN ()', $sql);
        $this->assertStringNotContainsString('?', $sql, 'an empty overlap binds nothing');
    }

    #[Test]
    public function sqliteAndMysqlSayPlainlyThatAnEmptyOverlapMatchesNothing(): void
    {
        $this->assertSame('0 = 1', (new SQLiteGrammar())->arrayOverlaps('"c"', 0));
        $this->assertSame('0 = 1', (new MySQLGrammar())->arrayOverlaps('`c`', 0));
    }

    #[Test]
    public function postgresKeepsItsNativeEmptyArrayWhichIsAlreadyValid(): void
    {
        $this->assertSame('"c" && ARRAY[]::text[]', (new PostgresGrammar())->arrayOverlaps('"c"', 0));
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function anOverlapBindsOnePlaceholderPerValue(Grammar $grammar): void
    {
        $this->assertSame(3, substr_count($grammar->arrayOverlaps('"c"', 3), '?'));
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function containsBindsExactlyOneValue(Grammar $grammar): void
    {
        $this->assertSame(1, substr_count($grammar->arrayContains('"c"'), '?'));
    }

    // ------------------------------------------------------------------
    // FULL-TEXT SEARCH
    // ------------------------------------------------------------------

    #[Test]
    public function mysqlPlainSearchNoLongerReadsPunctuationAsOperators(): void
    {
        // BOOLEAN MODE parsed '-' as an exclusion and raised "syntax error,
        // unexpected '+'" on an ordinary term like 'C++ database'.
        $this->assertSame(
            'MATCH(`title`, `body`) AGAINST(? IN NATURAL LANGUAGE MODE)',
            (new MySQLGrammar())->fulltextSearch(['`title`', '`body`'])
        );
    }

    #[Test]
    public function mysqlWebsearchKeepsBooleanModeWhereTheOperatorsAreTheWholePoint(): void
    {
        $this->assertSame(
            'MATCH(`body`) AGAINST(? IN BOOLEAN MODE)',
            (new MySQLGrammar())->fulltextSearch(['`body`'], 'websearch')
        );
    }

    #[Test]
    public function mysqlPhraseQuotesTheTermInSqlSoItStaysBound(): void
    {
        $sql = (new MySQLGrammar())->fulltextSearch(['`body`'], 'phrase');

        $this->assertSame(
            'MATCH(`body`) AGAINST(CONCAT(\'"\', REPLACE(?, \'"\', \' \'), \'"\') IN BOOLEAN MODE)',
            $sql
        );
        $this->assertSame(1, substr_count($sql, '?'), 'the term is still a single bound value');
    }

    #[Test]
    public function mysqlPhraseStripsAQuoteThatWouldCloseThePhraseEarly(): void
    {
        // Without this, a term such as 'data"base systems' ended the phrase and
        // the remainder was read as boolean operators — it matched rows the
        // caller never asked for.
        $this->assertStringContainsString(
            'REPLACE(?, \'"\', \' \')',
            (new MySQLGrammar())->fulltextSearch(['`body`'], 'phrase')
        );
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function everySearchModeBindsExactlyOneTerm(Grammar $grammar): void
    {
        foreach (['plain', 'phrase', 'websearch'] as $mode) {
            $this->assertSame(
                1,
                substr_count($grammar->fulltextSearch(['"c"'], $mode), '?'),
                "mode $mode"
            );
        }
    }

    #[Test]
    public function mysqlAndPostgresGiveEachModeItsOwnExpression(): void
    {
        foreach ([new MySQLGrammar(), new PostgresGrammar()] as $grammar) {
            $built = array_map(
                fn(string $mode): string => $grammar->fulltextSearch(['"c"'], $mode),
                ['plain', 'phrase', 'websearch']
            );

            $this->assertCount(3, array_unique($built), $grammar::class . ' collapsed its modes');
        }
    }

    #[Test]
    public function postgresPassesTheLanguageThroughAsALiteral(): void
    {
        $sql = (new PostgresGrammar())->fulltextSearch(['"body"'], 'plain', 'simple');

        $this->assertStringContainsString("to_tsvector('simple', \"body\")", $sql);
        $this->assertStringContainsString("plainto_tsquery('simple', ?)", $sql);
    }

    #[Test]
    public function postgresEscapesAHostileLanguageIntoALiteral(): void
    {
        $sql = (new PostgresGrammar())->fulltextSearch(['"body"'], 'plain', "english') OR true --");

        $this->assertStringNotContainsString("('english') OR true --')", $sql);
        $this->assertStringContainsString("''", $sql, 'the quote is doubled, not left open');
    }

    #[Test]
    public function postgresSearchesEveryColumnItIsGiven(): void
    {
        $sql = (new PostgresGrammar())->fulltextSearch(['"title"', '"body"']);

        $this->assertStringContainsString('"title"', $sql);
        $this->assertStringContainsString('"body"', $sql);
        $this->assertStringContainsString('||', $sql, 'the vectors are concatenated');
    }

    #[Test]
    public function mysqlSearchesEveryColumnItIsGiven(): void
    {
        $this->assertStringContainsString(
            'MATCH(`title`, `body`)',
            (new MySQLGrammar())->fulltextSearch(['`title`', '`body`'])
        );
    }

    #[Test]
    public function sqliteRefusesTheColumnsItCannotSearchRatherThanDroppingThem(): void
    {
        // It used to take $columns[0] and discard the rest in silence, so
        // whereFulltext(['title', 'body'], ...) searched title alone.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matches one column at a time; 2 were given');

        (new SQLiteGrammar())->fulltextSearch(['"title"', '"body"']);
    }

    #[Test]
    public function sqliteRefusesASearchWithNoColumnAtAll(): void
    {
        // The default was a made-up column named 'content'.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one column');

        (new SQLiteGrammar())->fulltextSearch([]);
    }

    #[Test]
    public function sqliteMatchesTheColumnItIsGiven(): void
    {
        $this->assertSame('"body" MATCH ?', (new SQLiteGrammar())->fulltextSearch(['"body"']));
    }

    #[Test]
    public function sqlitePhraseQuotesTheTermInSqlSoItStaysBound(): void
    {
        $sql = (new SQLiteGrammar())->fulltextSearch(['"body"'], 'phrase');

        $this->assertSame('"body" MATCH (\'"\' || REPLACE(?, \'"\', \' \') || \'"\')', $sql);
        $this->assertSame(1, substr_count($sql, '?'));
    }

    #[Test]
    public function sqliteReportsTheFulltextSupportItActuallyHas(): void
    {
        // It reported false while still emitting MATCH, so nothing consulted
        // the flag and callers got a statement that could not run. FTS5 is
        // built in; the requirement is a virtual table, not a newer SQLite.
        $this->assertTrue((new SQLiteGrammar())->supportsFulltext());
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function everyGrammarAgreesOnWhetherItHasNativeArrayColumns(Grammar $grammar): void
    {
        $native = $grammar->supportsArrayColumns();

        $this->assertSame($grammar instanceof PostgresGrammar, $native);
        // Whether native or emulated, the call still has to produce something.
        $this->assertNotSame('', $grammar->arrayContains('"c"'));
    }
}
