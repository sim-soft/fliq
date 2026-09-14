<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Connection;
use Simsoft\DB\Model;

/**
 * @property int|null $id
 * @property string|null $name
 * @property int|null $score
 */
class ChunkedUser extends Model
{
    protected string $table = 'users';
    protected string $connection = 'chunk_test';
    protected array $fillable = ['name', 'score'];
}

/**
 * Chunked iteration must not lose records to repeated keys.
 *
 * Collection::lazy() fetches one page per query, and each page arrived keyed
 * from zero. Iterating with foreach hid that, but anything that materialised
 * the generator — iterator_to_array(), and so Collection::all() — let each page
 * overwrite the previous one and silently returned a single page.
 *
 * Runs against in-memory SQLite, so the row set is exact and known.
 */
class CollectionChunkKeyTest extends TestCase
{
    /** @var int Rows loaded into the scratch table. */
    private const ROWS = 10;

    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('chunk_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $driver = Connection::get('chunk_test');
        $driver->execute(new Raw(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, score INTEGER)'
        ));

        for ($i = 1; $i <= self::ROWS; ++$i) {
            $driver->execute(new Raw(
                'INSERT INTO users (name, score) VALUES (?, ?)',
                ['user' . $i, $i * 10]
            ));
        }
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * Chunk sizes that do and do not divide the row count evenly, plus one
     * larger than the whole set (a single page, which never had the bug).
     *
     * @return array<string, array{int}>
     */
    public static function chunkSizes(): array
    {
        return [
            'chunk 1' => [1],
            'chunk 3 (uneven)' => [3],
            'chunk 4 (uneven)' => [4],
            'chunk 5 (even)' => [5],
            'chunk 10 (exact)' => [10],
            'chunk 25 (oversized)' => [25],
        ];
    }

    #[Test]
    #[DataProvider('chunkSizes')]
    public function materialisingAChunkedIterationKeepsEveryRecord(int $size): void
    {
        $records = iterator_to_array(ChunkedUser::find()->get()->chunk($size));

        $this->assertCount(self::ROWS, $records);
    }

    #[Test]
    #[DataProvider('chunkSizes')]
    public function collectionAllKeepsEveryRecord(int $size): void
    {
        $this->assertCount(self::ROWS, ChunkedUser::find()->get()->chunk($size)->all());
    }

    #[Test]
    #[DataProvider('chunkSizes')]
    public function chunkedIterationNumbersItsKeysContinuously(int $size): void
    {
        $keys = array_keys(iterator_to_array(ChunkedUser::find()->get()->chunk($size)));

        $this->assertSame(range(0, self::ROWS - 1), $keys);
    }

    #[Test]
    #[DataProvider('chunkSizes')]
    public function everyRowAppearsExactlyOnce(int $size): void
    {
        $names = [];
        foreach (ChunkedUser::find()->get()->chunk($size) as $user) {
            $this->assertInstanceOf(ChunkedUser::class, $user);
            $names[] = (string)$user->name;
        }
        sort($names);

        $expected = [];
        for ($i = 1; $i <= self::ROWS; ++$i) {
            $expected[] = 'user' . $i;
        }
        sort($expected);

        $this->assertSame($expected, $names);
    }

    /**
     * foreach never lost records, and must not start doing so.
     */
    #[Test]
    #[DataProvider('chunkSizes')]
    public function iteratingWithForeachStillSeesEveryRecord(int $size): void
    {
        $seen = 0;
        foreach (ChunkedUser::find()->get()->chunk($size) as $_) {
            ++$seen;
        }

        $this->assertSame(self::ROWS, $seen);
    }

    #[Test]
    public function eachAppliesItsSizeWithoutLosingRecords(): void
    {
        $this->assertCount(self::ROWS, iterator_to_array(ChunkedUser::find()->each(3)));
    }

    #[Test]
    public function batchStillYieldsEveryRecord(): void
    {
        $seen = 0;
        foreach (ChunkedUser::find()->batch(3) as $chunk) {
            $seen += count($chunk);
        }

        $this->assertSame(self::ROWS, $seen);
    }

    // ------------------------------------------------------------------
    // Keys the caller chose are theirs
    // ------------------------------------------------------------------

    #[Test]
    public function indexByKeysAreNotRenumbered(): void
    {
        $keys = array_keys(iterator_to_array(
            ChunkedUser::find()->indexBy('name')->get()->chunk(3)
        ));
        sort($keys);

        $expected = [];
        for ($i = 1; $i <= self::ROWS; ++$i) {
            $expected[] = 'user' . $i;
        }
        sort($expected);

        $this->assertSame($expected, $keys);
    }

