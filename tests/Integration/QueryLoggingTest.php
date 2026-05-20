<?php

namespace Integration;

use Models\Setting;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\QueryLogger;
use Simsoft\DB\QueryMonitor;

/**
 * Integration tests for QueryLogger and QueryMonitor.
 *

 */
class QueryLoggingTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        QueryLogger::disable();
        QueryLogger::reset();
        QueryMonitor::disable();
        QueryMonitor::reset();
    }

    protected function tearDown(): void
    {
        QueryLogger::disable();
        QueryLogger::reset();
        QueryMonitor::disable();
        QueryMonitor::reset();
    }

    // ------------------------------------------------------------------
    // QueryLogger
    // ------------------------------------------------------------------

    #[Test]
    public function loggerDisabledByDefault(): void
    {
        $this->assertFalse(QueryLogger::isEnabled());
    }

    #[Test]
    public function loggerEnableAndDisable(): void
    {
        QueryLogger::enable();
        $this->assertTrue(QueryLogger::isEnabled());

        QueryLogger::disable();
        $this->assertFalse(QueryLogger::isEnabled());
    }

    #[Test]
    public function loggerRecordsQueriesWhenEnabled(): void
    {
        QueryLogger::enable();

        User::findByPk(1);
        User::findByPk(2);

        $queries = QueryLogger::getQueries();
        $this->assertCount(2, $queries);
    }

    #[Test]
    public function loggerDoesNotRecordWhenDisabled(): void
    {
        // Disabled by default
        User::findByPk(1);

        $queries = QueryLogger::getQueries();
        $this->assertCount(0, $queries);
    }

    #[Test]
    public function loggerRecordsSqlAndBinds(): void
    {
        QueryLogger::enable();

        User::findByPk(1);

        $queries = QueryLogger::getQueries();
        $this->assertCount(1, $queries);
        $this->assertArrayHasKey('sql', $queries[0]);
        $this->assertArrayHasKey('binds', $queries[0]);
        $this->assertArrayHasKey('time', $queries[0]);
        $this->assertStringContainsString('user', $queries[0]['sql']);
    }

    #[Test]
    public function loggerRecordsExecutionTime(): void
    {
        QueryLogger::enable();

        User::findByPk(1);

        $queries = QueryLogger::getQueries();
        $this->assertGreaterThanOrEqual(0, $queries[0]['time']);
    }

    #[Test]
    public function loggerGetQueryCount(): void
    {
        QueryLogger::enable();

        User::findByPk(1);
        User::findByPk(2);
        Setting::findByPk(1);

        $this->assertEquals(3, QueryLogger::getQueryCount());
    }

    #[Test]
    public function loggerGetTotalTime(): void
    {
        QueryLogger::enable();

        User::findByPk(1);
        User::findByPk(2);

        $totalTime = QueryLogger::getTotalTime();
        $this->assertGreaterThanOrEqual(0, $totalTime);
    }

    #[Test]
    public function loggerGetSlowestQuery(): void
    {
        QueryLogger::enable();

        User::findByPk(1);
        User::find()->where('department_id', 1)->count();

        $slowest = QueryLogger::getSlowestQuery();
        $this->assertNotNull($slowest);
        $this->assertArrayHasKey('sql', $slowest);
        $this->assertArrayHasKey('time', $slowest);
    }

    #[Test]
    public function loggerGetSlowestQueryReturnsNullWhenEmpty(): void
    {
        QueryLogger::enable();

        $slowest = QueryLogger::getSlowestQuery();
        $this->assertNull($slowest);
    }

    #[Test]
    public function loggerReset(): void
    {
        QueryLogger::enable();

        User::findByPk(1);
        $this->assertEquals(1, QueryLogger::getQueryCount());

        QueryLogger::reset();
        $this->assertEquals(0, QueryLogger::getQueryCount());
        $this->assertEmpty(QueryLogger::getQueries());
    }

    #[Test]
    public function loggerCustomHandler(): void
    {
        $captured = [];

        QueryLogger::enable();
        QueryLogger::setHandler(function ($sql, $binds, $timeMs) use (&$captured) {
            $captured[] = ['sql' => $sql, 'binds' => $binds, 'time' => $timeMs];
        });

        User::findByPk(1);

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('user', $captured[0]['sql']);
        $this->assertGreaterThanOrEqual(0, $captured[0]['time']);

        // Reset handler
        QueryLogger::setHandler(function () {
        });
    }

    // ------------------------------------------------------------------
    // QueryMonitor (N+1 detection)
    // ------------------------------------------------------------------

    #[Test]
    public function monitorDisabledByDefault(): void
    {
        $this->assertFalse(QueryMonitor::isEnabled());
    }

    #[Test]
    public function monitorEnableAndDisable(): void
    {
        QueryMonitor::enable();
        $this->assertTrue(QueryMonitor::isEnabled());

        QueryMonitor::disable();
        $this->assertFalse(QueryMonitor::isEnabled());
    }

    #[Test]
    public function monitorDetectsRepeatedQueries(): void
    {
        $warnings = [];

        QueryMonitor::enable(3); // threshold = 3
        QueryMonitor::setHandler(function ($pattern, $count, $origin) use (&$warnings) {
            $warnings[] = ['pattern' => $pattern, 'count' => $count, 'origin' => $origin];
        });

        // Execute the same query pattern 3 times
        User::findByPk(1);
        User::findByPk(2);
        User::findByPk(3);

        // Should trigger at threshold (3rd query)
        $this->assertCount(1, $warnings);
        $this->assertEquals(3, $warnings[0]['count']);
    }

    #[Test]
    public function monitorDoesNotTriggerBelowThreshold(): void
    {
        $warnings = [];

        QueryMonitor::enable(5); // threshold = 5
        QueryMonitor::setHandler(function ($pattern, $count, $origin) use (&$warnings) {
            $warnings[] = $count;
        });

        // Only 3 queries — below threshold of 5
        User::findByPk(1);
        User::findByPk(2);
        User::findByPk(3);

        $this->assertEmpty($warnings);
    }

    #[Test]
    public function monitorGetDetectedPatterns(): void
    {
        QueryMonitor::enable(2); // low threshold for testing
        QueryMonitor::setHandler(function () {
        }); // suppress warnings

        User::findByPk(1);
        User::findByPk(2);

        $patterns = QueryMonitor::getDetectedPatterns();
        $this->assertNotEmpty($patterns);

        $firstPattern = array_values($patterns)[0];
        $this->assertArrayHasKey('count', $firstPattern);
        $this->assertArrayHasKey('origin', $firstPattern);
        $this->assertGreaterThanOrEqual(2, $firstPattern['count']);
    }

    #[Test]
    public function monitorDistinguishesDifferentPatterns(): void
    {
        QueryMonitor::enable(2);
        QueryMonitor::setHandler(function () {
        });

        // Two different query patterns
        User::findByPk(1);
        User::findByPk(2);
        Setting::findByPk(1);
        Setting::findByPk(2);

        $patterns = QueryMonitor::getDetectedPatterns();

        // Should detect 2 different patterns
        $this->assertCount(2, $patterns);
    }

    #[Test]
    public function monitorReset(): void
    {
        QueryMonitor::enable(2);
        QueryMonitor::setHandler(function () {
        });

        User::findByPk(1);
        User::findByPk(2);

        $this->assertNotEmpty(QueryMonitor::getDetectedPatterns());

        QueryMonitor::reset();
        $this->assertEmpty(QueryMonitor::getDetectedPatterns());
    }

    #[Test]
    public function monitorDoesNotRecordWhenDisabled(): void
    {
        // Disabled by default
        User::findByPk(1);
        User::findByPk(2);
        User::findByPk(3);

        QueryMonitor::enable(2);
        $patterns = QueryMonitor::getDetectedPatterns();
        $this->assertEmpty($patterns);
    }
}
