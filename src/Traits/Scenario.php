<?php

namespace Simsoft\DB\MySQL\Traits;

/**
 * Scenario trait.
 */
trait Scenario
{
    /** @var int|string|null Scenario value. */
    protected static int|string|null $scenario = null;

    /**
     * Set scenario.
     *
     * @param int|string $scenario
     * @return $this
     */
    public function scenario(int|string $scenario): static
    {
        static::$scenario = $scenario;
        return $this;
    }

    /**
     * Get current scenario.
     *
     * @return int|string|null
     */
    public function getScenario(): int|string|null
    {
        return static::$scenario;
    }

    /**
     * Determines is scenario.
     *
     * @param int|string $scenario
     * @return bool
     */
    public function isScenario(int|string $scenario): bool
    {
        return static::$scenario === $scenario;
    }
}
