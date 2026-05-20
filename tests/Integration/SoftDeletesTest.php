<?php

namespace Integration;

use Models\Task;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for SoftDeletes trait.
 *

 */
class SoftDeletesTest extends DatabaseTestCase
{
    // ------------------------------------------------------------------
    // Auto-scoping (find excludes soft-deleted)
    // ------------------------------------------------------------------

    #[Test]
    public function findExcludesSoftDeletedByDefault(): void
    {
        // Tasks 6 and 10 are soft-deleted
        $tasks = Task::find()->get()->all();

        // 10 total - 2 deleted = 8 visible
        $this->assertCount(8, $tasks);

        foreach ($tasks as $task) {
            $this->assertNull($task->deleted_at);
        }
    }

    #[Test]
    public function findByPkExcludesSoftDeleted(): void
    {
        // Task 6 is soft-deleted — should not be found via normal find
        $task = Task::findByPk(6);
        $this->assertNull($task);
    }

    #[Test]
    public function countExcludesSoftDeleted(): void
    {
        $count = Task::find()->count();
        $this->assertEquals(8, $count);
    }

    // ------------------------------------------------------------------
    // withTrashed() — includes soft-deleted
    // ------------------------------------------------------------------

    #[Test]
    public function withTrashedIncludesAll(): void
    {
        $tasks = Task::withTrashed()->get()->all();
        $this->assertCount(10, $tasks);
    }

    #[Test]
    public function withTrashedFindByPk(): void
    {
        /** @var \Models\Task $task */
        $task = Task::withTrashed()->where('id', 6)->first();
        $this->assertInstanceOf(Task::class, $task);
        $this->assertEquals('Old task to remove', $task->title);
        $this->assertNotNull($task->deleted_at);
    }

    #[Test]
    public function withTrashedCount(): void
    {
        $count = Task::withTrashed()->count();
        $this->assertEquals(10, $count);
    }

    // ------------------------------------------------------------------
    // onlyTrashed() — only soft-deleted records
    // ------------------------------------------------------------------

    #[Test]
    public function onlyTrashedReturnsDeletedOnly(): void
    {
        $trashed = Task::onlyTrashed()->get()->all();
        $this->assertCount(2, $trashed); // tasks 6 and 10

        foreach ($trashed as $task) {
            $this->assertNotNull($task->deleted_at);
        }
    }

    // ------------------------------------------------------------------
    // trashed() — check if model is soft-deleted
    // ------------------------------------------------------------------

    #[Test]
    public function trashedReturnsTrueForDeletedRecord(): void
    {
        /** @var \Models\Task $task */
        $task = Task::withTrashed()->where('id', 6)->first();
        $this->assertTrue($task->trashed());
    }

    #[Test]
    public function trashedReturnsFalseForActiveRecord(): void
    {
        $task = Task::findByPk(1);
        $this->assertInstanceOf(Task::class, $task);
        $this->assertFalse($task->trashed());
    }

    // ------------------------------------------------------------------
    // delete() — soft delete
    // ------------------------------------------------------------------

    #[Test]
    public function deleteSetsSoftDeleteTimestamp(): void
    {
        $task = Task::findByPk(9);
        $this->assertInstanceOf(Task::class, $task);
        $this->assertNull($task->deleted_at);

        $result = $task->delete();
        $this->assertTrue($result);
        $this->assertNotNull($task->deleted_at);
        $this->assertTrue($task->trashed());

        // Should no longer appear in normal find
        $notFound = Task::findByPk(9);
        $this->assertNull($notFound);

        // But visible with withTrashed
        /** @var \Models\Task $found */
        $found = Task::withTrashed()->where('id', 9)->first();
        $this->assertInstanceOf(Task::class, $found);

        // Restore for other tests
        $found->restore();
    }

    #[Test]
    public function deleteReturnsFalseOnNewModel(): void
    {
        $task = new Task();
        $task->fill(['user_id' => 1, 'title' => 'Unsaved']);
        $result = $task->delete();
        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // restore() — undo soft delete
    // ------------------------------------------------------------------

    #[Test]
    public function restoreUndoesSoftDelete(): void
    {
        // Soft-delete task 4, then restore it
        $task = Task::findByPk(4);
        $this->assertInstanceOf(Task::class, $task);
        $task->delete();
        $this->assertTrue($task->trashed());

        // Restore
        $result = $task->restore();
        $this->assertTrue($result);
        $this->assertNull($task->deleted_at);
        $this->assertFalse($task->trashed());

        // Should be visible again
        $found = Task::findByPk(4);
        $this->assertInstanceOf(Task::class, $found);
    }

    #[Test]
    public function restoreReturnsFalseOnNewModel(): void
    {
        $task = new Task();
        $result = $task->restore();
        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // forceDelete() — permanent delete
    // ------------------------------------------------------------------

    #[Test]
    public function forceDeleteRemovesPermanently(): void
    {
        // Create a task, then force delete it
        $task = new Task();
        $task->user_id = 1;
        $task->title = 'To be force deleted';
        $task->priority = 'low';
        $task->status = 'todo';
        $task->save();
        $taskId = $task->id;

        $result = $task->forceDelete();
        $this->assertTrue($result);

        // Gone even with withTrashed
        /** @var \Models\Task|null $gone */
        $gone = Task::withTrashed()->where('id', $taskId)->first();
        $this->assertNull($gone);
    }

    #[Test]
    public function forceDeleteReturnsFalseOnNewModel(): void
    {
        $task = new Task();
        $result = $task->forceDelete();
        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // Queries on soft-deleted data
    // ------------------------------------------------------------------

    #[Test]
    public function whereConditionsRespectSoftDeleteScope(): void
    {
        // Only non-deleted high priority tasks
        $tasks = Task::find()->where('priority', 'high')->get()->all();

        foreach ($tasks as $task) {
            $this->assertNull($task->deleted_at);
            $this->assertEquals('high', $task->priority);
        }
    }

    #[Test]
    public function aggregationsRespectSoftDeleteScope(): void
    {
        // Count should exclude soft-deleted
        $total = Task::find()->count();
        $totalWithTrashed = Task::withTrashed()->count();

        $this->assertLessThan($totalWithTrashed, $total);
        $this->assertEquals($total + 2, $totalWithTrashed);
    }
}
