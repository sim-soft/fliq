<?php

namespace Simsoft\DB\Traits;

/**
 * HasEvents trait.
 *
 * Adds event dispatching to Model. Supports creating, created, updating, updated,
 * saving, saved, deleting, deleted events.
 *
 * Usage:
 *   User::on('creating', function($model) { ... });
 *   User::observe(new UserObserver());
 */
trait HasEvents
{
    /** @var array<string, array<string, array<int, callable>>> Event listeners per model class */
    protected static array $eventListeners = [];

    /**
     * Register an event listener.
     *
     * @param string $event Event name (creating, created, updating, etc.)
     * @param callable $callback Receives the model instance. Return false to cancel (before events only).
     * @return void
     */
    public static function on(string $event, callable $callback): void
    {
        static::$eventListeners[static::class][$event][] = $callback;
    }

    /**
     * Register an observer instance.
     *
     * The observer should have methods named after events (creating, created, etc.)
     *
     * @param object $observer The observer instance.
     * @return void
     */
    public static function observe(object $observer): void
    {
        $events = ['creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted'];
        foreach ($events as $event) {
            if (method_exists($observer, $event)) {
                static::on($event, $observer->$event(...));
            }
        }
    }

    /**
     * Fire an event. Returns false if any listener cancels it.
     *
     * @param string $event The event name.
     * @return bool True if all listeners passed, false if cancelled.
     */
    protected function fireEvent(string $event): bool
    {
        $listeners = static::$eventListeners[static::class][$event] ?? [];
        foreach ($listeners as $listener) {
            if ($listener($this) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Remove all event listeners for this model class.
     *
     * @return void
     */
    public static function flushEvents(): void
    {
        unset(static::$eventListeners[static::class]);
    }
}
