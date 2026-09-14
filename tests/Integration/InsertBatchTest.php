<?php

namespace Integration;

use InvalidArgumentException;
use Models\Setting;
use Models\Tag;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for Model::insertBatch().
 *
 * It assembled its own multi-row statement out of a Raw rather than going
 * through Insert, so it carried none of the checks Insert had — and none of the
 * ones added alongside these tests.
 */
class InsertBatchTest extends DatabaseTestCase
{
    #[Test]
    public function insertBatchWritesEveryRow(): void
    {
        $before = Tag::find()->count();

        $count = Tag::insertBatch([
            ['name' => 'Batch One', 'slug' => 'batch-one'],
            ['name' => 'Batch Two', 'slug' => 'batch-two'],
            ['name' => 'Batch Three', 'slug' => 'batch-three'],
        ]);

        $this->assertSame(3, $count);
        $this->assertSame($before + 3, Tag::find()->count());

        $tag = Tag::find()->where(['slug' => 'batch-two'])->first();
        $this->assertInstanceOf(Tag::class, $tag);
        $this->assertSame('Batch Two', $tag->name);
    }

    #[Test]
    public function insertBatchChunksAndStillWritesEveryRow(): void
    {
        $before = Tag::find()->count();

        $count = Tag::insertBatch([
            ['name' => 'Chunk A', 'slug' => 'chunk-a'],
            ['name' => 'Chunk B', 'slug' => 'chunk-b'],
            ['name' => 'Chunk C', 'slug' => 'chunk-c'],
            ['name' => 'Chunk D', 'slug' => 'chunk-d'],
            ['name' => 'Chunk E', 'slug' => 'chunk-e'],
        ], 2);

        $this->assertSame(5, $count);
        $this->assertSame($before + 5, Tag::find()->count());
    }

    #[Test]
    public function insertBatchRefusesARecordNamingAColumnTheFirstDoesNot(): void
    {
        // The hand-built statement wrote every row against the first row's
        // columns, so a key only a later record carried was dropped. Against a
        // nullable column — as here — nothing surfaced: both rows were written,
        // insertBatch returned 2, and the value the caller supplied was simply
        // not in the database.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names columns the first row does not: value');

        Setting::insertBatch([
            ['group' => 'batch', 'key' => 'ragged.one'],
            ['group' => 'batch', 'key' => 'ragged.two', 'value' => 'supplied'],
        ]);
    }

    #[Test]
    public function insertBatchAcceptsARecordShortOfAColumnTheFirstHas(): void
    {
        // The other direction is a value the caller did not give, which reading
        // as null is reasonable and costs nothing.
        $count = Setting::insertBatch([
            ['group' => 'batch', 'key' => 'short.one', 'value' => 'given'],
            ['group' => 'batch', 'key' => 'short.two'],
        ]);

        $this->assertSame(2, $count);

        $short = Setting::find()->where(['key' => 'short.two'])->first();
        $this->assertInstanceOf(Setting::class, $short);
        $this->assertNull($short->value);
    }

    #[Test]
    public function insertBatchReturnsZeroForAnEmptyArray(): void
    {
        $this->assertSame(0, Tag::insertBatch([]));
    }
}
