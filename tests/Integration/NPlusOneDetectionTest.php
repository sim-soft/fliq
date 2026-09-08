<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\DB;
use Simsoft\DB\QueryLogger;
use Simsoft\DB\QueryMonitor;

/**
 * The N+1 detector against a real database.
 *
 * QueryMonitor's whole purpose is to notice a lazy-loading loop and name the
 * line that wrote it. The unit tests drive recordQuery() directly; these run the
 * loop the documentation describes, through the ORM, against MySQL, and check
 * that what comes out is both a detection and an address worth acting on.
 */
class NPlusOneDetectionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
     * Collect what the handler is told, instead of raising a warning.
     *
     * @param array<int, array{pattern: string, count: int, origin: string}> $seen
     */
    private function captureInto(array &$seen, int $threshold): void
    {
        QueryMonitor::enable($threshold);
        QueryMonitor::setHandler(function (string $pattern, int $count, string $origin) use (&$seen): void {
            $seen[] = ['pattern' => $pattern, 'count' => $count, 'origin' => $origin];
        });
    }

    /**
     * The library's own directory, with a trailing separator.
     *
     * @return non-empty-string
     */
    private function libraryRoot(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    }

    /**
     * How many times the first detected pattern was seen.
     *
     * @param array<string, array{count: int, origin: string}> $detected
     */
    private function firstCount(array $detected): int
    {
        $first = reset($detected);

        return $first === false ? 0 : $first['count'];
    }

    #[Test]
    public function aLazyLoadingLoopIsDetected(): void
    {
        $seen = [];
        $this->captureInto($seen, 5);

        $users = User::find()->get();
        foreach ($users as $user) {
            foreach ($user->posts as $post) {
                $this->assertNotNull($post->id);
            }
        }

        $detected = QueryMonitor::getDetectedPatterns();

        $this->assertNotEmpty($detected, 'the documented N+1 loop produced no detection');
        $this->assertCount(1, $seen, 'the handler should be told once, when the threshold is crossed');

        $pattern = array_key_first($detected);
        $this->assertStringContainsString('`post`', (string)$pattern);
        $this->assertStringContainsString('`user_id` = ?', (string)$pattern);
    }

    #[Test]
    public function theDetectionNamesTheLineThatCausedIt(): void
    {
        $seen = [];
        $this->captureInto($seen, 3);

        foreach (User::find()->get() as $user) {
            foreach ($user->posts as $post) {
                $this->assertNotNull($post->id);
            }
        }
        $loop = __LINE__ - 4;

        $this->assertNotEmpty($seen);

        // The origin has to be the loop, not the library: the previous
        // implementation reported QueryMonitor.php's own line for every query,
        // which named a file the caller cannot change.
        $this->assertSame(__FILE__ . ':' . $loop, $seen[0]['origin']);
    }

    #[Test]
    public function noDetectedOriginPointsInsideTheLibrary(): void
    {
        $seen = [];
        $this->captureInto($seen, 3);

        foreach (User::find()->get() as $user) {
            foreach ($user->posts as $post) {
                $this->assertNotNull($post->id);
            }
        }

        $this->assertNotEmpty($seen);
        foreach (QueryMonitor::getDetectedPatterns() as $info) {
            $this->assertStringStartsNotWith($this->libraryRoot(), $info['origin']);
        }
    }

    #[Test]
    public function eagerLoadingProducesNoDetection(): void
    {
        $seen = [];
        $this->captureInto($seen, 3);

        // The fix for an N+1 must actually clear the detector, or the warning
        // is noise that cannot be silenced.
        foreach (User::find()->with('posts')->get() as $user) {
            foreach ($user->posts as $post) {
                $this->assertNotNull($post->id);
            }
        }

        $this->assertSame([], $seen);
        $this->assertSame([], QueryMonitor::getDetectedPatterns());
    }

    #[Test]
    public function repeatedInlineLiteralsGroupIntoOnePattern(): void
    {
        $seen = [];
        $this->captureInto($seen, 3);

        for ($id = 1; $id <= 6; $id++) {
            DB::query("SELECT * FROM user WHERE id = $id", [], 'mysql');
        }

        $detected = QueryMonitor::getDetectedPatterns();

        $this->assertCount(1, $detected, 'six queries differing only in the id should be one pattern');
        $this->assertSame(6, $this->firstCount($detected));
        $this->assertSame('SELECT * FROM user WHERE id = ?', array_key_first($detected));
    }

    #[Test]
    public function queriesAgainstDifferentTablesDoNotGroup(): void
    {
        $seen = [];
        $this->captureInto($seen, 100);

        DB::query('SELECT * FROM user WHERE id = 1', [], 'mysql');
        DB::query('SELECT * FROM post WHERE id = 1', [], 'mysql');
        DB::query('SELECT * FROM tag WHERE id = 1', [], 'mysql');

        $this->assertSame([], $seen, 'nothing should reach the threshold');
    }

    #[Test]
    public function theMonitorAndTheLoggerSeeTheSameQueries(): void
    {
        $seen = [];
        $this->captureInto($seen, 5);
        QueryLogger::enable();

        foreach (User::find()->get() as $user) {
            foreach ($user->posts as $post) {
                $this->assertNotNull($post->id);
            }
        }

        $logged = QueryLogger::getQueries();
        $relationQueries = array_filter(
            $logged,
            static fn(array $q): bool => str_contains($q['sql'], '`user_id` = ?')
        );

        $detected = QueryMonitor::getDetectedPatterns();
        $this->assertNotEmpty($detected);

        // Both observers are called from the same place in Execute::runQuery(),
        // so a query one of them recorded is one the other recorded too.
        $this->assertSame(count($relationQueries), $this->firstCount($detected));
    }

    #[Test]
    public function aClearedHandlerStopsReceivingRealQueries(): void
    {
        $seen = [];
        $this->captureInto($seen, 2);

        DB::query('SELECT * FROM user WHERE id = 1', [], 'mysql');
        DB::query('SELECT * FROM user WHERE id = 2', [], 'mysql');
        $this->assertCount(1, $seen);

        QueryMonitor::clearHandler();
        QueryMonitor::reset();

        set_error_handler(static fn(): bool => true, E_USER_WARNING);

        try {
            DB::query('SELECT * FROM user WHERE id = 3', [], 'mysql');
            DB::query('SELECT * FROM user WHERE id = 4', [], 'mysql');
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $seen, 'the cleared handler was called again');
    }

    #[Test]
    public function aClearedLoggerHandlerStopsReceivingRealQueries(): void
    {
        $calls = 0;
        QueryLogger::enable();
        QueryLogger::setHandler(function () use (&$calls): void {
            $calls++;
        });

        DB::query('SELECT 1 AS x', [], 'mysql');
        $this->assertGreaterThan(0, $calls);

        $before = $calls;
        QueryLogger::clearHandler();
        DB::query('SELECT 1 AS x', [], 'mysql');

        $this->assertSame($before, $calls);
        $this->assertNotEmpty(QueryLogger::getQueries(), 'logging itself should continue');
    }

    #[Test]
    public function aValueContainingAnApostropheStillGroups(): void
    {
        $seen = [];
        $this->captureInto($seen, 3);

        // Round-tripped through the database so the escaping is real: these are
        // three lookups of the same shape, and they must count as three.
        foreach (["o''brien", "d''arcy", "smith"] as $name) {
            DB::query("SELECT * FROM user WHERE username = '$name'", [], 'mysql');
        }

        $detected = QueryMonitor::getDetectedPatterns();

        $this->assertCount(1, $detected);
        $this->assertSame('SELECT * FROM user WHERE username = ?', array_key_first($detected));
    }
}
