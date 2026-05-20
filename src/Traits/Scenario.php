<?php

namespace Simsoft\DB\Traits;

/**
 * Scenario trait.
 *
 * Adds context-aware behavior to a Model. Scenarios let the same model behave
 * differently for create, update, admin, or any custom workflow.
 *
 * Usage:
 *   class User extends Model {
 *       use Scenario;
 *       public const SCENARIO_REGISTER = 'register';
 *   }
 *
 *   $user = new User($data);
 *   $user->withScenario(User::SCENARIO_REGISTER)->save();
 */
trait Scenario
{
    /** @var int|string|null Scenario value. */
    protected int|string|null $scenario = null;

    /**
     * Set the active scenario.
     *
     * Pass null to clear the scenario.
     *
     * @param int|string|null $scenario The scenario identifier (or null to clear).
     * @return static
     */
    public function withScenario(int|string|null $scenario): static
    {
        $this->scenario = $scenario;
        return $this;
    }

    /**
     * Get the current scenario.
     *
     * @return int|string|null
     */
    public function getScenario(): int|string|null
    {
        return $this->scenario;
    }

    /**
     * Determine if the current scenario matches.
     *
     * Uses strict comparison — `isScenario(1)` will NOT match if the scenario
     * was set as the string `"1"`.
     *
     * @param int|string $scenario The scenario to check.
     * @return bool
     */
    public function isScenario(int|string $scenario): bool
    {
        return $this->scenario === $scenario;
    }

    /**
     * Determine if any scenario is currently active.
     *
     * @return bool
     */
    public function hasScenario(): bool
    {
        return $this->scenario !== null;
    }

    /**
     * Determine if the current scenario matches any of the given scenarios.
     *
     * @param int|string ...$scenarios One or more scenarios to check.
     * @return bool
     */
    public function isAnyScenario(int|string ...$scenarios): bool
    {
        return in_array($this->scenario, $scenarios, true);
    }
}