    #[Test]
    public function callableIndexByKeysAreNotRenumbered(): void
    {
        $collection = ChunkedUser::find()
            ->indexBy(static fn(array $row): string => 'k' . $row['id'])
            ->get()
            ->chunk(3)
            ->toArray();

        $keys = array_keys(iterator_to_array($collection));
        sort($keys);

        $this->assertCount(self::ROWS, $keys);
        $this->assertContains('k1', $keys);
        $this->assertContains('k10', $keys);
    }

    // ------------------------------------------------------------------
    // Transformations layered on top of chunking
    // ------------------------------------------------------------------

    #[Test]
    public function filterKeepsEveryMatchAcrossChunks(): void
    {
        $matched = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30)
        );

        // Scores are 10..100 in steps of 10, so seven exceed 30.
        $this->assertCount(7, $matched);
        $this->assertSame(range(0, 6), array_keys($matched), 'Filtered keys should be continuous.');
    }

    #[Test]
    public function mapKeepsEveryRecordAcrossChunks(): void
    {
        $mapped = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)->map(static fn(ChunkedUser $u): string => (string)$u->name)
        );

        $this->assertCount(self::ROWS, $mapped);
    }

    #[Test]
    public function pluckKeepsEveryValueAcrossChunks(): void
    {
        $plucked = iterator_to_array(ChunkedUser::find()->pluck('name')->chunk(3));

        $this->assertCount(self::ROWS, $plucked);
    }

    #[Test]
    public function reduceVisitsEveryRecord(): void
    {
        $sum = ChunkedUser::find()->get()->chunk(3)->reduce(
            static fn(int $carry, ChunkedUser $u): int => $carry + (int)$u->score,
            0
        );

        // 10 + 20 + ... + 100
        $this->assertSame(550, $sum);
    }

    #[Test]
    public function groupByVisitsEveryRecord(): void
    {
        $groups = ChunkedUser::find()->get()->chunk(3)->groupBy('name');

        $this->assertCount(self::ROWS, $groups);
    }

    #[Test]
    public function indexByOnTheCollectionVisitsEveryRecord(): void
    {
        $this->assertCount(self::ROWS, ChunkedUser::find()->get()->chunk(3)->indexBy('name'));
    }

    // ------------------------------------------------------------------
    // The single-query path
    // ------------------------------------------------------------------

    #[Test]
    public function anExplicitLimitStillBoundsTheIteration(): void
    {
        $this->assertCount(3, iterator_to_array(ChunkedUser::find()->limit(3)->get()->chunk(2)));
    }

    #[Test]
    public function anExplicitLimitKeepsItsOwnKeys(): void
    {
        $keys = array_keys(iterator_to_array(ChunkedUser::find()->limit(4)->get()->chunk(2)));

        $this->assertSame(range(0, 3), $keys);
    }

    #[Test]
    public function firstStillReturnsTheLeadingRecord(): void
    {
        $user = ChunkedUser::find()->get()->chunk(3)->first();

        $this->assertInstanceOf(ChunkedUser::class, $user);
        $this->assertSame('user1', $user->name);
    }

    #[Test]
    public function anEmptyResultYieldsNothing(): void
    {
        $collection = ChunkedUser::find()->where('score', '>', 100000)->get()->chunk(3);

        $this->assertSame([], iterator_to_array($collection));
        $this->assertTrue($collection->isEmpty());
    }

    // ------------------------------------------------------------------
    // filter() and map() compose rather than replace
    // ------------------------------------------------------------------

    /**
     * Scores are 10..100 in steps of 10, so "> 30" keeps 7 and "< 80" keeps 7,
     * and the two together keep exactly rows 40..70 — four of them.
     */
    #[Test]
    public function chainedFiltersApplyBoth(): void
    {
        $matched = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->score < 80)
        );

        $this->assertCount(4, $matched);
    }

    #[Test]
    public function chainedFiltersApplyBothInEitherOrder(): void
    {
        $matched = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->score < 80)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30)
        );

        $this->assertCount(4, $matched);
    }

    #[Test]
    public function threeFiltersAllApply(): void
    {
        $matched = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->score < 80)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->id > 5)
        );

        // Scores 40,50,60,70 are ids 4,5,6,7; of those only 6 and 7 exceed id 5.
        $this->assertCount(2, $matched);
    }

    #[Test]
    public function aSecondMapReceivesTheFirstMapsOutput(): void
    {
        $mapped = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)
                ->map(static fn(ChunkedUser $u): string => (string)$u->name)
                ->map(static fn(string $name): string => strtoupper($name))
        );

        $this->assertCount(self::ROWS, $mapped);
        $this->assertContains('USER1', $mapped);
        $this->assertContains('USER10', $mapped);
    }

    #[Test]
    public function aFilterAfterAMapReceivesTheMappedValue(): void
    {
        $matched = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)
                ->map(static fn(ChunkedUser $u): int => (int)$u->score)
                ->filter(static fn(int $score): bool => $score > 30)
        );

        $this->assertCount(7, $matched);
        $this->assertSame([40, 50, 60, 70, 80, 90, 100], array_values($matched));
    }

    #[Test]
    public function aMapAfterAFilterOnlySeesTheKeptRecords(): void
    {
        $mapped = iterator_to_array(
            ChunkedUser::find()->get()->chunk(3)
                ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 70)
                ->map(static fn(ChunkedUser $u): int => (int)$u->score)
        );

        $this->assertSame([80, 90, 100], array_values($mapped));
    }

    #[Test]
    public function chainingDoesNotMutateTheCollectionItCameFrom(): void
    {
        $source = ChunkedUser::find()->get()->chunk(3);
        $once = $source->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30);
        $twice = $once->filter(static fn(ChunkedUser $u): bool => (int)$u->score < 80);

        $this->assertCount(self::ROWS, iterator_to_array($source), 'Source must be untouched.');
        $this->assertCount(7, iterator_to_array($once), 'Intermediate must be untouched.');
        $this->assertCount(4, iterator_to_array($twice));
    }

    #[Test]
    public function isEmptyHonoursEveryFilterInTheChain(): void
    {
        $collection = ChunkedUser::find()->get()->chunk(3)
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30)
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score < 20);

        $this->assertTrue($collection->isEmpty());
        $this->assertFalse($collection->isNotEmpty());
    }

    #[Test]
    public function reduceSeesTheComposedPipeline(): void
    {
        $sum = ChunkedUser::find()->get()->chunk(3)
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30)
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score < 80)
            ->reduce(static fn(int $carry, ChunkedUser $u): int => $carry + (int)$u->score, 0);

        // 40 + 50 + 60 + 70
        $this->assertSame(220, $sum);
    }

    // ------------------------------------------------------------------
    // batch() reads through the same pipeline as everything else
    // ------------------------------------------------------------------

    #[Test]
    public function batchAppliesTheFilter(): void
    {
        $seen = 0;
        $collection = ChunkedUser::find()->get()
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30);

        foreach ($collection->batch(4) as $chunk) {
            $seen += count($chunk);
        }

        $this->assertSame(7, $seen);
    }

    #[Test]
    public function batchAppliesEveryFilterInTheChain(): void
    {
        $seen = 0;
        $collection = ChunkedUser::find()->get()
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30)
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score < 80);

        foreach ($collection->batch(4) as $chunk) {
            $seen += count($chunk);
        }

        $this->assertSame(4, $seen);
    }

    #[Test]
    public function batchAppliesTheMap(): void
    {
        $values = [];
        $collection = ChunkedUser::find()->get()
            ->map(static fn(ChunkedUser $u): int => (int)$u->score);

        foreach ($collection->batch(4) as $chunk) {
            foreach ($chunk as $value) {
                $values[] = $value;
            }
        }

        $this->assertSame([10, 20, 30, 40, 50, 60, 70, 80, 90, 100], $values);
    }

    #[Test]
    public function batchAgreesWithIterationOnTheSameCollection(): void
    {
        $collection = ChunkedUser::find()->get()
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30);

        $batched = 0;
        foreach ($collection->batch(4) as $chunk) {
            $batched += count($chunk);
        }

        $this->assertSame(count(iterator_to_array($collection)), $batched);
    }

    #[Test]
    public function batchOverAnExplicitLimitAppliesTheFilter(): void
    {
        $seen = 0;
        $collection = ChunkedUser::find()->limit(6)->get()
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30);

        foreach ($collection->batch(2) as $chunk) {
            $seen += count($chunk);
        }

        // The first six rows score 10..60; three of them exceed 30.
        $this->assertSame(3, $seen);
    }

    #[Test]
    public function batchYieldsNoEmptyChunksWhenAFilterRejectsEverything(): void
    {
        $chunks = 0;
        $collection = ChunkedUser::find()->get()
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 100000);

        foreach ($collection->batch(3) as $chunk) {
            ++$chunks;
        }

        $this->assertSame(0, $chunks, 'A rejected page must not surface as an empty batch.');
    }

    #[Test]
    public function batchKeepsPagingPastAFullyFilteredPage(): void
    {
        $values = [];
        // Page one is scores 10..30, all rejected; the survivors are all later.
        $collection = ChunkedUser::find()->get()
            ->filter(static fn(ChunkedUser $u): bool => (int)$u->score > 30);

        foreach ($collection->batch(3) as $chunk) {
            foreach ($chunk as $user) {
                $this->assertInstanceOf(ChunkedUser::class, $user);
                $values[] = (int)$user->score;
            }
        }

        $this->assertSame([40, 50, 60, 70, 80, 90, 100], $values);
    }

    #[Test]
    public function batchStillYieldsRawRecordsWithoutAPipeline(): void
    {
        $seen = 0;
        foreach (ChunkedUser::find()->get()->batch(4) as $chunk) {
            $seen += count($chunk);
        }

        $this->assertSame(self::ROWS, $seen);
    }
}
