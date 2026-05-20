<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Paginator;

class PaginatorTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $paginator = new Paginator(
            data: [['id' => 1], ['id' => 2]],
            total: 50,
            perPage: 10,
            currentPage: 2,
            lastPage: 5,
        );

        $this->assertCount(2, $paginator->data);
        $this->assertSame(50, $paginator->total);
        $this->assertSame(10, $paginator->perPage);
        $this->assertSame(2, $paginator->currentPage);
        $this->assertSame(5, $paginator->lastPage);
    }

    #[Test]
    public function hasMorePagesReturnsTrueWhenNotOnLastPage(): void
    {
        $paginator = new Paginator([], 100, 10, 3, 10);
        $this->assertTrue($paginator->hasMorePages());
    }

    #[Test]
    public function hasMorePagesReturnsFalseOnLastPage(): void
    {
        $paginator = new Paginator([], 100, 10, 10, 10);
        $this->assertFalse($paginator->hasMorePages());
    }

    #[Test]
    public function isFirstPageReturnsTrue(): void
    {
        $paginator = new Paginator([], 100, 10, 1, 10);
        $this->assertTrue($paginator->isFirstPage());
    }

    #[Test]
    public function isFirstPageReturnsFalse(): void
    {
        $paginator = new Paginator([], 100, 10, 2, 10);
        $this->assertFalse($paginator->isFirstPage());
    }

    #[Test]
    public function isLastPageReturnsTrue(): void
    {
        $paginator = new Paginator([], 100, 10, 10, 10);
        $this->assertTrue($paginator->isLastPage());
    }

    #[Test]
    public function isLastPageReturnsFalse(): void
    {
        $paginator = new Paginator([], 100, 10, 5, 10);
        $this->assertFalse($paginator->isLastPage());
    }

    #[Test]
    public function countReturnsItemCount(): void
    {
        $paginator = new Paginator([['id' => 1], ['id' => 2], ['id' => 3]], 30, 10, 1, 3);
        $this->assertSame(3, $paginator->count());
    }

    #[Test]
    public function isEmptyReturnsTrueForEmptyData(): void
    {
        $paginator = new Paginator([], 0, 10, 1, 1);
        $this->assertTrue($paginator->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseForNonEmptyData(): void
    {
        $paginator = new Paginator([['id' => 1]], 1, 10, 1, 1);
        $this->assertFalse($paginator->isEmpty());
    }

    #[Test]
    public function toArrayReturnsStructuredResult(): void
    {
        $paginator = new Paginator(
            data: [['id' => 1], ['id' => 2]],
            total: 20,
            perPage: 5,
            currentPage: 2,
            lastPage: 4,
        );

        $array = $paginator->toArray();

        $this->assertSame([['id' => 1], ['id' => 2]], $array['data']);
        $this->assertSame(20, $array['total']);
        $this->assertSame(5, $array['per_page']);
        $this->assertSame(2, $array['current_page']);
        $this->assertSame(4, $array['last_page']);
        $this->assertTrue($array['has_more_pages']);
    }

    #[Test]
    public function toArrayHasMorePagesFalseOnLastPage(): void
    {
        $paginator = new Paginator([], 10, 10, 1, 1);
        $array = $paginator->toArray();
        $this->assertFalse($array['has_more_pages']);
    }

    #[Test]
    public function singleItemPagination(): void
    {
        $paginator = new Paginator([['id' => 1]], 1, 15, 1, 1);

        $this->assertSame(1, $paginator->count());
        $this->assertTrue($paginator->isFirstPage());
        $this->assertTrue($paginator->isLastPage());
        $this->assertFalse($paginator->hasMorePages());
    }

    #[Test]
    public function nextPageReturnsNextPageNumber(): void
    {
        $paginator = new Paginator([['id' => 1]], 100, 10, 3, 10);
        $this->assertSame(4, $paginator->nextPage());
    }

    #[Test]
    public function nextPageReturnsNullOnLastPage(): void
    {
        $paginator = new Paginator([['id' => 1]], 100, 10, 10, 10);
        $this->assertNull($paginator->nextPage());
    }

    #[Test]
    public function previousPageReturnsPreviousPageNumber(): void
    {
        $paginator = new Paginator([['id' => 1]], 100, 10, 5, 10);
        $this->assertSame(4, $paginator->previousPage());
    }

    #[Test]
    public function previousPageReturnsNullOnFirstPage(): void
    {
        $paginator = new Paginator([['id' => 1]], 100, 10, 1, 10);
        $this->assertNull($paginator->previousPage());
    }

    #[Test]
    public function fromAndToCalculateRanges(): void
    {
        // Page 2 of 10-per-page = items 11-20
        $paginator = new Paginator(
            data: array_fill(0, 10, ['id' => 0]),
            total: 100,
            perPage: 10,
            currentPage: 2,
            lastPage: 10,
        );

        $this->assertSame(11, $paginator->from());
        $this->assertSame(20, $paginator->to());
    }

    #[Test]
    public function fromAndToOnLastPagePartial(): void
    {
        // Page 10 of 10-per-page with 95 total = items 91-95
        $paginator = new Paginator(
            data: array_fill(0, 5, ['id' => 0]),
            total: 95,
            perPage: 10,
            currentPage: 10,
            lastPage: 10,
        );

        $this->assertSame(91, $paginator->from());
        $this->assertSame(95, $paginator->to());
    }

    #[Test]
    public function fromAndToReturnNullWhenEmpty(): void
    {
        $paginator = new Paginator([], 0, 10, 1, 1);
        $this->assertNull($paginator->from());
        $this->assertNull($paginator->to());
    }

    #[Test]
    public function paginatorIsIterable(): void
    {
        $paginator = new Paginator([['id' => 1], ['id' => 2], ['id' => 3]], 3, 10, 1, 1);

        $items = [];
        foreach ($paginator as $item) {
            $items[] = $item;
        }

        $this->assertCount(3, $items);
        $this->assertSame(['id' => 1], $items[0]);
    }

    #[Test]
    public function countableInterfaceWorks(): void
    {
        $paginator = new Paginator([['id' => 1], ['id' => 2]], 2, 10, 1, 1);
        $this->assertCount(2, $paginator);
    }

    #[Test]
    public function toArrayIncludesFromAndTo(): void
    {
        $paginator = new Paginator(
            data: [['id' => 1], ['id' => 2]],
            total: 20,
            perPage: 5,
            currentPage: 2,
            lastPage: 4,
        );

        $array = $paginator->toArray();
        $this->assertSame(6, $array['from']);
        $this->assertSame(7, $array['to']);
    }
}
