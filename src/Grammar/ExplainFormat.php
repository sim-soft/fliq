<?php

namespace Simsoft\DB\Grammar;

use InvalidArgumentException;

/**
 * Format validation shared by the grammars' EXPLAIN builders.
 *
 * Every engine names its own set of plan formats and rejects the rest, so a
 * format is only meaningful next to the driver it was written for. Passing one
 * through unchecked either produced a statement the server refused with a
 * message naming nothing the caller wrote, or — on MySQL, where the format was
 * dropped instead of emitted — a plan in a different format than was asked for,
 * with no indication that the request had been ignored.
 */
trait ExplainFormat
{
    /**
     * Get the driver name identifier.
     *
     * @return string
     */
    abstract public function getDriverName(): string;

    /**
     * Normalise an EXPLAIN format and reject one this engine cannot produce.
     *
     * @param string $format The requested format.
     * @param array<int, string> $supported The formats this engine accepts, lowercase.
     * @return string The lowercased format.
     * @throws InvalidArgumentException If the format is not supported.
     */
    protected function normaliseExplainFormat(string $format, array $supported): string
    {
        $normalised = strtolower(trim($format));

        if (!in_array($normalised, $supported, true)) {
            throw new InvalidArgumentException(sprintf(
                "Unsupported EXPLAIN format '%s' for %s. Supported formats: %s.",
                $format,
                $this->getDriverName(),
                implode(', ', $supported)
            ));
        }

        return $normalised;
    }
}
