<?php

namespace Simsoft\DB\Grammar;

use InvalidArgumentException;

/**
 * Mode validation shared by the grammars' full-text search builders.
 *
 * Three modes are supported, and each engine maps them onto its own syntax.
 * Anything else used to fall through to the plain branch, which is not a
 * wording difference: on MySQL a caller asking for 'boolean' — MySQL's own
 * name for the mode — was answered IN NATURAL LANGUAGE MODE, where '+' and
 * '-' are stripped as punctuation. Searching '+database -systems' returned
 * every row instead of the two that satisfy it, with nothing to indicate the
 * mode had been ignored.
 */
trait FulltextMode
{
    /** @var array<int, string> The search modes every grammar understands. */
    private const FULLTEXT_MODES = ['plain', 'phrase', 'websearch'];

    /**
     * Get the driver name identifier.
     *
     * @return string
     */
    abstract public function getDriverName(): string;

    /**
     * Normalise a search mode and reject one this grammar cannot express.
     *
     * Case is normalised, as it is for EXPLAIN formats: 'Phrase' names the
     * phrase mode as plainly as 'phrase' does. An unrecognised name is a
     * different matter and is refused.
     *
     * @param string $mode The requested mode.
     * @return string The lowercased mode.
     * @throws InvalidArgumentException If the mode is not supported.
     */
    protected function normaliseFulltextMode(string $mode): string
    {
        $normalised = strtolower(trim($mode));

        if (!in_array($normalised, self::FULLTEXT_MODES, true)) {
            throw new InvalidArgumentException(sprintf(
                "Unsupported full-text search mode '%s' for %s. Supported modes: %s."
                . " For boolean operators (+, -, \"), use 'websearch'.",
                $mode,
                $this->getDriverName(),
                implode(', ', self::FULLTEXT_MODES)
            ));
        }

        return $normalised;
    }
}
