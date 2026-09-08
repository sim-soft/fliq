<?php

namespace Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * The two LIKE families and the defaults that separate them.
 *
 * like()/orLike()/notLike()/orNotLike() are case-sensitive by default; the
 * whereLike() family that wraps them is case-insensitive by default. Called
 * with the same arguments they build different SQL, and on PostgreSQL they
 * match different rows. Three of the base docblocks stated the wrong default
 * and all four wrappers called themselves aliases, so nothing recorded the
 * distinction — and no test compared the families against each other.
 */
class LikeCaseSensitivityTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('like_pg', ['driver' => 'pgsql', 'database' => 'unused']);
        Connection::add('like_sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /**
     * A query against the default (MySQL) grammar.
     *
     * @return ActiveQuery The query.
     */
    private function query(): ActiveQuery
    {
        return new ActiveQuery()->from('user');
    }

    /**
     * The base family's case-sensitivity default, one method per case.
     *
     * @return array<string, array{string}> Method name by case name.
     */
    public static function baseFamily(): array
    {
        return [
            'like' => ['like'],
            'orLike' => ['orLike'],
            'notLike' => ['notLike'],
            'orNotLike' => ['orNotLike'],
        ];
    }

    /**
     * The wrapping family, paired with the base method it delegates to.
     *
     * @return array<string, array{string, string}> Wrapper and base method.
     */
    public static function familyPairs(): array
    {
        return [
            'whereLike / like' => ['whereLike', 'like'],
            'orWhereLike / orLike' => ['orWhereLike', 'orLike'],
            'whereNotLike / notLike' => ['whereNotLike', 'notLike'],
            'orWhereNotLike / orNotLike' => ['orWhereNotLike', 'orNotLike'],
        ];
    }

    #[Test]
    #[DataProvider('baseFamily')]
    public function theBaseFamilyIsCaseSensitiveByDefault(string $method): void
    {
        $sql = $this->query()->$method('username', 'Alice%')->getSQL();

        $this->assertStringNotContainsString('LOWER', $sql);
        $this->assertStringContainsString('`user`.`username`', $sql);
    }

    #[Test]
    #[DataProvider('familyPairs')]
    public function theWrappingFamilyIsCaseInsensitiveByDefault(string $wrapper, string $base): void
    {
        $sql = $this->query()->$wrapper('username', 'Alice%')->getSQL();

        $this->assertStringContainsString('LOWER(`user`.`username`)', $sql);
        $this->assertStringContainsString('LOWER(?)', $sql);
    }

    #[Test]
    #[DataProvider('familyPairs')]
    public function theTwoFamiliesDisagreeWhenCalledIdentically(string $wrapper, string $base): void
    {
        // The claim the "Alias for like()" docblocks made, tested directly.
        $viaWrapper = $this->query()->$wrapper('username', 'Alice%')->getSQL();
        $viaBase = $this->query()->$base('username', 'Alice%')->getSQL();

        $this->assertNotSame($viaBase, $viaWrapper);
    }

    #[Test]
    #[DataProvider('familyPairs')]
    public function thefamiliesAgreeOnceSensitivityIsStated(string $wrapper, string $base): void
    {
        // They share an implementation; only the default differs. Say which
        // you want and the difference disappears.
        $viaWrapper = $this->query()->$wrapper('username', 'Alice%', caseSensitive: true)->getSQL();
        $viaBase = $this->query()->$base('username', 'Alice%', caseSensitive: true)->getSQL();

        $this->assertSame($viaBase, $viaWrapper);
    }

    #[Test]
    #[DataProvider('baseFamily')]
    public function theDocumentedDefaultMatchesTheDeclaredOne(string $method): void
    {
        // Three of these docblocks said "Default: false" against a signature
        // reading `bool $caseSensitive = true`, which is the reverse of the
        // behaviour and the more dangerous way round to be wrong: a reader
        // trusting it expects a case-insensitive search and gets a strict one.
        $parameter = null;

        foreach (new ReflectionClass(ActiveQuery::class)->getMethod($method)->getParameters() as $candidate) {
            if ($candidate->getName() === 'caseSensitive') {
                $parameter = $candidate;
            }
        }

        $this->assertNotNull($parameter);
        $this->assertTrue($parameter->getDefaultValue());

        $doc = new ReflectionClass(ActiveQuery::class)->getMethod($method)->getDocComment();
        $this->assertIsString($doc);
        $this->assertMatchesRegularExpression('/caseSensitive[^\n]*Default: true/', $doc);
    }

    #[Test]
    #[DataProvider('familyPairs')]
    public function theWrappersDocumentedDefaultMatchesTheDeclaredOne(string $wrapper, string $base): void
    {
        $parameter = null;

        foreach (new ReflectionClass(ActiveQuery::class)->getMethod($wrapper)->getParameters() as $candidate) {
            if ($candidate->getName() === 'caseSensitive') {
                $parameter = $candidate;
            }
        }

        $this->assertNotNull($parameter);
        $this->assertFalse($parameter->getDefaultValue());

        $doc = new ReflectionClass(ActiveQuery::class)->getMethod($wrapper)->getDocComment();
        $this->assertIsString($doc);
        $this->assertMatchesRegularExpression('/caseSensitive[^\n]*Default: false/', $doc);
    }

    #[Test]
    public function noWrapperStillClaimsToBeAnAlias(): void
    {
        // "Alias for like()" was true of the implementation and false of the
        // behaviour, which is the kind of claim that stops a reader looking
        // any further.
        foreach (array_keys(self::familyPairs()) as $label) {
            [$wrapper] = self::familyPairs()[$label];
            $doc = new ReflectionClass(ActiveQuery::class)->getMethod($wrapper)->getDocComment();

            $this->assertIsString($doc);
            $this->assertStringNotContainsString('Alias for', $doc);
        }
    }

    #[Test]
    public function postgresUsesIlikeForCaseInsensitiveMatching(): void
    {
        // The engine-specific branch: PG has a real case-insensitive operator,
        // so lowering both sides would be both slower and unable to use an
        // ILIKE-aware index.
        $sql = $this->query()->withConnection('like_pg')
            ->like('username', 'Alice%', caseSensitive: false)->getSQL();

        $this->assertStringContainsString('ILIKE ?', $sql);
        $this->assertStringNotContainsString('LOWER', $sql);
    }

    #[Test]
    public function postgresUsesNotIlikeForNegatedCaseInsensitiveMatching(): void
    {
        $sql = $this->query()->withConnection('like_pg')
            ->notLike('username', 'Alice%', caseSensitive: false)->getSQL();

        $this->assertStringContainsString('NOT ILIKE ?', $sql);
        $this->assertStringNotContainsString('LOWER', $sql);
    }

    #[Test]
    public function postgresStillUsesPlainLikeWhenCaseSensitivityIsAsked(): void
    {
        $sql = $this->query()->withConnection('like_pg')
            ->like('username', 'Alice%', caseSensitive: true)->getSQL();

        $this->assertStringContainsString('LIKE ?', $sql);
        $this->assertStringNotContainsString('ILIKE', $sql);
    }

    #[Test]
    public function theWrapperFamilyReachesIlikeOnPostgres(): void
    {
        $sql = $this->query()->withConnection('like_pg')
            ->whereLike('username', 'Alice%')->getSQL();

        $this->assertStringContainsString('ILIKE ?', $sql);
    }

    #[Test]
    public function enginesWithoutIlikeLowerBothSides(): void
    {
        // Lowering only the column would leave a mixed-case pattern unable to
        // match anything at all.
        $sql = $this->query()->withConnection('like_sqlite')
            ->like('username', 'Alice%', caseSensitive: false)->getSQL();

        $this->assertStringContainsString('LOWER("user"."username")', $sql);
        $this->assertStringContainsString('LOWER(?)', $sql);
    }

    #[Test]
    public function postgresIlikeAppliesToEveryValueOfAnArray(): void
    {
        $sql = $this->query()->withConnection('like_pg')
            ->like('username', ['a%', 'b%'], caseSensitive: false)->getSQL();

        $this->assertSame(2, substr_count($sql, 'ILIKE ?'));
        $this->assertStringContainsString(' AND ', $sql);
    }

    #[Test]
    public function orNotLikeJoinsWithOrAndNegates(): void
    {
        // orNotLike() had no caller anywhere in the suite, so neither half of
        // what makes it distinct — the OR and the negation — was pinned.
        $sql = $this->query()->where('id', 1)->orNotLike('username', 'alice')->getSQL();

        $this->assertStringContainsString('OR `user`.`username` NOT LIKE ?', $sql);
    }

    #[Test]
    public function eachMethodContributesItsOwnLogicalOperator(): void
    {
        $expectations = [
            'like' => 'AND `user`.`username` LIKE ?',
            'orLike' => 'OR `user`.`username` LIKE ?',
            'notLike' => 'AND `user`.`username` NOT LIKE ?',
            'orNotLike' => 'OR `user`.`username` NOT LIKE ?',
        ];

        foreach ($expectations as $method => $expected) {
            $sql = $this->query()->where('id', 1)->$method('username', 'alice')->getSQL();
            $this->assertStringContainsString($expected, $sql, $method);
        }
    }

    #[Test]
    public function orNotLikeBindsEveryValueOfAnArray(): void
    {
        $query = $this->query()->orNotLike('username', ['a%', 'b%']);

        $this->assertSame(2, substr_count($query->getSQL(), 'NOT LIKE ?'));
        $this->assertSame(['a%', 'b%'], $query->getBinds());
    }

    #[Test]
    public function orNotLikeWithAnEmptyArrayAddsNothing(): void
    {
        // Consistent with the rest of the family: an empty pattern list is a
        // skipped condition, not an empty group the server would reject.
        $withCondition = $this->query()->where('id', 1)->orNotLike('username', [])->getSQL();
        $without = $this->query()->where('id', 1)->getSQL();

        $this->assertSame($without, $withCondition);
    }
}
