<?php

namespace Integration;

use InvalidArgumentException;
use Models\Post;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Collection;
use Simsoft\DB\DB;

/**
 * The full-text search mode, against a live MySQL server.
 *
 * An unrecognised mode used to fall through to the plain branch. This test
 * shows what that cost: the same term answered in the two modes returns
 * different rows, so a caller who asked for 'boolean' — MySQL's own name for
 * what this library calls 'websearch' — was given a different answer than the
 * one requested, with nothing to say so.
 *
 * Results are checked against the answer the server computes for the
 * equivalent MATCH...AGAINST written by hand, not against a copied row list.
 *
 * The `post` table carries FULLTEXT INDEX ft_title (title), so these run
 * against the shipped fixture. Every test is read-only.
 */
class FulltextModeTest extends DatabaseTestCase
{
    /**
     * Post ids the builder returned, sorted.
     *
     * @param Collection $rows The query result.
     * @return array<int, int>
     */
    private function ids(Collection $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $this->assertInstanceOf(Post::class, $row);
            $ids[] = $row->id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * Post ids matching a MATCH...AGAINST expression written by hand.
     *
     * @param string $against The AGAINST expression, without the keyword.
     * @param array<int, string> $binds Values for the expression's placeholders.
     * @return array<int, int>
     */
    private function expected(string $against, array $binds = []): array
    {
        $ids = [];

        foreach (DB::query("SELECT id FROM post WHERE MATCH(title) AGAINST($against)", $binds) as $row) {
            $ids[] = (int)$row['id'];
        }

        sort($ids);

        return $ids;
    }

    #[Test]
    public function websearchReadsTheBooleanOperators(): void
    {
        // 'Docker for PHP Developers' is post 2. Excluding Docker must drop it.
        $expected = $this->expected('? IN BOOLEAN MODE', ['+PHP -Docker']);

        $this->assertNotContains(2, $expected, 'The exclusion must remove post 2.');

        $this->assertSame(
            $expected,
            $this->ids(Post::find()->whereFulltext(['title'], '+PHP -Docker', 'websearch')->get())
        );
    }

    #[Test]
    public function plainDoesNotReadTheBooleanOperators(): void
    {
        // In natural language mode '+' and '-' are punctuation, so the row the
        // caller asked to exclude comes back. This is correct for 'plain' — an
        // ordinary term must not have its punctuation read as syntax — and is
        // exactly why the mode cannot be guessed.
        $expected = $this->expected('? IN NATURAL LANGUAGE MODE', ['+PHP -Docker']);

        $this->assertContains(2, $expected);

        $this->assertSame(
            $expected,
            $this->ids(Post::find()->whereFulltext(['title'], '+PHP -Docker', 'plain')->get())
        );
    }

    #[Test]
    public function theTwoModesDisagreeOnTheSameTerm(): void
    {
        // The premise of the whole fix. If these agreed, falling through to
        // plain would have been a wording difference and nothing more.
        $websearch = $this->ids(
            Post::find()->whereFulltext(['title'], '+PHP -Docker', 'websearch')->get()
        );
        $plain = $this->ids(
            Post::find()->whereFulltext(['title'], '+PHP -Docker', 'plain')->get()
        );

        $this->assertNotSame($plain, $websearch);
    }

    #[Test]
    public function phraseMatchesTheWordsInOrder(): void
    {
        // 'PHP Design Patterns' is post 4. As a phrase, 'PHP Design' matches it
        // alone; in plain mode the two words match every PHP post.
        $expected = $this->expected("CONCAT('\"', ?, '\"') IN BOOLEAN MODE", ['PHP Design']);

        $this->assertSame([4], $expected);

        $this->assertSame(
            $expected,
            $this->ids(Post::find()->whereFulltext(['title'], 'PHP Design', 'phrase')->get())
        );
    }

    #[Test]
    public function phraseRespectsWordOrder(): void
    {
        // Reversed, the phrase is in no title, while plain mode still matches
        // every PHP post.
        $this->assertSame(
            [],
            $this->ids(Post::find()->whereFulltext(['title'], 'Design PHP', 'phrase')->get())
        );

        $this->assertNotSame(
            [],
            $this->ids(Post::find()->whereFulltext(['title'], 'Design PHP', 'plain')->get())
        );
    }

    #[Test]
    public function anUnsupportedModeIsRefusedBeforeTheQueryRuns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported full-text search mode 'boolean' for mysql");

        Post::find()->whereFulltext(['title'], '+PHP -Docker', 'boolean')->get();
    }

    #[Test]
    public function theRefusedModeIsTheOneThatUsedToReturnTheWrongRows(): void
    {
        // Before the fix, mode='boolean' built NATURAL LANGUAGE MODE and
        // returned the plain result — the rows the caller had asked to exclude.
        // Now it raises, and the caller is told which mode to use instead.
        try {
            Post::find()->whereFulltext(['title'], '+PHP -Docker', 'boolean')->get();
            $this->fail('An unsupported mode should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("use 'websearch'", $e->getMessage());
        }

        $this->assertSame(
            $this->expected('? IN BOOLEAN MODE', ['+PHP -Docker']),
            $this->ids(Post::find()->whereFulltext(['title'], '+PHP -Docker', 'websearch')->get())
        );
    }

    #[Test]
    public function caseIsNormalisedAndStillQueries(): void
    {
        $this->assertSame(
            $this->ids(Post::find()->whereFulltext(['title'], '+PHP -Docker', 'websearch')->get()),
            $this->ids(Post::find()->whereFulltext(['title'], '+PHP -Docker', 'WebSearch')->get())
        );
    }

    #[Test]
    public function orWhereFulltextRefusesTheSameModes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Post::find()->where('id', '=', 1)->orWhereFulltext(['title'], 'PHP', 'boolean')->get();
    }
}
