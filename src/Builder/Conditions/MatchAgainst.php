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
     * Every character MySQL reads as a boolean operator.
     *
     * `@` is in the list because it opens the `@distance` operator, and `<`
     * because it is the "decrease relevance" counterpart of `>`. Both used to
     * be missing, and both reach the server as operators when a term such as
     * an email address contains one.
     *
     * @var array<int, string>
     */
    private const BOOLEAN_OPERATORS = ['+', '-', '>', '<', '(', ')', '~', '*', '"', '@'];

    /**
     * Strip every boolean operator out of a word.
     *
     * The characters are replaced with a space rather than removed, so
     * `a<b` becomes two words rather than the single word `ab` — the term the
     * caller passed contained two, and joining them would search for something
     * that was never in the text. Operators were previously trimmed only from
     * the ends, so `user@example.com` kept its `@` and the query it built was
     * rejected by the server outright.
     *
     * @param string $word The word to strip.
     * @return string The word with no operator characters left in it.
     */
    private static function stripOperators(string $word): string
    {
        $stripped = str_replace(self::BOOLEAN_OPERATORS, ' ', $word);

        return trim((string)preg_replace('/\s+/', ' ', $stripped));
    }

    /**
     * Set the search expression directly.
     *
     * Passes the string as-is without any operator prefixing.
     * Use this when you want full control over the boolean syntax.
     *
     * @param string $expression The raw search expression.
     * @return static
     */
    public function search(string $expression): static
    {
        $this->words = [$expression];
        return $this;
    }

    /**
     * Add optional words (stripped of operators).
     *
     * @param array<int, string> $words Words to add as optional.
     * @return static
     */
    public function optional(array $words = []): static
    {
        foreach ($words as $word) {
            $stripped = self::stripOperators($word);

            // A word that was nothing but operators leaves nothing behind, and
            // appending the empty string would put a stray space into the
            // expression rather than a term.
            if ($stripped !== '') {
                $this->words[] = $stripped;
            }
        }
        return $this;
    }

    /**
     * Add required words (prefixed with +).
     *
     * Every word gets the `+`. Words of three characters or fewer used to be
     * added bare, which does not make them "required" — it makes them
     * optional, and MySQL then returns rows that contain none of them.
     * `mustHave(['php', 'mysql'])` built `php +mysql` and matched a document
     * about java and mysql that had no php in it anywhere. The threshold
     * appears to have been MyISAM's `ft_min_word_len` of 4, but InnoDB is the
     * default engine and its `innodb_ft_min_token_size` is 3, so even on its
     * own terms it excluded a word length the index does hold.
     *
     * A word below the server's minimum token size still matches nothing —
     * with the `+` the whole expression returns no rows rather than quietly
     * dropping that word, which is the honest answer to "this word must
     * appear" for a word the index cannot answer for.
     *
     * @param array<int, string> $words Words that must appear.
     * @return static
     */
    public function mustHave(array $words = []): static
    {
        foreach ($words as $word) {
            $this->words[] = "+$word";
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
