<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Conditions\MatchAgainst;
use Simsoft\DB\Connection;

/**
 * Unit tests for MatchAgainst full-text search condition.
 */
class MatchAgainstTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => 'localhost',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function searchPassesExpressionAsIs(): void
    {
        $match = (new MatchAgainst(['title', 'body']))
            ->search('+laravel -wordpress "service container" php*')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        $this->assertStringContainsString('MATCH(', $sql);
        $this->assertStringContainsString('AGAINST (? IN BOOLEAN MODE)', $sql);
        $this->assertNotNull($binds);
        $this->assertSame('+laravel -wordpress "service container" php*', $binds[0]);
    }

    #[Test]
    public function searchOverridesPreviousWords(): void
    {
        $match = (new MatchAgainst(['title']))
            ->mustHave(['php'])
            ->search('custom expression')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $binds = $query->getBinds();

        // search() replaces all previous words
        $this->assertNotNull($binds);
        $this->assertSame('custom expression', $binds[0]);
    }

    #[Test]
    public function searchWithNaturalLanguageMode(): void
    {
        $match = (new MatchAgainst(['content']))
            ->search('database optimization techniques')
            ->naturalLanguageMode();

        $query = (new ActiveQuery())
            ->from('articles')
            ->where($match);

        $sql = $query->getSQL();
        $binds = $query->getBinds();

        $this->assertStringContainsString('AGAINST (? IN NATURAL LANGUAGE MODE)', $sql);
        $this->assertNotNull($binds);
        $this->assertSame('database optimization techniques', $binds[0]);
    }

    #[Test]
    public function mustHaveBuildsCorrectExpression(): void
    {
        $match = (new MatchAgainst(['title', 'body']))
            ->mustHave(['php', 'mysql'])
            ->mustNot(['java'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $binds = $query->getBinds();

        // Every required word gets its +, and this is the expression the
        // documentation has always shown for this call.
        $this->assertNotNull($binds);
        $this->assertSame('+php +mysql -java', $binds[0]);
    }

    // ------------------------------------------------------------------
    // mustHave() means the word must appear
    // ------------------------------------------------------------------

    /**
     * Words of three characters or fewer used to be added without their +,
     * which does not make them required — it makes them optional, and MySQL
     * answers with rows containing none of them. Measured against a real
     * FULLTEXT index: mustHave(['php', 'mysql']) built `php +mysql` and
     * returned a document about java and mysql with no php in it anywhere.
     *
     * @return void
     */
    #[Test]
    public function everyRequiredWordCarriesItsOperator(): void
    {
        $match = (new MatchAgainst(['title', 'body']))->mustHave(['php', 'mysql']);

        $binds = (new ActiveQuery())->from('posts')->where($match)->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('+php +mysql', $binds[0]);
    }

    /**
     * The old rule was MyISAM's ft_min_word_len of 4, but InnoDB is the default
     * engine and indexes from 3. Either way the length of a word is not what
     * decides whether the caller meant it, so no length is exempt.
     *
     * @param string $word The required word.
     * @return void
     */
    #[Test]
    #[DataProvider('wordsOfEveryLength')]
    public function noWordLengthIsExemptFromTheRequiredOperator(string $word): void
    {
        $match = (new MatchAgainst(['title']))->mustHave([$word]);

        $binds = (new ActiveQuery())->from('posts')->where($match)->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame("+$word", $binds[0]);
    }

    /**
     * Words either side of the old four-character threshold.
     *
     * @return array<string, array{string}>
     */
    public static function wordsOfEveryLength(): array
    {
        return [
            'one character' => ['a'],
            'two characters' => ['at'],
            'three characters, the old cut-off' => ['php'],
            'four characters' => ['java'],
            'five characters' => ['mysql'],
        ];
    }

    /**
     * mustNot() never had the length rule, so `-cat` and `cat` could be
     * produced for the same word depending on which method took it. The two are
     * now symmetric.
     *
     * @return void
     */
    #[Test]
    public function requiredAndExcludedTreatTheSameWordTheSameWay(): void
    {
        $required = (new MatchAgainst(['title']))->mustHave(['cat']);
        $excluded = (new MatchAgainst(['title']))->mustNot(['cat']);

        $requiredBinds = (new ActiveQuery())->from('posts')->where($required)->getBinds();
        $excludedBinds = (new ActiveQuery())->from('posts')->where($excluded)->getBinds();

        $this->assertNotNull($requiredBinds);
        $this->assertNotNull($excludedBinds);
        $this->assertSame('+cat', $requiredBinds[0]);
        $this->assertSame('-cat', $excludedBinds[0]);
    }

    // ------------------------------------------------------------------
    // optional() strips every operator, not just the ones at the ends
    // ------------------------------------------------------------------

    /**
     * The operators were trimmed from the ends of the word, and `<` and `@`
     * were not in the trim list at all. A term holding one in the middle kept
     * it, and the server rejected the whole expression — `user@example.com`
     * raised "syntax error, unexpected '@'". optional() is the method for words
     * that came from a user, so this needs nothing unusual of them.
     *
     * @param string $word The word as given.
     * @param string $expected The expression it should contribute.
     * @return void
     */
    #[Test]
    #[DataProvider('wordsCarryingOperators')]
    public function operatorsAreStrippedFromAnywhereInTheWord(string $word, string $expected): void
    {
        $match = (new MatchAgainst(['title', 'body']))->optional([$word]);

        $binds = (new ActiveQuery())->from('posts')->where($match)->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame($expected, $binds[0]);
    }

    /**
     * Every character MySQL reads as a boolean operator, in each position.
     *
     * The replacement is a space rather than nothing, so `a<b` becomes two
     * words: the term held two, and joining them would search for something
     * that was never written.
     *
     * @return array<string, array{string, string}>
     */
    public static function wordsCarryingOperators(): array
    {
        return [
            'at sign in the middle' => ['user@example.com', 'user example.com'],
            'plus at the end' => ['C++', 'C'],
            'less than in the middle' => ['a<b', 'a b'],
            'angle brackets around' => ['<tag>', 'tag'],
            'hyphen in the middle' => ['e-mail', 'e mail'],
            'star in the middle' => ['rock*roll', 'rock roll'],
            'quotes around a word' => ['say "hi"', 'say hi'],
            'leading plus' => ['+php', 'php'],
            'leading tilde' => ['~php', 'php'],
            'trailing paren' => ['php)', 'php'],
            'surrounding spaces' => ['  php  ', 'php'],
            'nothing to strip' => ['php', 'php'],
        ];
    }

    /**
     * A word made only of operators leaves nothing behind. Appending the empty
     * string would put a stray space in the expression where a term should be.
     *
     * @return void
     */
    #[Test]
    public function aWordThatIsNothingButOperatorsIsDropped(): void
    {
        $match = (new MatchAgainst(['title']))->optional(['+++', 'mysql']);

        $binds = (new ActiveQuery())->from('posts')->where($match)->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('mysql', $binds[0]);
    }

    /**
     * One unusable term used to take the whole query with it, since the words
     * are joined into a single expression and the server parses it as a whole.
     *
     * @return void
     */
    #[Test]
    public function oneAwkwardTermDoesNotSpoilTheRest(): void
    {
        $match = (new MatchAgainst(['title', 'body']))->optional(['mysql', 'user@example.com']);

        $binds = (new ActiveQuery())->from('posts')->where($match)->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('mysql user example.com', $binds[0]);
    }

    /**
     * search() is documented to pass the expression as-is, so it is the way to
     * write boolean syntax by hand. Stripping there would remove the only
     * escape hatch the class has.
     *
     * @return void
     */
    #[Test]
    public function searchStillPassesOperatorsThrough(): void
    {
        $match = (new MatchAgainst(['title']))->search('+php -java "exact phrase"');

        $binds = (new ActiveQuery())->from('posts')->where($match)->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('+php -java "exact phrase"', $binds[0]);
    }

    // ------------------------------------------------------------------
    // Modes
    // ------------------------------------------------------------------

    #[Test]
    public function queryExpansionSetsItsMode(): void
    {
        $match = (new MatchAgainst(['title', 'body']))
            ->optional(['mysql'])
            ->queryExpansion();

        $query = (new ActiveQuery())->from('posts')->where($match);
        $binds = $query->getBinds();

        $this->assertStringContainsString('AGAINST (? WITH QUERY EXPANSION)', $query->getSQL());
        $this->assertNotNull($binds);
        $this->assertSame('mysql', $binds[0]);
    }

    /**
     * The mode is one value, so the last call decides it. Worth pinning: the
     * three setters are chainable and read as though they accumulate.
     *
     * @return void
     */
    #[Test]
    public function theLastModeSetWins(): void
    {
        $match = (new MatchAgainst(['title']))
            ->search('php')
            ->queryExpansion()
            ->naturalLanguageMode()
            ->booleanMode();

        $this->assertStringContainsString(
            'AGAINST (? IN BOOLEAN MODE)',
            (new ActiveQuery())->from('posts')->where($match)->getSQL()
        );
    }

    #[Test]
    public function wildcardBuildsSuffixedWords(): void
    {
        $match = (new MatchAgainst(['name']))
            ->wildcard(['micro', 'soft'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('products')
            ->where($match);

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('micro* soft*', $binds[0]);
    }

    #[Test]
    public function containsWrapsInQuotes(): void
    {
        $match = (new MatchAgainst(['body']))
            ->contains(['dependency injection', 'service container'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('articles')
            ->where($match);

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('"dependency injection" "service container"', $binds[0]);
    }

    #[Test]
    public function negationPrefixesTilde(): void
    {
        $match = (new MatchAgainst(['title']))
            ->negation(['wordpress'])
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $binds = $query->getBinds();

        $this->assertNotNull($binds);
        $this->assertSame('~wordpress', $binds[0]);
    }

    #[Test]
    public function singleColumnMatch(): void
    {
        $match = (new MatchAgainst('title'))
            ->search('php')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $sql = $query->getSQL();

        $this->assertStringContainsString('MATCH(`posts`.`title`)', $sql);
    }

    #[Test]
    public function multipleColumnsMatch(): void
    {
        $match = (new MatchAgainst(['title', 'body', 'tags']))
            ->search('php')
            ->booleanMode();

        $query = (new ActiveQuery())
            ->from('posts')
            ->where($match);

        $sql = $query->getSQL();

        $this->assertStringContainsString('MATCH(`posts`.`title`, `posts`.`body`, `posts`.`tags`)', $sql);
    }
}
