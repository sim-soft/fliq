<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;
use Simsoft\DB\Collection;
use Simsoft\DB\Connection;
use Simsoft\DB\Model;

/**
 * @property int|null $id
 * @property string|null $name
 * @property int|null $score
 */
class CollectionUser extends Model
{
    protected string $table = 'users';
    protected string $connection = 'coll_test';
    protected array $fillable = ['name', 'score'];
}

/**
 * Tests Collection class: count, iteration, Countable interface.
 */
class CollectionTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('coll_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $driver = Connection::get('coll_test');
        $driver->execute(new Raw('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, score INTEGER)'));
        $driver->execute(new Raw('INSERT INTO users (name, score) VALUES (?, ?)', ['Alice', 100]));
        $driver->execute(new Raw('INSERT INTO users (name, score) VALUES (?, ?)', ['Bob', 200]));
        $driver->execute(new Raw('INSERT INTO users (name, score) VALUES (?, ?)', ['Charlie', 150]));
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function countReturnsRecordCount(): void
    {
        $collection = CollectionUser::find()->get();
        $this->assertSame(3, $collection->count());
    }

    #[Test]
    public function countableInterfaceWorks(): void
    {
        $collection = CollectionUser::find()->get();
        $this->assertCount(3, $collection);
    }

    #[Test]
    public function iterationYieldsModels(): void
    {
        $collection = CollectionUser::find()->get();
        $items = iterator_to_array($collection);

        $this->assertCount(3, $items);
        $this->assertInstanceOf(CollectionUser::class, $items[0]);
        $this->assertSame('Alice', $items[0]->name);
    }

    #[Test]
    public function countWithCondition(): void
    {
        $collection = CollectionUser::find()->where('score', '>', 100)->get();
        $this->assertSame(2, $collection->count());
    }

    #[Test]
    public function emptyCollectionCountIsZero(): void
    {
        $collection = CollectionUser::find()->where('score', '>', 999)->get();
        $this->assertSame(0, $collection->count());
    }

    #[Test]
    public function countByUsesSpecificField(): void
    {
        $collection = CollectionUser::find()->get();
        $count = $collection->countBy('name');
        $this->assertSame(3, $count);
    }

    #[Test]
    public function filterReducesIterationResults(): void
    {
        $collection = CollectionUser::find()->get()
            ->filter(fn($user) => $user->score >= 150);

        $items = iterator_to_array($collection);
        $this->assertCount(2, $items);
    }

    #[Test]
    public function filterDoesNotMutateOriginal(): void
    {
        $original = CollectionUser::find()->get();
        $filtered = $original->filter(fn($user) => $user->score >= 200);

        $this->assertCount(3, iterator_to_array($original));
        $this->assertCount(1, iterator_to_array($filtered));
    }

    #[Test]
    public function mapTransformsRecords(): void
    {
        $collection = CollectionUser::find()->get()
            ->map(fn($user) => $user->name);

        $items = iterator_to_array($collection);
        $this->assertSame(['Alice', 'Bob', 'Charlie'], array_values($items));
    }

    #[Test]
    public function mapAndFilterCombine(): void
    {
        $collection = CollectionUser::find()->get()
            ->filter(fn($u) => $u->score >= 150)
            ->map(fn($u) => $u->name);

        $names = array_values(iterator_to_array($collection));
        $this->assertSame(['Bob', 'Charlie'], $names);
    }

    #[Test]
    public function reduceSumsValues(): void
    {
        $total = CollectionUser::find()->get()
            ->reduce(fn($carry, $user) => $carry + $user->score, 0);

        $this->assertSame(450, $total);
    }

    #[Test]
    public function reduceWithStringConcat(): void
    {
        $names = CollectionUser::find()->get()
            ->reduce(fn($carry, $u) => $carry . $u->name . ',', '');

        $this->assertSame('Alice,Bob,Charlie,', $names);
    }

    #[Test]
    public function indexByIndexesByAttribute(): void
    {
        $byName = CollectionUser::find()->get()->indexBy('name');

        $this->assertArrayHasKey('Alice', $byName);
        $this->assertArrayHasKey('Bob', $byName);
        $this->assertSame(100, $byName['Alice']->score);
    }

    #[Test]
    public function indexByCallback(): void
    {
        $byScore = CollectionUser::find()->get()
            ->indexBy(fn($u) => 'user_' . $u->id);

        $this->assertArrayHasKey('user_1', $byScore);
        $this->assertArrayHasKey('user_2', $byScore);
    }

    #[Test]
    public function groupByGroupsRecords(): void
    {
        // Add a duplicate score
        $driver = Connection::get('coll_test');
        $driver->execute(new Raw('INSERT INTO users (name, score) VALUES (?, ?)', ['Dave', 100]));

        $byScore = CollectionUser::find()->get()->groupBy('score');

        $this->assertCount(2, $byScore[100]); // Alice + Dave
        $this->assertCount(1, $byScore[200]); // Bob
    }

    #[Test]
    public function pluckReturnsAttributes(): void
    {
        $names = CollectionUser::find()->get()->pluck('name');

        $items = iterator_to_array($names);
        $this->assertSame(['Alice', 'Bob', 'Charlie'], array_values($items));
    }

    #[Test]
    public function pageReturnsCorrectRecords(): void
    {
        $page1 = CollectionUser::find()->orderBy('id')->get()->page(1, 2);
        $this->assertCount(2, $page1);

        $page2 = CollectionUser::find()->orderBy('id')->get()->page(2, 2);
        $this->assertCount(1, $page2);
    }

    #[Test]
    public function pageAppliesFilter(): void
    {
        $page = CollectionUser::find()->orderBy('id')->get()
            ->filter(fn($u) => $u->score >= 150)
            ->page(1, 10);

        $this->assertCount(2, $page);
    }

    #[Test]
    public function pageAppliesMap(): void
    {
        $page = CollectionUser::find()->orderBy('id')->get()
            ->map(fn($u) => $u->name)
            ->page(1, 10);

        $this->assertSame(['Alice', 'Bob', 'Charlie'], array_values($page));
    }

    #[Test]
    public function batchYieldsBatches(): void
    {
        $batches = [];
        foreach (CollectionUser::find()->orderBy('id')->get()->batch(2) as $batch) {
            $batches[] = $batch;
        }

        $this->assertCount(2, $batches);
        $this->assertCount(2, $batches[0]); // First batch: 2 items
        $this->assertCount(1, $batches[1]); // Second batch: 1 item
    }

    #[Test]
    public function batchDoesNotMutateChunkSize(): void
    {
        $collection = CollectionUser::find()->orderBy('id')->get()->chunk(50);

        // Iterate via batch with different size
        foreach ($collection->batch(2) as $_batch) {
            // consume
        }

        // Should still iterate via the original chunk size (50 = all 3 in one fetch)
        $items = iterator_to_array($collection);
        $this->assertCount(3, $items);
    }

    #[Test]
    public function isEmptyWithFilter(): void
    {
        $empty = CollectionUser::find()->get()
            ->filter(fn($u) => $u->score > 999);
        $this->assertTrue($empty->isEmpty());

        $notEmpty = CollectionUser::find()->get()
            ->filter(fn($u) => $u->score >= 100);
        $this->assertFalse($notEmpty->isEmpty());
    }

    #[Test]
    public function firstReturnsFirstRecord(): void
    {
        $first = CollectionUser::find()->orderBy('id')->get()->first();
        $this->assertSame('Alice', $first->name);
    }

    #[Test]
    public function firstWithFilterReturnsFirstMatching(): void
    {
        $first = CollectionUser::find()->orderBy('id')->get()
            ->filter(fn($u) => $u->score >= 150)
            ->first();
        $this->assertSame('Bob', $first->name);
    }

    #[Test]
    public function firstReturnsNullWhenNoRecords(): void
    {
        $first = CollectionUser::find()->where('score', '>', 999)->get()->first();
        $this->assertNull($first);
    }
}

