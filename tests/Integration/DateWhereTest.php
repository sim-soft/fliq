<?php

namespace Integration;

use Models\Order;
use Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for whereDate, whereMonth, whereYear, whereTime methods.
 */
class DateWhereTest extends DatabaseTestCase
{
    #[Test]
    public function whereDateFiltersRecords(): void
    {
        $orders = Order::find()
            ->whereDate('ordered_at', '=', '2024-01-05')
            ->get();

        $this->assertCount(1, $orders);
        $this->assertEquals(1, $orders->first()->id);
    }

    #[Test]
    public function whereDateShorthandDefaultsToEquals(): void
    {
        $orders = Order::find()
            ->whereDate('ordered_at', '2024-01-05')
            ->get();

        $this->assertCount(1, $orders);
        $this->assertEquals(1, $orders->first()->id);
    }

    #[Test]
    public function whereDateWithGreaterThan(): void
    {
        $orders = Order::find()
            ->whereDate('ordered_at', '>=', '2024-05-20')
            ->get();

        $this->assertGreaterThanOrEqual(1, count($orders));
    }

    #[Test]
    public function whereMonthFiltersRecords(): void
    {
        // January orders
        $orders = Order::find()
            ->whereMonth('ordered_at', '=', 1)
            ->get();

        $this->assertGreaterThanOrEqual(3, count($orders));
    }

    #[Test]
    public function whereMonthShorthandDefaultsToEquals(): void
    {
        $orders = Order::find()
            ->whereMonth('ordered_at', 1)
            ->get();

        $this->assertGreaterThanOrEqual(3, count($orders));
    }

    #[Test]
    public function whereYearFiltersRecords(): void
    {
        $orders = Order::find()
            ->whereYear('ordered_at', '=', 2024)
            ->get();

        $this->assertCount(20, $orders);
    }

    #[Test]
    public function whereYearShorthandDefaultsToEquals(): void
    {
        $orders = Order::find()
            ->whereYear('ordered_at', 2024)
            ->get();

        $this->assertCount(20, $orders);
    }

    #[Test]
    public function whereTimeFiltersRecords(): void
    {
        // Order 1 was at 10:00:00
        $orders = Order::find()
            ->whereTime('ordered_at', '=', '10:00:00')
            ->get();

        $this->assertGreaterThanOrEqual(1, count($orders));
    }

    #[Test]
    public function whereTimeShorthandDefaultsToEquals(): void
    {
        $orders = Order::find()
            ->whereTime('ordered_at', '10:00:00')
            ->get();

        $this->assertGreaterThanOrEqual(1, count($orders));
    }

    #[Test]
    public function whereTimeWithGreaterThan(): void
    {
        $orders = Order::find()
            ->whereTime('ordered_at', '>=', '14:00:00')
            ->get();

        $this->assertGreaterThanOrEqual(1, count($orders));
    }

    #[Test]
    public function orWhereDateCombinesConditions(): void
    {
        $orders = Order::find()
            ->whereDate('ordered_at', '2024-01-05')
            ->orWhereDate('ordered_at', '2024-02-14')
            ->get();

        $this->assertCount(2, $orders);
    }

    #[Test]
    public function orWhereMonthCombinesConditions(): void
    {
        // January or February orders
        $orders = Order::find()
            ->whereMonth('ordered_at', 1)
            ->orWhereMonth('ordered_at', 2)
            ->get();

        $this->assertGreaterThanOrEqual(5, count($orders));
    }

    #[Test]
    public function orWhereYearCombinesConditions(): void
    {
        $orders = Order::find()
            ->whereYear('ordered_at', 2024)
            ->orWhereYear('ordered_at', 2023)
            ->get();

        // All orders are 2024, so should get all 20
        $this->assertCount(20, $orders);
    }

    #[Test]
    public function orWhereTimeCombinesConditions(): void
    {
        $orders = Order::find()
            ->whereTime('ordered_at', '10:00:00')
            ->orWhereTime('ordered_at', '09:00:00')
            ->get();

        $this->assertGreaterThanOrEqual(2, count($orders));
    }

    #[Test]
    public function combinedDatePartConditions(): void
    {
        // Orders in January 2024
        $orders = Order::find()
            ->whereYear('ordered_at', 2024)
            ->whereMonth('ordered_at', 1)
            ->get();

        $this->assertGreaterThanOrEqual(3, count($orders));
    }

    #[Test]
    public function whereDateOnUserCreatedColumn(): void
    {
        // Orders have explicit ordered_at dates in 2024
        $orders = Order::find()
            ->whereYear('ordered_at', '=', 2024)
            ->whereMonth('ordered_at', '=', 3)
            ->whereDate('ordered_at', '>=', '2024-03-01')
            ->get();

        $this->assertGreaterThanOrEqual(1, count($orders));
    }
}
