<?php

namespace Integration;

use Models\Task;
use Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for HasEvents trait (Model events/observers).
 */
class ModelEventsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        User::flushEvents();
        Task::flushEvents();
    }

    // ------------------------------------------------------------------
    // Creating / Created events
    // ------------------------------------------------------------------

    #[Test]
    public function creatingEventFiresBeforeInsert(): void
    {
        $fired = false;
        User::on('creating', function (User $model) use (&$fired) {
            $fired = true;
            $this->assertTrue($model->isNew());
        });

        $user = new User();
        $user->fill([
            'username' => 'event_create_test',
            'email' => 'event_create@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertTrue($fired);

        // Cleanup
        $user->delete();
    }

    #[Test]
    public function creatingEventCanCancelInsert(): void
    {
        User::on('creating', function () {
            return false;
        });

        $user = new User();
        $user->fill([
            'username' => 'should_not_exist',
            'email' => 'no@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $result = $user->save();

        $this->assertFalse($result);
        $this->assertTrue($user->isNew());
    }

    #[Test]
    public function createdEventFiresAfterInsert(): void
    {
        $fired = false;
        User::on('created', function (User $model) use (&$fired) {
            $fired = true;
            $this->assertTrue($model->exists());
        });

        $user = new User();
        $user->fill([
            'username' => 'event_created_test',
            'email' => 'event_created@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertTrue($fired);

        // Cleanup
        $user->delete();
    }

    // ------------------------------------------------------------------
    // Updating / Updated events
    // ------------------------------------------------------------------

    #[Test]
    public function updatingEventFiresBeforeUpdate(): void
    {
        $fired = false;
        User::on('updating', function (User $model) use (&$fired) {
            $fired = true;
            $this->assertTrue($model->exists());
        });

        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $originalScore = $user->score;
        $user->score = $originalScore + 1;
        $user->save();

        $this->assertTrue($fired);

        // Restore original
        $user->score = $originalScore;
        User::flushEvents();
        $user->save();
    }

    #[Test]
    public function updatingEventCanCancelUpdate(): void
    {
        User::on('updating', function () {
            return false;
        });

        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $user->score = 9999;
        $result = $user->save();

        $this->assertFalse($result);

        // Verify DB unchanged
        User::flushEvents();
        $fresh = User::findByPk(1);
        $this->assertInstanceOf(User::class, $fresh);
        $this->assertNotEquals(9999, $fresh->score);
    }

    #[Test]
    public function updatedEventFiresAfterUpdate(): void
    {
        $fired = false;
        User::on('updated', function (User $model) use (&$fired) {
            $fired = true;
        });

        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $originalScore = $user->score;
        $user->score = $originalScore + 1;
        $user->save();

        $this->assertTrue($fired);

        // Restore
        User::flushEvents();
        $user->score = $originalScore;
        $user->save();
    }

    // ------------------------------------------------------------------
    // Saving / Saved events
    // ------------------------------------------------------------------

    #[Test]
    public function savingEventFiresOnCreate(): void
    {
        $fired = false;
        User::on('saving', function () use (&$fired) {
            $fired = true;
        });

        $user = new User();
        $user->fill([
            'username' => 'saving_event_test',
            'email' => 'saving@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertTrue($fired);

        // Cleanup
        $user->delete();
    }

    #[Test]
    public function savingEventFiresOnUpdate(): void
    {
        $fired = false;
        User::on('saving', function () use (&$fired) {
            $fired = true;
        });

        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $originalScore = $user->score;
        $user->score = $originalScore + 1;
        $user->save();

        $this->assertTrue($fired);

        // Restore
        User::flushEvents();
        $user->score = $originalScore;
        $user->save();
    }

    #[Test]
    public function savingEventCanCancelSave(): void
    {
        User::on('saving', function () {
            return false;
        });

        $user = new User();
        $user->fill([
            'username' => 'saving_cancel_test',
            'email' => 'saving_cancel@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $result = $user->save();

        $this->assertFalse($result);
        $this->assertTrue($user->isNew());
    }

    #[Test]
    public function savedEventFiresAfterSuccessfulSave(): void
    {
        $fired = false;
        User::on('saved', function () use (&$fired) {
            $fired = true;
        });

        $user = new User();
        $user->fill([
            'username' => 'saved_event_test',
            'email' => 'saved@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertTrue($fired);

        // Cleanup
        $user->delete();
    }

    // ------------------------------------------------------------------
    // Deleting / Deleted events
    // ------------------------------------------------------------------

    #[Test]
    public function deletingEventFiresBeforeDelete(): void
    {
        $fired = false;
        User::on('deleting', function (User $model) use (&$fired) {
            $fired = true;
            $this->assertTrue($model->exists());
        });

        $user = new User();
        $user->fill([
            'username' => 'deleting_event_test',
            'email' => 'deleting@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        User::flushEvents();
        User::on('deleting', function (User $model) use (&$fired) {
            $fired = true;
            $this->assertTrue($model->exists());
        });

        $user->delete();
        $this->assertTrue($fired);
    }

    #[Test]
    public function deletingEventCanCancelDelete(): void
    {
        User::on('deleting', function () {
            return false;
        });

        $user = new User();
        $user->fill([
            'username' => 'deleting_cancel_test',
            'email' => 'deleting_cancel@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();
        $userId = $user->id;

        $result = $user->delete();
        $this->assertFalse($result);

        // Verify still exists
        User::flushEvents();
        $found = User::findByPk($userId);
        $this->assertInstanceOf(User::class, $found);

        // Cleanup
        $found->delete();
    }

    #[Test]
    public function deletedEventFiresAfterDelete(): void
    {
        $fired = false;
        User::on('deleted', function () use (&$fired) {
            $fired = true;
        });

        $user = new User();
        $user->fill([
            'username' => 'deleted_event_test',
            'email' => 'deleted@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();
        $user->delete();

        $this->assertTrue($fired);
    }

    // ------------------------------------------------------------------
    // SoftDeletes fires events
    // ------------------------------------------------------------------

    #[Test]
    public function softDeleteFiresDeletingAndDeletedEvents(): void
    {
        $deletingFired = false;
        $deletedFired = false;

        Task::on('deleting', function () use (&$deletingFired) {
            $deletingFired = true;
        });
        Task::on('deleted', function () use (&$deletedFired) {
            $deletedFired = true;
        });

        $task = Task::findByPk(3);
        $this->assertInstanceOf(Task::class, $task);
        $task->delete();

        $this->assertTrue($deletingFired);
        $this->assertTrue($deletedFired);

        // Restore
        $task->restore();
    }

    #[Test]
    public function softDeleteDeletingCanCancel(): void
    {
        Task::on('deleting', function () {
            return false;
        });

        $task = Task::findByPk(3);
        $this->assertInstanceOf(Task::class, $task);
        $result = $task->delete();

        $this->assertFalse($result);
        $this->assertFalse($task->trashed());
    }

    // ------------------------------------------------------------------
    // Observer pattern
    // ------------------------------------------------------------------

    #[Test]
    public function observerMethodsAreCalledForEvents(): void
    {
        $observer = new class {
            /** @var array<int, string> */
            public array $log = [];

            public function creating(object $model): void
            {
                $this->log[] = 'creating';
            }

            public function created(object $model): void
            {
                $this->log[] = 'created';
            }

            public function saving(object $model): void
            {
                $this->log[] = 'saving';
            }

            public function saved(object $model): void
            {
                $this->log[] = 'saved';
            }

            public function deleting(object $model): void
            {
                $this->log[] = 'deleting';
            }

            public function deleted(object $model): void
            {
                $this->log[] = 'deleted';
            }
        };

        User::observe($observer);

        $user = new User();
        $user->fill([
            'username' => 'observer_test',
            'email' => 'observer@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();
        $user->delete();

        $this->assertEquals(['saving', 'creating', 'created', 'saved', 'deleting', 'deleted'], $observer->log);
    }

    // ------------------------------------------------------------------
    // Event order
    // ------------------------------------------------------------------

    #[Test]
    public function eventOrderIsCorrectForCreate(): void
    {
        $order = [];

        User::on('saving', function () use (&$order) {
            $order[] = 'saving';
        });
        User::on('creating', function () use (&$order) {
            $order[] = 'creating';
        });
        User::on('created', function () use (&$order) {
            $order[] = 'created';
        });
        User::on('saved', function () use (&$order) {
            $order[] = 'saved';
        });

        $user = new User();
        $user->fill([
            'username' => 'order_test',
            'email' => 'order@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertEquals(['saving', 'creating', 'created', 'saved'], $order);

        // Cleanup
        $user->delete();
    }

    #[Test]
    public function eventOrderIsCorrectForUpdate(): void
    {
        $order = [];

        User::on('saving', function () use (&$order) {
            $order[] = 'saving';
        });
        User::on('updating', function () use (&$order) {
            $order[] = 'updating';
        });
        User::on('updated', function () use (&$order) {
            $order[] = 'updated';
        });
        User::on('saved', function () use (&$order) {
            $order[] = 'saved';
        });

        $user = User::findByPk(1);
        $this->assertInstanceOf(User::class, $user);
        $originalScore = $user->score;
        $user->score = $originalScore + 1;
        $user->save();

        $this->assertEquals(['saving', 'updating', 'updated', 'saved'], $order);

        // Restore
        User::flushEvents();
        $user->score = $originalScore;
        $user->save();
    }

    // ------------------------------------------------------------------
    // flushEvents
    // ------------------------------------------------------------------

    #[Test]
    public function flushEventsRemovesAllListeners(): void
    {
        $fired = false;
        User::on('creating', function () use (&$fired) {
            $fired = true;
        });

        User::flushEvents();

        $user = new User();
        $user->fill([
            'username' => 'flush_test',
            'email' => 'flush@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertFalse($fired);

        // Cleanup
        $user->delete();
    }

    // ------------------------------------------------------------------
    // Multiple listeners
    // ------------------------------------------------------------------

    #[Test]
    public function multipleListenersAreCalledInOrder(): void
    {
        $order = [];

        User::on('creating', function () use (&$order) {
            $order[] = 'first';
        });
        User::on('creating', function () use (&$order) {
            $order[] = 'second';
        });

        $user = new User();
        $user->fill([
            'username' => 'multi_listener_test',
            'email' => 'multi@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertEquals(['first', 'second'], $order);

        // Cleanup
        $user->delete();
    }

    #[Test]
    public function cancellingListenerStopsSubsequentListeners(): void
    {
        $secondCalled = false;

        User::on('creating', function () {
            return false;
        });
        User::on('creating', function () use (&$secondCalled) {
            $secondCalled = true;
        });

        $user = new User();
        $user->fill([
            'username' => 'cancel_chain_test',
            'email' => 'cancel_chain@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertFalse($secondCalled);
    }

    // ------------------------------------------------------------------
    // Events are class-specific
    // ------------------------------------------------------------------

    #[Test]
    public function eventsAreIsolatedPerModelClass(): void
    {
        $userFired = false;
        $taskFired = false;

        User::on('creating', function () use (&$userFired) {
            $userFired = true;
        });
        Task::on('creating', function () use (&$taskFired) {
            $taskFired = true;
        });

        $user = new User();
        $user->fill([
            'username' => 'isolation_test',
            'email' => 'isolation@test.com',
            'password' => 'secret',
            'role' => 'member',
            'score' => 0,
            'department_id' => 1,
            'status_code' => 1,
        ]);
        $user->save();

        $this->assertTrue($userFired);
        $this->assertFalse($taskFired);

        // Cleanup
        $user->delete();
    }
}
