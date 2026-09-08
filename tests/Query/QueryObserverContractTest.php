<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\DB\QueryLogger;
use Simsoft\DB\QueryMonitor;

/**
 * What QueryMonitor and QueryLogger promise about grouping, attribution and
 * teardown.
 *
 * These two are the only classes in the library whose entire job is to describe
 * what happened, which makes them the two whose failures are hardest to notice:
 * a monitor that groups nothing reports nothing, and looks exactly like a
 * codebase with no N+1 in it.
 */
class QueryObserverContractTest extends TestCase
{
    protected function setUp(): void
    {
        $this->quiesce();
    }

    protected function tearDown(): void
    {
        $this->quiesce();
    }

    /**
     * Return both observers to a pristine state.
     */
    private function quiesce(): void
    {
        QueryMonitor::disable();
        QueryMonitor::clearHandler();
        QueryMonitor::reset();
        QueryLogger::disable();
        QueryLogger::clearHandler();
        QueryLogger::reset();
        QueryLogger::setLimit(QueryLogger::DEFAULT_LIMIT);
    }

    /**
     * The patterns the monitor is currently holding, counts included.
     *
     * @return array<string, int>
     */
    private function patterns(): array
    {
        /** @var array<string, int> $patterns */
        $patterns = (new ReflectionProperty(QueryMonitor::class, 'patterns'))->getValue();

        return $patterns;
    }

    /**
     * Enable monitoring without the default warning.
     *
     * Crossing the threshold calls trigger_error() when no handler is set, and
     * PHPUnit reports that as a warning against the test. The tests below that
     * are about grouping and attribution cross it on purpose, so they take a
     * handler that does nothing; the tests that are about the handler itself
     * call enable() directly.
     */
    private function enableQuietly(int $threshold): void
    {
        QueryMonitor::enable($threshold);
        QueryMonitor::setHandler(static fn() => null);
    }

    /**
     * Normalise one statement the way recordQuery() does.
     */
    private function patternFor(string $sql): string
    {
        QueryMonitor::reset();
        QueryMonitor::enable(threshold: 1000);
        QueryMonitor::recordQuery($sql);
        $keys = array_keys($this->patterns());
        QueryMonitor::disable();

        return (string)($keys[0] ?? '');
    }

    // ------------------------------------------------------------------
    // Grouping: the literal that varies is the whole point
    // ------------------------------------------------------------------

