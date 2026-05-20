<?php

namespace Integration;

use Models\Task;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for Timestamps trait.
 */
class TimestampsTest extends DatabaseTestCase
{
    #[Test]
    public function insertSetsCreatedAtAndUpdatedAt(): void
    {
        $before = date('Y-m-d H:i:s');

        $task = new Task();
        $task->user_id = 1;
        $task->title = 'Timestamp insert test';
        $task->priority = 'low';
        $task->status = 'todo';
        $task->save();

        $after = date('Y-m-d H:i:s');

        $this->assertNotNull($task->created_at);
        $this->assertNotNull($task->updated_at);
        $this->assertGreaterThanOrEqual($before, $task->created_at);
        $this->assertLessThanOrEqual($after, $task->created_at);
        $this->assertEquals($task->created_at, $task->updated_at);

        // Cleanup
        $task->forceDelete();
    }

    #[Test]
    public function updateSetsUpdatedAtOnly(): void
    {
        $task = new Task();
        $task->user_id = 1;
        $task->title = 'Timestamp update test';
        $task->priority = 'medium';
        $task->status = 'todo';
        $task->save();

        $createdAt = $task->created_at;

        // Wait a moment to ensure different timestamp
        usleep(1100000); // 1.1 seconds

        $task->status = 'in_progress';
        $task->save();

        // Refresh from DB
        $refreshed = Task::findByPk($task->id);
        $this->assertNotNull($refreshed);

        // created_at should not change
        $this->assertEquals($createdAt, $refreshed->created_at);
        // updated_at should be newer
        $this->assertGreaterThanOrEqual($createdAt, $refreshed->updated_at);

        // Cleanup
        $refreshed->forceDelete();
    }

    #[Test]
    public function manualTimestampIsNotOverwritten(): void
    {
        $customTime = '2020-06-15 12:00:00';

        $task = new Task();
        $task->user_id = 1;
        $task->title = 'Manual timestamp test';
        $task->priority = 'low';
        $task->status = 'todo';
        $task->created_at = $customTime;
        $task->save();

        // Refresh from DB
        $refreshed = Task::findByPk($task->id);
        $this->assertNotNull($refreshed);

        // Manual created_at should be preserved
        $this->assertEquals($customTime, $refreshed->created_at);

        // Cleanup
        $refreshed->forceDelete();
    }

    #[Test]
    public function existingRecordsHaveTimestamps(): void
    {
        // Task 1 was seeded with specific timestamps
        $task = Task::findByPk(1);
        $this->assertNotNull($task);

        $this->assertEquals('2024-01-10 09:00:00', $task->created_at);
        $this->assertEquals('2024-01-15 14:00:00', $task->updated_at);
    }
}
