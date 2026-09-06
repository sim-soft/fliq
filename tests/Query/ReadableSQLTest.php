<?php

namespace Query;

use DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Grammar\Grammar;
use Simsoft\DB\Grammar\MySQLGrammar;
use Simsoft\DB\Grammar\PostgresGrammar;
use Simsoft\DB\Grammar\SQLiteGrammar;
use Stringable;

/**
 * The debug renderings: dump(), dd() and getFullSQL().
 *
 * These exist to show what was run, so a rendering that does not mean what the
 * executed statement meant is worse than none — it sends the reader after a
 * difference that is not there, or hides one that is. It was assembled with one
 * rule for every driver and no knowledge of how the value would be bound, which
 * got several cases wrong in both directions.
 */
class ReadableSQLTest extends TestCase
{
    /** @return array<string, array{0: Grammar}> */
    public static function grammarProvider(): array
    {
        return [
            'mysql' => [new MySQLGrammar()],
            'pgsql' => [new PostgresGrammar()],
            'sqlite' => [new SQLiteGrammar()],
        ];
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function anApostropheIsDoubledOnEveryEngine(Grammar $grammar): void
    {
        $this->assertSame("'o''brien'", $grammar->literal("o'brien"));
    }

    #[Test]
    public function mysqlDoublesABackslashBecauseItsLiteralsEscapeWithOne(): void
    {
        // Left single, the value read as an escape sequence: 'back\' does not
        // close the literal at all, and the rest of the statement was swallowed
        // into it. MySQL reported a syntax error for a query that ran fine.
        $this->assertSame("'a\\\\b'", (new MySQLGrammar())->literal('a\\b'));
        $this->assertSame("'back\\\\'", (new MySQLGrammar())->literal('back\\'));
    }

    #[Test]
    #[DataProvider('backslashKeepingGrammarProvider')]
    public function anEngineWithoutBackslashEscapesLeavesItAlone(Grammar $grammar): void
    {
        // Doubling it here would show a value with two backslashes where the
        // bound one had one, which is the same error in the other direction.
        $this->assertSame("'a\\b'", $grammar->literal('a\\b'));
    }

    /** @return array<string, array{0: Grammar}> */
    public static function backslashKeepingGrammarProvider(): array
    {
        return [
            'pgsql' => [new PostgresGrammar()],
            'sqlite' => [new SQLiteGrammar()],
        ];
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function nullIsTheKeywordAndNotAQuotedWord(Grammar $grammar): void
    {
        $this->assertSame('NULL', $grammar->literal(null));
    }

    #[Test]
    #[DataProvider('pdoBindingGrammarProvider')]
    public function aNumberIsQuotedWhereTheDriverBindsItAsAString(Grammar $grammar): void
    {
        // PDOStatement::execute($binds) binds everything as PDO::PARAM_STR, so
        // a bound 7 compares as '7'. Rendered bare, it compared as the number
        // instead — and against a text column those differ: '007' = 7 holds
        // where '007' = '7' does not, so the rendering found rows the executed
        // statement did not. is_numeric() sent '007' and '1e3' the same way.
        $this->assertSame("'7'", $grammar->literal(7));
        $this->assertSame("'007'", $grammar->literal('007'));
        $this->assertSame("'1e3'", $grammar->literal('1e3'));
    }

    /** @return array<string, array{0: Grammar}> */
    public static function pdoBindingGrammarProvider(): array
    {
        return [
            'mysql' => [new MySQLGrammar()],
            'pgsql' => [new PostgresGrammar()],
        ];
    }

    #[Test]
    public function sqliteRendersANumberBareBecauseItsDriverBindsByType(): void
    {
        // SQLiteDriver::bindTypedValues() binds an int as PDO::PARAM_INT, and
        // SQLite does not compare '1' equal to 1, so quoting it here would
        // show a comparison the engine never made.
        $grammar = new SQLiteGrammar();

        $this->assertSame('7', $grammar->literal(7));
        $this->assertSame('1', $grammar->literal(true));
        $this->assertSame('0', $grammar->literal(false));
        $this->assertSame("'7'", $grammar->literal('7'), 'A string stays a string.');
    }

    #[Test]
    #[DataProvider('pdoBindingGrammarProvider')]
    public function aBoolIsRenderedAsThePdoConversionSendsIt(Grammar $grammar): void
    {
        // (string)true is '1' and (string)false is '', which is what reaches
        // the server. TRUE and FALSE read better but compare differently:
        // against a varchar column, `= FALSE` matches every row while a bound
        // false matches none.
        $this->assertSame("'1'", $grammar->literal(true));
        $this->assertSame("''", $grammar->literal(false));
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function aValueWithNoStringFormIsNamedRatherThanCastToOne(Grammar $grammar): void
    {
        // Casting these raised "Object of class DateTime could not be converted
        // to string" — an Error thrown out of dump(), from inside the debugging
        // the caller was doing to find the problem in the first place.
        $this->assertSame("'[array]'", $grammar->literal(['x']));
        $this->assertSame("'[DateTime]'", $grammar->literal(new DateTime()));
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function aStringableIsRenderedByItsStringForm(Grammar $grammar): void
    {
        $value = new class implements Stringable {
            public function __toString(): string
            {
                return "o'brien";
            }
        };

        $this->assertSame("'o''brien'", $grammar->literal($value));
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function aStatementShortOfBindsKeepsItsRemainingPlaceholders(Grammar $grammar): void
    {
        // The missing value was replaced with the placeholder as though it were
        // one, so it came back quoted: `b = '?'` reads as a value the caller
        // passed and never did, and the server rejects it against a typed
        // column. Leaving it says plainly that nothing was bound there.
        $this->assertSame(
            "SELECT * FROM t WHERE a = 'x' AND b = ?",
            $grammar->readableSQL('SELECT * FROM t WHERE a = ? AND b = ?', ['x'])
        );
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function anEmptyPlaceholderLeavesTheStatementUntouched(Grammar $grammar): void
    {
        $sql = 'SELECT * FROM t WHERE a = 1';

        $this->assertSame($sql, $grammar->readableSQL($sql, ['x'], ''));
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function aNamedPlaceholderIsSubstitutedInStatementOrder(Grammar $grammar): void
    {
        $this->assertSame(
            "SELECT * FROM t WHERE a = 'x' AND b = 'y'",
            $grammar->readableSQL('SELECT * FROM t WHERE a = :v AND b = :v', ['x', 'y'], ':v')
        );
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function aQuestionMarkInsideAValueIsNotTreatedAsAPlaceholder(Grammar $grammar): void
    {
        // The value is substituted into the segment after the placeholder it
        // filled, so its own '?' is never revisited.
        $this->assertSame(
            "SELECT * FROM t WHERE a = 'who?' AND b = 'x'",
            $grammar->readableSQL('SELECT * FROM t WHERE a = ? AND b = ?', ['who?', 'x'])
        );
    }

    #[Test]
    #[DataProvider('grammarProvider')]
    public function aStatementWithNoPlaceholdersIsReturnedAsWritten(Grammar $grammar): void
    {
        $sql = 'SELECT 1';

        $this->assertSame($sql, $grammar->readableSQL($sql, []));
    }
}