    #[Test]
    public function queriesDifferingOnlyInATrailingIdAreOnePattern(): void
    {
        $this->enableQuietly(5);

        for ($id = 1; $id <= 6; $id++) {
            QueryMonitor::recordQuery("SELECT * FROM user WHERE id = $id");
        }

        // The N+1 this class exists to find writes its varying value last,
        // because that is where a WHERE clause on a primary key puts it.
        $this->assertSame(
            ['SELECT * FROM user WHERE id = ?' => 6],
            $this->patterns()
        );
        $this->assertCount(1, QueryMonitor::getDetectedPatterns());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function literalPositions(): array
    {
        return [
            'trailing' => ['SELECT * FROM user WHERE id = 1', 'SELECT * FROM user WHERE id = ?'],
            'multi-digit trailing' => ['SELECT * FROM user WHERE id = 4321', 'SELECT * FROM user WHERE id = ?'],
            'trailing after >' => ['SELECT * FROM user WHERE id > 3', 'SELECT * FROM user WHERE id > ?'],
            'trailing LIMIT' => ['SELECT * FROM user LIMIT 10', 'SELECT * FROM user LIMIT ?'],
            'trailing OFFSET' => ['SELECT * FROM u LIMIT 5 OFFSET 10', 'SELECT * FROM u LIMIT ? OFFSET ?'],
            'before semicolon' => ['SELECT * FROM user WHERE id = 1;', 'SELECT * FROM user WHERE id = ?;'],
            'mid-statement' => ['UPDATE user SET n = 5 WHERE id = 9', 'UPDATE user SET n = ? WHERE id = ?'],
            'value list' => ['INSERT INTO t VALUES (1,2,3)', 'INSERT INTO t VALUES (?,?,?)'],
            'delete' => ['DELETE FROM user WHERE id = 9', 'DELETE FROM user WHERE id = ?'],
            'quoted identifier' => ['SELECT * FROM `p` WHERE `user_id` = 7', 'SELECT * FROM `p` WHERE `user_id` = ?'],
        ];
    }

    #[Test]
    #[DataProvider('literalPositions')]
    public function aNumericLiteralIsNormalisedWhereverItSits(string $sql, string $expected): void
    {
        $this->assertSame($expected, $this->patternFor($sql));
    }

    #[Test]
    public function anEscapedQuoteDoesNotSplitAStringLiteral(): void
    {
        // 'o''brien' is one value. Read as two, it normalised to `??` and never
        // grouped with the same query carrying a name without an apostrophe.
        $this->assertSame(
            'SELECT * FROM user WHERE name = ?',
            $this->patternFor("SELECT * FROM user WHERE name = 'o''brien'")
        );
    }

    #[Test]
    public function namesWithAndWithoutAnApostropheShareAPattern(): void
    {
        $this->enableQuietly(2);

        QueryMonitor::recordQuery("SELECT * FROM user WHERE name = 'smith'");
        QueryMonitor::recordQuery("SELECT * FROM user WHERE name = 'o''brien'");

        $this->assertSame(['SELECT * FROM user WHERE name = ?' => 2], $this->patterns());
    }

    #[Test]
    public function separateLiteralsAreStillSeparatePlaceholders(): void
    {
        $this->assertSame(
            'SELECT * FROM user WHERE a = ? AND b = ?',
            $this->patternFor("SELECT * FROM user WHERE a = 'x' AND b = 'y'")
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function identifiersHoldingDigits(): array
    {
        return [
            'table' => ['SELECT * FROM user2'],
            'columns' => ['SELECT col1, col2 FROM t9'],
            'quoted table' => ['SELECT * FROM `t1`'],
            'join' => ['SELECT * FROM t1 JOIN t2 ON t1.id = t2.id'],
        ];
    }

    #[Test]
    #[DataProvider('identifiersHoldingDigits')]
    public function digitsInsideAnIdentifierSurvive(string $sql): void
    {
        // Normalising these would merge queries against genuinely different
        // tables into one pattern, and report an N+1 that is not there.
        $this->assertSame($sql, $this->patternFor($sql));
    }

    #[Test]
    public function distinctStatementsStayDistinct(): void
    {
        $this->enableQuietly(100);

        QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 1');
        QueryMonitor::recordQuery('SELECT * FROM post WHERE id = 1');
        QueryMonitor::recordQuery('SELECT * FROM user2 WHERE id = 1');

        $this->assertCount(3, $this->patterns());
    }

    #[Test]
    public function aDoubledQuoteRunDoesNotStallTheNormaliser(): void
    {
        // '(?:[^']|'')*' has two ways to match a non-quote character. Written
        // as ('' | [^'])* it would backtrack exponentially on a long run.
        $sql = "SELECT * FROM t WHERE s = '" . str_repeat("a''b", 500) . "'";

        $started = microtime(true);
        $pattern = $this->patternFor($sql);
        $elapsed = microtime(true) - $started;

        $this->assertSame('SELECT * FROM t WHERE s = ?', $pattern);
        $this->assertLessThan(1.0, $elapsed);
    }

    // ------------------------------------------------------------------
    // Attribution: a warning that blames the library is not actionable
    // ------------------------------------------------------------------

    #[Test]
    public function theOriginIsTheCallerNotTheMonitor(): void
    {
        $this->enableQuietly(2);

        QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 1');
        $line = __LINE__ - 1;
        QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 2');

        $detected = QueryMonitor::getDetectedPatterns();
        $origin = reset($detected)['origin'] ?? '';

        $this->assertSame(__FILE__ . ':' . $line, $origin);
    }

    #[Test]
    public function noOriginEverPointsInsideTheLibrary(): void
    {
        $this->enableQuietly(1);
        QueryMonitor::recordQuery('SELECT 1');

        $detected = QueryMonitor::getDetectedPatterns();
        $origin = (string)(reset($detected)['origin'] ?? '');

        // The bug this replaces reported QueryMonitor.php's own line number for
        // every query on every platform, because the skip list tested for
        // 'src/Drivers/' and friends with forward slashes against paths that on
        // Windows use backslashes — so nothing matched and frame zero, the
        // getOrigin() call itself, was always returned.
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        $this->assertStringStartsNotWith($root, $origin);
        $this->assertStringStartsWith(__FILE__ . ':', $origin);
    }

    #[Test]
    public function theOriginIsFoundThroughAnIndirectCall(): void
    {
        $this->enableQuietly(2);

        $emit = static function (string $sql): void {
            QueryMonitor::recordQuery($sql);
        };
        $emit('SELECT * FROM post WHERE user_id = 1');
        $emit('SELECT * FROM post WHERE user_id = 2');

        $detected = QueryMonitor::getDetectedPatterns();
        $origin = (string)(reset($detected)['origin'] ?? '');

        $this->assertStringStartsWith(__FILE__ . ':', $origin);
    }

    #[Test]
    public function theOriginIsRecordedOncePerPatternNotPerQuery(): void
    {
        $this->enableQuietly(3);

        QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 1');
        $first = __LINE__ - 1;
        QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 2');
        QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 3');

        $detected = QueryMonitor::getDetectedPatterns();

        // The first sighting is the useful one: it is where the loop began.
        $this->assertSame(__FILE__ . ':' . $first, reset($detected)['origin'] ?? '');
    }

    // ------------------------------------------------------------------
    // Teardown: a handler outlives what registered it
    // ------------------------------------------------------------------

    #[Test]
    public function theMonitorHandlerCanBeCleared(): void
    {
        $calls = 0;
        QueryMonitor::enable(threshold: 2);
        QueryMonitor::setHandler(function () use (&$calls): void {
            $calls++;
        });

        QueryMonitor::recordQuery('SELECT 1');
        QueryMonitor::recordQuery('SELECT 1');
        $this->assertSame(1, $calls);

        QueryMonitor::clearHandler();
        QueryMonitor::reset();

        // Detection continues — it is only the delivery that was cancelled, so
        // the threshold is still crossed and the default warning still fires.
        set_error_handler(static fn(): bool => true, E_USER_WARNING);

        try {
            QueryMonitor::recordQuery('SELECT 1');
            QueryMonitor::recordQuery('SELECT 1');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function aClearedMonitorHandlerIsGoneNotReplaced(): void
    {
        QueryMonitor::setHandler(static fn() => null);
        QueryMonitor::clearHandler();

        $this->assertNull((new ReflectionProperty(QueryMonitor::class, 'handler'))->getValue());
    }

    #[Test]
    public function theLoggerHandlerCanBeCleared(): void
    {
        $calls = 0;
        QueryLogger::enable();
        QueryLogger::setHandler(function () use (&$calls): void {
            $calls++;
        });

        QueryLogger::logQuery('SELECT 1', null, microtime(true));
        $this->assertSame(1, $calls);

        QueryLogger::clearHandler();
        QueryLogger::logQuery('SELECT 2', null, microtime(true));

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function aClearedLoggerHandlerIsGoneNotReplaced(): void
    {
        QueryLogger::setHandler(static fn() => null);
        QueryLogger::clearHandler();

        $this->assertNull((new ReflectionProperty(QueryLogger::class, 'handler'))->getValue());
    }

    #[Test]
    public function clearingAHandlerThatWasNeverSetIsHarmless(): void
    {
        QueryMonitor::clearHandler();
        QueryMonitor::clearHandler();
        QueryLogger::clearHandler();
        QueryLogger::clearHandler();

        $this->assertNull((new ReflectionProperty(QueryMonitor::class, 'handler'))->getValue());
        $this->assertNull((new ReflectionProperty(QueryLogger::class, 'handler'))->getValue());
    }

    #[Test]
    public function clearingTheMonitorHandlerRestoresTheDefaultWarning(): void
    {
        QueryMonitor::setHandler(static fn() => null);
        QueryMonitor::clearHandler();
        QueryMonitor::enable(threshold: 2);

        $raised = '';
        set_error_handler(function (int $_no, string $message) use (&$raised): bool {
            $raised = $message;

            return true;
        }, E_USER_WARNING);

        try {
            QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 1');
            QueryMonitor::recordQuery('SELECT * FROM user WHERE id = 2');
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('N+1 query detected (2x)', $raised);
        $this->assertStringContainsString('SELECT * FROM user WHERE id = ?', $raised);
    }

    #[Test]
    public function aClearedHandlerDoesNotFireForTheRestOfTheProcess(): void
    {
        // The leak this covers: disable() and reset() both leave the handler
        // installed, so one registered inside a profiling block kept receiving
        // queries long after that block had ended.
        $calls = 0;
        QueryMonitor::enable(threshold: 1);
        QueryMonitor::setHandler(function () use (&$calls): void {
            $calls++;
        });
        QueryMonitor::recordQuery('SELECT 1');
        $this->assertSame(1, $calls);

        QueryMonitor::disable();
        QueryMonitor::clearHandler();
        QueryMonitor::reset();

        QueryMonitor::enable(threshold: 1);
        set_error_handler(static fn(): bool => true, E_USER_WARNING);

        try {
            QueryMonitor::recordQuery('SELECT 2');
            QueryMonitor::recordQuery('SELECT 3');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $calls);
    }
}
