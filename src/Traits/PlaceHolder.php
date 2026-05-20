<?php

namespace Simsoft\DB\Traits;

/**
 * PlaceHolder Trait
 *
 */
trait PlaceHolder
{
    /** @var string Query's value placeholder */
    private string $placeHolder = '?';


    /**
     * Set placeholder
     *
     * @param string $placeHolder The placeholder value.
     * @return static
     */
    public function setPlaceHolder(string $placeHolder): static
    {
        $this->placeHolder = $placeHolder;
        return $this;
    }

    /**
     * Get current place holder value.
     *
     * @return string
     */
    public function getPlaceHolder(): string
    {
        return $this->placeHolder;
    }
}
