<?php

namespace Simsoft\DB\MySQL\Traits;

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
     * @param string $placeHolder The place holder value.
     */
    public function setPlaceHolder(string $placeHolder): self
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
