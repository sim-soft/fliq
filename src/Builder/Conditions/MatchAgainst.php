<?php

namespace Simsoft\DB\Builder\Conditions;

use Simsoft\DB\Builder\Clauses\Clause;

/**
 * MatchAgainst Clause.
 *
 * Builds MySQL MATCH...AGAINST full-text search expressions.
 */
class MatchAgainst extends Clause
{
    /** @var array<int, string> Full-text search words/phrases */
    protected array $words = [];

    /** @var string Default full-text search mode. */
    protected string $mode = 'IN BOOLEAN MODE';

    /**
     * Add optional words (stripped of operators).
     *
     * @param array<int, string> $words Words to add as optional.
     * @return static
     */
    public function optional(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = trim($word, ' ~*>()+-"');
        }
        return $this;
    }

    /**
     * Add required words (prefixed with +).
     *
     * @param array<int, string> $words Words that must appear.
     * @return static
     */
    public function mustHave(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = strlen($word) > 3 ? "+$word" : $word;
        }
        return $this;
    }

    /**
     * Add negated words (prefixed with ~).
     *
     * @param array<int, string> $words Words to negate.
     * @return static
     */
    public function negation(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = "~$word";
        }
        return $this;
    }

    /**
     * Add wildcard words (suffixed with *).
     *
     * @param array<int, string> $words Words to wildcard.
     * @return static
     */
    public function wildcard(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = "$word*";
        }
        return $this;
    }

    /**
     * Add exact phrase matches (wrapped in quotes).
     *
     * @param array<int, string> $phrases Phrases to match exactly.
     * @return static
     */
    public function contains(array $phrases = []): static
    {
        foreach ($phrases as $phrase) {
            $this->words[] = "\"$phrase\"";
        }
        return $this;
    }

    /**
     * Add excluded words (prefixed with -).
     *
     * @param array<int, string> $words Words that must not appear.
     * @return static
     */
    public function mustNot(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = "-$word";
        }
        return $this;
    }

    /**
     * Enable natural language mode.
     *
     * @return static
     */
    public function naturalLanguageMode(): static
    {
        $this->mode = 'IN NATURAL LANGUAGE MODE';
        return $this;
    }

    /**
     * Enable boolean mode.
     *
     * @return static
     */
    public function booleanMode(): static
    {
        $this->mode = 'IN BOOLEAN MODE';
        return $this;
    }

    /**
     * Enable query expansion mode.
     *
     * @return static
     */
    public function queryExpansion(): static
    {
        $this->mode = 'WITH QUERY EXPANSION';
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        if (is_string($this->attribute)) {
            $this->attribute = [$this->attribute];
        }

        $fullTextColumns = [];
        foreach ($this->attribute as $attribute) {
            $fullTextColumns[] = $this->getQualifiedAttribute($attribute);
        }

        $this->appendBinds(implode(' ', $this->words));
        return 'MATCH('
            . implode(', ', $fullTextColumns)
            . ") AGAINST ({$this->getPlaceHolder()} $this->mode)";
    }
}
