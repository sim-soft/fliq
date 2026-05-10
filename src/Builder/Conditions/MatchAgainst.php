<?php

namespace Simsoft\DB\MySQL\Builder\Conditions;

use Simsoft\DB\MySQL\Builder\Clauses\Clause;

/**
 * MatchAgainst Clause.
 */
class MatchAgainst extends Clause
{
    protected array $words = [];

    /** @var string Default full-text search mode. */
    protected string $mode = 'IN BOOLEAN MODE';

    public function optional(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = trim(' ~*>()+-"');
        }
        return $this;
    }

    public function mustHave(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = strlen($word) > 3 ? "+$word" : $word;
        }
        return $this;
    }

    public function negation(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = "~$word";
        }
        return $this;
    }

    public function wildcard(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = "$word*";
        }
        return $this;
    }

    public function contains(array $phrases = []): static
    {
        foreach ($phrases as $phrase) {
            $this->words[] = '"' . $phrase . '"';
        }
        return $this;
    }

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
     * @return $this
     */
    public function naturalLanguageMode(): static
    {
        $this->mode = 'IN NATURAL LANGUAGE MODE';
        return $this;
    }

    /**
     * Enable boolean mode.
     *
     * @return $this
     */
    public function booleanMode(): static
    {
        $this->mode = 'IN BOOLEAN MODE';
        return $this;
    }

    /**
     * Enable query expansion mode.
     *
     * @return $this
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
