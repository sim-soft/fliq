<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\CursorPaginator;

/**
 * The value object cursorPaginate() hands back.
 *
 * Its offset counterpart has been covered since it was written; this one
 * carries cursors rather than page numbers and had no test of its own, so
 * isEmpty() was never called by anything.
 */
class CursorPaginatorTest extends TestCase
{
    #[Test]
    public function constructorExposesEveryProperty(): void
    {
        $paginator = new CursorPaginator(
            data: [['id' => 1], ['id' => 2]],
            perPage: 10,
            nextCursor: 2,
            previousCursor: null,
            hasMore: true,
        );

        $this->assertSame([['id' => 1], ['id' => 2]], $paginator->data);
        $this->assertSame(10, $paginator->perPage);
        $this->assertSame(2, $paginator->nextCursor);
        $this->assertNull($paginator->previousCursor);
        $this->assertTrue($paginator->hasMore);
    }

    #[Test]
    public function countReturnsThePageSizeNotThePerPageLimit(): void
    {
        // A partial last page holds fewer rows than perPage allows, and count()
        // must describe what is actually there.
        $paginator = new CursorPaginator([['id' => 1], ['id' => 2]], 10, null, null, false);

        $this->assertSame(2, $paginator->count());
        $this->assertCount(2, $paginator);
    }

    #[Test]
    public function isEmptyIsTrueWithoutRows(): void
    {
        $paginator = new CursorPaginator([], 10, null, null, false);

        $this->assertTrue($paginator->isEmpty());
        $this->assertSame(0, $paginator->count());
    }

    #[Test]
    public function isEmptyIsFalseWithRows(): void
    {
        $paginator = new CursorPaginator([['id' => 1]], 10, null, null, false);

        $this->assertFalse($paginator->isEmpty());
    }

    /**
     * Rows that are falsy in their own right, which empty() would misread if
     * it were ever applied to an element rather than to the array.
     *
     * @return array<string, array{array<int, mixed>}>
     */
    public static function falsyRows(): array
    {
        return [
            'one zero' => [[0]],
            'one empty string' => [['']],
            'one empty array' => [[[]]],
            'one null' => [[null]],
            'one false' => [[false]],
        ];
    }

    /**
     * @param array<int, mixed> $data A page holding exactly one falsy row.
     */
    #[Test]
    #[DataProvider('falsyRows')]
    public function aPageOfFalsyRowsIsNotEmpty(array $data): void
    {
        $paginator = new CursorPaginator($data, 10, null, null, false);

        $this->assertFalse($paginator->isEmpty());
        $this->assertSame(1, $paginator->count());
    }

    #[Test]
    public function isEmptyAgreesWithCountForEveryPage(): void
    {
        foreach ([[], [['id' => 1]], [['id' => 1], ['id' => 2]]] as $data) {
            $paginator = new CursorPaginator($data, 10, null, null, false);

            $this->assertSame(
                $paginator->count() === 0,
                $paginator->isEmpty(),
                'isEmpty() must mean exactly "no rows on this page".'
            );
        }
    }

    #[Test]
    public function iteratingYieldsEveryRowInOrder(): void
    {
        $rows = [['id' => 1], ['id' => 2], ['id' => 3]];
        $paginator = new CursorPaginator($rows, 10, 3, null, true);

        $this->assertSame($rows, iterator_to_array($paginator));
    }

    #[Test]
    public function iteratingAnEmptyPageYieldsNothingRatherThanFailing(): void
    {
        $paginator = new CursorPaginator([], 10, null, null, false);

        $this->assertSame([], iterator_to_array($paginator));
    }

    #[Test]
    public function iteratingTwiceYieldsTheSameRows(): void
    {
        // getIterator() yields from an array rather than a consumed generator,
        // so a second foreach must not come back empty.
        $rows = [['id' => 1], ['id' => 2]];
        $paginator = new CursorPaginator($rows, 10, null, null, false);

        $this->assertSame($rows, iterator_to_array($paginator));
        $this->assertSame($rows, iterator_to_array($paginator));
    }

    #[Test]
    public function toArrayReportsTheWholeState(): void
    {
        $paginator = new CursorPaginator(
            data: [['id' => 4], ['id' => 5]],
            perPage: 2,
            nextCursor: 5,
            previousCursor: 3,
            hasMore: true,
        );

        $this->assertSame([
            'data' => [['id' => 4], ['id' => 5]],
            'per_page' => 2,
            'next_cursor' => 5,
            'previous_cursor' => 3,
            'has_more' => true,
        ], $paginator->toArray());
    }

    #[Test]
    public function toArrayCarriesStringCursors(): void
    {
        // The cursor is whatever the ordering column holds, so it is not
        // always an integer id.
        $paginator = new CursorPaginator([['name' => 'bob']], 1, 'bob', 'alice', true);

        $array = $paginator->toArray();

        $this->assertSame('bob', $array['next_cursor']);
        $this->assertSame('alice', $array['previous_cursor']);
    }

    #[Test]
    public function anEmptyPageReportsNoCursorsAndNoMore(): void
    {
        $paginator = new CursorPaginator([], 10, null, null, false);

        $array = $paginator->toArray();

        $this->assertSame([], $array['data']);
        $this->assertNull($array['next_cursor']);
        $this->assertNull($array['previous_cursor']);
        $this->assertFalse($array['has_more']);
    }

    #[Test]
    public function hasMoreIsIndependentOfIsEmpty(): void
    {
        // hasMore describes the query, isEmpty describes this page. A page can
        // hold rows with nothing after it, which is the ordinary last page.
        $last = new CursorPaginator([['id' => 9]], 10, null, 8, false);

        $this->assertFalse($last->isEmpty());
        $this->assertFalse($last->hasMore);
    }
}
