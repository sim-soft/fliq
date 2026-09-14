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
 * Each engine names its own EXPLAIN formats and spells the request differently.
 *
 * The prefix used to be assembled in Execute::explain() from one template for
 * every driver. MySQL ignored $format outright and returned a traditional plan
 * for `format: 'json'`; PostgreSQL emitted `EXPLAIN ANALYZE (FORMAT JSON)`,
 * which the server refuses to parse. Both are the documented calls.
 */
class ExplainFormatTest extends TestCase
{
    /** @return array<string, array{0: bool, 1: string, 2: string}> */
    public static function mysqlProvider(): array
    {
        return [
            'default' => [false, 'text', 'EXPLAIN'],
            'traditional is the same plan' => [false, 'traditional', 'EXPLAIN'],
            'json' => [false, 'json', 'EXPLAIN FORMAT=JSON'],
            'tree' => [false, 'tree', 'EXPLAIN FORMAT=TREE'],
            'case and padding are ignored' => [false, '  JSON ', 'EXPLAIN FORMAT=JSON'],
            // ANALYZE reports the tree format whether or not it is named, and
            // rejects FORMAT=TREE beside it, so the bare form serves both.
            'analyze' => [true, 'text', 'EXPLAIN ANALYZE'],
            'analyze tree' => [true, 'tree', 'EXPLAIN ANALYZE'],
        ];
    }

    #[Test]
    #[DataProvider('mysqlProvider')]
    public function mysqlSpellsEachFormatTheWayTheServerAcceptsIt(
        bool $analyze,
        string $format,
        string $expected
    ): void {
        $this->assertSame($expected, (new MySQLGrammar())->explainSQL($analyze, $format));
    }

    /** @return array<string, array{0: bool, 1: string, 2: string}> */
    public static function postgresProvider(): array
    {
        return [
            'default' => [false, 'text', 'EXPLAIN'],
            'json' => [false, 'json', 'EXPLAIN (FORMAT JSON)'],
            'yaml' => [false, 'yaml', 'EXPLAIN (FORMAT YAML)'],
            'xml' => [false, 'xml', 'EXPLAIN (FORMAT XML)'],
            'analyze' => [true, 'text', 'EXPLAIN (ANALYZE)'],
            // The documented example. It used to emit
            // "EXPLAIN ANALYZE (FORMAT JSON)", a syntax error at "FORMAT".
            'analyze json' => [true, 'json', 'EXPLAIN (ANALYZE, FORMAT JSON)'],
        ];
    }

    #[Test]
    #[DataProvider('postgresProvider')]
    public function postgresPutsEveryOptionInsideOneParenthesisedList(
        bool $analyze,
        string $format,
        string $expected
    ): void {
        $this->assertSame($expected, (new PostgresGrammar())->explainSQL($analyze, $format));
    }

    #[Test]
    public function sqliteExplainsThePlanOnly(): void
    {
        $this->assertSame('EXPLAIN QUERY PLAN', (new SQLiteGrammar())->explainSQL(false, 'text'));
    }

    /** @return array<string, array{0: Grammar, 1: string}> */
    public static function rejectedFormatProvider(): array
    {
        return [
            'mysql has no yaml' => [new MySQLGrammar(), 'yaml'],
            'mysql has no xml' => [new MySQLGrammar(), 'xml'],
            'postgres has no tree' => [new PostgresGrammar(), 'tree'],
            'postgres has no traditional' => [new PostgresGrammar(), 'traditional'],
            'sqlite has no json' => [new SQLiteGrammar(), 'json'],
            'an empty format names nothing' => [new MySQLGrammar(), ''],
        ];
    }

    #[Test]
    #[DataProvider('rejectedFormatProvider')]
    public function aFormatTheEngineCannotProduceIsRejected(Grammar $grammar, string $format): void
    {
        // Silently dropping it handed back a plan in a different shape than the
        // caller asked for, which they then parsed as though it were the one
        // they requested.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported EXPLAIN format');

        $grammar->explainSQL(false, $format);
    }

    #[Test]
    public function mysqlRejectsAFormatItCannotCombineWithAnalyze(): void
    {
        // FORMAT=JSON is fine on its own but not beside ANALYZE, so the
        // whitelist narrows rather than being fixed per driver.
        $this->assertSame('EXPLAIN FORMAT=JSON', (new MySQLGrammar())->explainSQL(false, 'json'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supported formats: text, tree.');

        (new MySQLGrammar())->explainSQL(true, 'json');
    }

    #[Test]
    public function sqliteRejectsAnalyzeOutright(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite has no EXPLAIN ANALYZE');

        (new SQLiteGrammar())->explainSQL(true, 'text');
    }

    #[Test]
    public function aFormatIsNeverInterpolatedIntoTheStatement(): void
    {
        // $format reached the SQL string unchecked. It is not reachable from
        // request data in any documented shape, so this is robustness rather
        // than a live injection, but the value belongs on a whitelist either way.
        $this->expectException(InvalidArgumentException::class);

        (new PostgresGrammar())->explainSQL(false, 'text) /*');
    }

    #[Test]
    public function theMessageNamesTheDriverAndWhatItAccepts(): void
    {
        try {
            (new PostgresGrammar())->explainSQL(false, 'tree');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('pgsql', $exception->getMessage());
            $this->assertStringContainsString('text, json, yaml, xml', $exception->getMessage());
        }
    }
}
