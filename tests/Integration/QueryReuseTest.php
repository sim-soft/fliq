<?php

namespace Integration;

use Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\Insert;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;

/**
 * Query reuse and insert-id reporting, against a live server.
 *
 * Every expectation here is a row count or a value the database is asked for
 * separately, never the query object's own account of itself.
 */
class QueryReuseTest extends DatabaseTestCase
{
    /** @var array<int, string> Emails of scratch rows to remove. */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $email) {
            DB::raw('DELETE FROM user WHERE email = ?', [$email], 'mysql');
        }
        $this->scratch = [];
    }

    /**
     * How many rows the server says are in `user`.
     */
    private function totalUsers(): int
    {
        $rows = DB::query('SELECT COUNT(*) AS c FROM user', [], 'mysql');

        return (int)$rows[0]['c'];
    }

    /**
     * How many rows the server says match a score floor.
     */
    private function usersScoringAbove(int $score): int
    {
        $rows = DB::query('SELECT COUNT(*) AS c FROM user WHERE score > ?', [$score], 'mysql');

        return (int)$rows[0]['c'];
    }

    // ------------------------------------------------------------------
    // first() must not narrow the query it was called on
    // ------------------------------------------------------------------

    /**
     * The bug: first() applied limit(1) to the receiver, so every later read
     * of the same query returned one row and said nothing about it.
     *
     * @return array<string, array{callable(\Simsoft\DB\Builder\ActiveQuery): int}>
     */
    public static function readsOfAWholeTable(): array
    {
        return [
            'all()' => [fn($q): int => count(iterator_to_array($q->all()))],
            'getArray()' => [fn($q): int => count(iterator_to_array($q->getArray()))],
            'get()->all()' => [fn($q): int => count($q->get()->all())],
            'each()' => [fn($q): int => count(iterator_to_array($q->each(4)))],
            'cursor()' => [fn($q): int => count(iterator_to_array($q->cursor()))],
            'batch()' => [function ($q): int {
                $seen = 0;
                foreach ($q->batch(4) as $chunk) {
                    $seen += count($chunk);
                }
                return $seen;
            }],
        ];
    }

    #[Test]
    #[DataProvider('readsOfAWholeTable')]
    public function readingTheWholeTableAfterFirstStillReturnsEveryRow(callable $read): void
    {
        $expected = $this->totalUsers();
        $this->assertGreaterThan(1, $expected, 'Fixture must hold more than one user for this to mean anything.');

        $query = User::find();
        $query->first();

        $this->assertSame($expected, $read($query));
    }

    #[Test]
    #[DataProvider('readsOfAWholeTable')]
    public function readingTheWholeTableAfterHasRecordsStillReturnsEveryRow(callable $read): void
    {
        $expected = $this->totalUsers();

        $query = User::find();
        $query->hasRecords();

        $this->assertSame($expected, $read($query));
    }

    #[Test]
    public function countAfterFirstStillCountsEveryRow(): void
    {
        $query = User::find();
        $query->first();

        $this->assertSame($this->totalUsers(), $query->count());
    }

    #[Test]
    public function paginateAfterFirstStillFillsThePage(): void
    {
        $query = User::find();
        $query->first();

        $page = $query->paginate(3, 1);

        $this->assertSame($this->totalUsers(), $page->total);
        $this->assertCount(3, $page->data);
    }

    #[Test]
    public function chunkByIdAfterFirstStillVisitsEveryRow(): void
    {
        $query = User::find();
        $query->first();

        $seen = 0;
        $query->chunkById(4, function (array $rows) use (&$seen): void {
            $seen += count($rows);
        });

        $this->assertSame($this->totalUsers(), $seen);
    }

    /**
     * A conditional query must keep matching exactly what it matched before.
     */
    #[Test]
    public function aFilteredQueryStillMatchesItsRowsAfterFirst(): void
    {
        $expected = $this->usersScoringAbove(40);

        $query = User::find()->where('score', '>', 40);
        $query->first();

        $this->assertSame($expected, count(iterator_to_array($query->all())));
    }

    /**
     * The other direction: a limit the caller asked for must survive.
     */
    #[Test]
    public function anExplicitLimitSurvivesFirst(): void
    {
        $this->assertGreaterThan(3, $this->totalUsers());

        $query = User::find()->limit(3);
        $query->first();

        $this->assertCount(3, iterator_to_array($query->all()));
    }

    #[Test]
    public function firstStillReturnsExactlyOneMatchingRecord(): void
    {
        $user = User::find()->where('username', '=', 'alice')->first();

        $this->assertInstanceOf(User::class, $user);

        $rows = DB::query('SELECT id FROM user WHERE username = ?', ['alice'], 'mysql');
        $this->assertSame((int)$rows[0]['id'], $user->id);
    }

    #[Test]
    public function firstStillReturnsNullWhenNothingMatches(): void
    {
        $this->assertNull(User::find()->where('username', '=', 'no_such_user_at_all')->first());
    }

    // ------------------------------------------------------------------
    // hasRecords()
    // ------------------------------------------------------------------

    #[Test]
    public function hasRecordsAgreesWithTheServerWhenRowsMatch(): void
    {
        $this->assertGreaterThan(0, $this->usersScoringAbove(40));
        $this->assertTrue(User::find()->where('score', '>', 40)->hasRecords());
    }

    #[Test]
    public function hasRecordsAgreesWithTheServerWhenNothingMatches(): void
    {
        $this->assertSame(0, $this->usersScoringAbove(100000));
        $this->assertFalse(User::find()->where('score', '>', 100000)->hasRecords());
    }

    /**
     * The sub-query exists() that won the name collision must keep working.
     */
    #[Test]
    public function theSubQueryExistsConditionStillFiltersCorrectly(): void
    {
        $rows = DB::query('SELECT COUNT(DISTINCT user_id) AS c FROM post', [], 'mysql');
        $expected = (int)$rows[0]['c'];
        $this->assertGreaterThan(0, $expected);

        $sub = DB::table('post')->select('id')->whereColumn('post.user_id', '=', 'user.id');
        $matched = iterator_to_array(User::find()->exists($sub)->all());

        $this->assertCount($expected, $matched);
    }

    // ------------------------------------------------------------------
    // Chunked iteration must not lose records to repeated keys
    // ------------------------------------------------------------------

    /**
     * The bug: lazy() fetched each page as its own query, so every page was
     * keyed from zero again. foreach hid it, but materialising the generator
     * let each page overwrite the one before it — all() on a collection whose
     * chunk size was smaller than the result returned one chunk's worth of
     * records, silently, and reported no error.
     */
    #[Test]
    public function materialisingAChunkedIterationKeepsEveryRecord(): void
    {
        $expected = $this->totalUsers();
        $this->assertGreaterThan(4, $expected, 'Chunk size must be smaller than the result for this to mean anything.');

        $records = iterator_to_array(User::find()->each(4));

        $this->assertCount($expected, $records);
    }

    #[Test]
    public function collectionAllKeepsEveryRecordWhenChunkedSmall(): void
    {
        $expected = $this->totalUsers();

        $this->assertCount($expected, User::find()->get()->chunk(3)->all());
    }

    #[Test]
    public function chunkedIterationYieldsEveryRowExactlyOnce(): void
    {
        $rows = DB::query('SELECT id FROM user ORDER BY id', [], 'mysql');
        $expected = array_map(static fn(array $row): int => (int)$row['id'], $rows);

        $seen = array_map(
            static fn(User $user): int => (int)$user->id,
            array_values(iterator_to_array(User::find()->each(3)))
        );
        sort($seen);

        $this->assertSame($expected, $seen);
    }

    #[Test]
    public function chunkedIterationNumbersItsKeysContinuously(): void
    {
        $expected = $this->totalUsers();

        $keys = array_keys(iterator_to_array(User::find()->each(4)));

        $this->assertSame(range(0, $expected - 1), $keys);
    }

    /**
     * Keys the caller asked for by name are theirs, and must not be renumbered.
     */
    #[Test]
    public function indexByKeysSurviveChunkedIteration(): void
    {
        $rows = DB::query('SELECT username FROM user ORDER BY username', [], 'mysql');
        $expected = array_map(static fn(array $row): string => (string)$row['username'], $rows);

        $keys = array_keys(iterator_to_array(User::find()->indexBy('username')->get()->chunk(3)));
        sort($keys);

        $this->assertSame($expected, $keys);
    }

    #[Test]
    public function pluckKeepsEveryValueAcrossChunks(): void
    {
        $rows = DB::query('SELECT username FROM user ORDER BY username', [], 'mysql');
        $expected = array_map(static fn(array $row): string => (string)$row['username'], $rows);

        $plucked = array_values(iterator_to_array(User::find()->pluck('username')->chunk(3)));
        sort($plucked);

        $this->assertSame($expected, $plucked);
    }

    #[Test]
    public function filteringAChunkedCollectionKeepsEveryMatch(): void
    {
        $expected = $this->usersScoringAbove(40);
        $this->assertGreaterThan(3, $expected);

        $matched = iterator_to_array(
            User::find()->get()->chunk(3)->filter(static fn(User $user): bool => (int)$user->score > 40)
        );

        $this->assertCount($expected, $matched);
    }

    #[Test]
    public function mappingAChunkedCollectionKeepsEveryRecord(): void
    {
        $expected = $this->totalUsers();

        $mapped = iterator_to_array(
            User::find()->get()->chunk(3)->map(static fn(User $user): string => (string)$user->username)
        );

        $this->assertCount($expected, $mapped);
    }

    /**
     * An explicit limit takes the single-query path rather than paging, so it
     * must keep behaving as it did.
     */
    #[Test]
    public function anExplicitLimitStillBoundsAChunkedIteration(): void
    {
        $this->assertGreaterThan(3, $this->totalUsers());

        $this->assertCount(3, iterator_to_array(User::find()->limit(3)->get()->chunk(2)));
    }

    // ------------------------------------------------------------------
    // filter() and map() compose
    // ------------------------------------------------------------------

    /**
     * The bug: filter() and map() each had a single callback slot, so a second
     * call overwrote the first instead of composing with it. Chaining two
     * filters silently applied only the second one.
     */
    #[Test]
    public function chainedFiltersApplyEveryCondition(): void
    {
        $row = DB::query(
            "SELECT COUNT(*) c FROM user WHERE score > 40 AND role = 'member'",
            [],
            'mysql'
        );
        $expected = (int)$row[0]['c'];
        $this->assertGreaterThan(0, $expected);

        // Each condition alone must match more than the pair, or the test
        // would pass even with the callbacks overwriting one another.
        $this->assertGreaterThan($expected, $this->usersScoringAbove(40));

        $matched = iterator_to_array(
            User::find()->get()->chunk(3)
                ->filter(static fn(User $user): bool => (int)$user->score > 40)
                ->filter(static fn(User $user): bool => $user->role === 'member')
        );

        $this->assertCount($expected, $matched);
    }

    #[Test]
    public function chainedFiltersApplyEveryConditionInEitherOrder(): void
    {
        $row = DB::query(
            "SELECT COUNT(*) c FROM user WHERE score > 40 AND role = 'member'",
            [],
            'mysql'
        );
        $expected = (int)$row[0]['c'];

        $matched = iterator_to_array(
            User::find()->get()->chunk(3)
                ->filter(static fn(User $user): bool => $user->role === 'member')
                ->filter(static fn(User $user): bool => (int)$user->score > 40)
        );

        $this->assertCount($expected, $matched);
    }

    #[Test]
    public function aSecondMapReceivesTheFirstMapsOutput(): void
    {
        $rows = DB::query('SELECT UPPER(username) u FROM user ORDER BY username', [], 'mysql');
        $expected = array_map(static fn(array $row): string => (string)$row['u'], $rows);

        $mapped = array_values(iterator_to_array(
            User::find()->get()->chunk(3)
                ->map(static fn(User $user): string => (string)$user->username)
                ->map(static fn(string $name): string => strtoupper($name))
        ));
        sort($mapped);

        $this->assertSame($expected, $mapped);
    }

    #[Test]
    public function aFilterAfterAMapReceivesTheMappedValue(): void
    {
        $expected = $this->usersScoringAbove(40);

        $matched = iterator_to_array(
            User::find()->get()->chunk(3)
                ->map(static fn(User $user): int => (int)$user->score)
                ->filter(static fn(int $score): bool => $score > 40)
        );

        $this->assertCount($expected, $matched);
        foreach ($matched as $score) {
            $this->assertIsInt($score, 'The filter must run on the mapped value.');
        }
    }

    #[Test]
    public function chainingLeavesTheCollectionItCameFromAlone(): void
    {
        $total = $this->totalUsers();
        $above = $this->usersScoringAbove(40);
        $this->assertGreaterThan($above, $total);

        $source = User::find()->get()->chunk(3);
        $once = $source->filter(static fn(User $user): bool => (int)$user->score > 40);
        $twice = $once->filter(static fn(User $user): bool => $user->role === 'member');

        $this->assertCount($total, iterator_to_array($source));
        $this->assertCount($above, iterator_to_array($once));
        $this->assertLessThan($above, count(iterator_to_array($twice)));
    }

    // ------------------------------------------------------------------
    // batch() reads through the same pipeline
    // ------------------------------------------------------------------

    /**
     * The bug: batch() resolved raw records itself and never ran the
     * filter/map callbacks, so batching a filtered collection yielded every
     * row while iterating the same collection yielded only the matches.
     */
    #[Test]
    public function batchAppliesTheFilter(): void
    {
        $expected = $this->usersScoringAbove(40);
        $this->assertLessThan($this->totalUsers(), $expected);

        $collection = User::find()->get()
            ->filter(static fn(User $user): bool => (int)$user->score > 40);

        $seen = 0;
        foreach ($collection->batch(3) as $chunk) {
            $seen += count($chunk);
        }

        $this->assertSame($expected, $seen);
    }

    #[Test]
    public function batchAppliesTheMap(): void
    {
        $collection = User::find()->get()
            ->map(static fn(User $user): string => (string)$user->username);

        $values = [];
        foreach ($collection->batch(3) as $chunk) {
            foreach ($chunk as $value) {
                $this->assertIsString($value, 'batch() must yield mapped values.');
                $values[] = $value;
            }
        }

        $this->assertCount($this->totalUsers(), $values);
    }

    #[Test]
    public function batchAgreesWithIterationOverTheSameCollection(): void
    {
        $collection = User::find()->get()
            ->filter(static fn(User $user): bool => (int)$user->score > 40);

        $batched = 0;
        foreach ($collection->batch(3) as $chunk) {
            $batched += count($chunk);
        }

        $this->assertSame(count(iterator_to_array($collection)), $batched);
    }

    #[Test]
    public function batchYieldsNoChunkWhenTheFilterRejectsEverything(): void
    {
        $collection = User::find()->get()
            ->filter(static fn(User $user): bool => (int)$user->score > 100000);

        $chunks = 0;
        foreach ($collection->batch(3) as $chunk) {
            ++$chunks;
        }

        $this->assertSame(0, $chunks);
    }

    // ------------------------------------------------------------------
    // Insert ids
    // ------------------------------------------------------------------

    /**
     * The bug: MySQLi answered false while the PDO-backed drivers answered the
     * string '0', so getLastInsertId() returned null on one and "0" on the
     * others for the same "nothing was inserted" fact.
     */
    #[Test]
    public function aStatementThatInsertedNothingReportsNoInsertId(): void
    {
        $before = $this->totalUsers();

        // Runs successfully, writes no row.
        $statement = (new Raw(
            'INSERT INTO user (username, email, password, role, score, status_code) '
            . "SELECT 'x', 'x@reuse.test', 'x', 'member', 1, 1 FROM DUAL WHERE 1 = 0"
        ))->withConnection('mysql');
        $statement->execute();

        $this->assertSame($before, $this->totalUsers(), 'The statement must not have written a row.');
        $this->assertNull($statement->getLastInsertId());
    }

    #[Test]
    public function aConnectionThatHasInsertedNothingReportsNoInsertId(): void
    {
        Connection::disconnect('mysql');

        $this->assertFalse(Connection::get('mysql')->lastInsertId());
    }

    /**
     * The id a real insert reports must name the row it actually wrote.
     */
    #[Test]
    public function aRealInsertReportsTheIdOfTheRowItWrote(): void
    {
        $email = 'reuse_real@reuse.test';
        $this->scratch[] = $email;

        $insert = (new Insert('user', [
            'username' => 'reuse_real',
            'email' => $email,
            'password' => 'x',
            'role' => 'member',
            'score' => 7,
            'status_code' => 1,
        ]))->withConnection('mysql');
        $insert->execute();

        $id = $insert->getLastInsertId();
        $this->assertNotNull($id);

        $rows = DB::query('SELECT email FROM user WHERE id = ?', [$id], 'mysql');
        $this->assertCount(1, $rows, "Reported id $id names no row.");
        $this->assertSame($email, $rows[0]['email']);
    }

    #[Test]
    public function savingAModelStillPopulatesItsPrimaryKey(): void
    {
        $email = 'reuse_model@reuse.test';
        $this->scratch[] = $email;

        $user = new User([
            'username' => 'reuse_model',
            'email' => $email,
            'password' => 'x',
            'role' => 'member',
            'score' => 9,
            'status_code' => 1,
        ]);

        $this->assertTrue($user->save());
        $this->assertNotNull($user->id);

        $rows = DB::query('SELECT email FROM user WHERE id = ?', [$user->id], 'mysql');
        $this->assertCount(1, $rows);
        $this->assertSame($email, $rows[0]['email']);
    }
}
