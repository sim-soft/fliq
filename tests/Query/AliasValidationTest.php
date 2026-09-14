<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * The alias, which reaches the statement without being bound.
 *
 * An alias names the table and prefixes every unqualified column, and neither
 * can be a parameter. Quoting alone does not make one safe: the grammars double
 * an embedded quote rather than reject it, so the surrounding punctuation
 * survives into the SQL. Every other identifier is validated; the aliases set
 * through from(['t' => ...]), withAlias() and alias() were not, because they
 * were assigned to the property directly.
 */
class AliasValidationTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli',
            'host' => '127.0.0.1',
            'database' => 'sample_db',
            'username' => 'root',
            'password' => '',
        ]);
        Connection::setDefault('mysql');
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    /** @return array<string, array{0: string}> */
    public static function hostileAliasProvider(): array
    {
        return [
            // Quoting doubled the backtick and left the rest intact, so this
            // arrived as the usable identifier `t``;--`.
            'a quote and a comment' => ['t`;--'],
            'a statement terminator' => ['t`; DROP TABLE x --'],
            'a double quote' => ['t"; DROP TABLE x --'],
            'a space' => ['my alias'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('hostileAliasProvider')]
    public function aliasRefusesAnIdentifierItCannotSafelyEmit(string $alias): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        (new ActiveQuery())->from('user')->alias($alias);
    }

    #[Test]
    #[DataProvider('hostileAliasProvider')]
    public function aSubQueryAliasIsValidatedLikeEveryOtherIdentifier(string $alias): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ActiveQuery())->from([$alias => 'SELECT 1 AS a']);
    }

    #[Test]
    public function withAliasValidatesTheAliasItScopesTheBlockTo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        (new ActiveQuery())
            ->from('user')
            ->withAlias('p`;--', fn(ActiveQuery $query) => $query->where('score', '>', 0));
    }

    #[Test]
    public function aRefusedAliasLeavesTheQueryAsItWas(): void
    {
        $query = (new ActiveQuery())->from('user u');

        try {
            $query->alias('bad alias');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Validation happens before the assignment, so a rejected alias
            // cannot leave the query qualifying columns against a name that
            // would never be emitted.
            $this->assertSame('u', $query->getAlias());
        }
    }

    #[Test]
    public function nullClearsTheAliasRatherThanBeingValidated(): void
    {
        // withAlias() restores whatever was there before, and a query built
        // without a FROM table has no alias to restore.
        $query = (new ActiveQuery())->from('user');
        $query->alias(null);

        $this->assertNull($query->getAlias());
    }

    #[Test]
    public function withAliasRestoresAnAbsentAliasWithoutTrippingValidation(): void
    {
        $query = (new ActiveQuery())->withConnection('mysql');
        $query->withAlias('u', fn(ActiveQuery $inner) => $inner);

        $this->assertNull($query->getAlias());
    }

    #[Test]
    public function aSubQueryKeyedByANumberIsRefusedRatherThanNamedByIt(): void
    {
        // An alias that comes from configuration or request data is a string as
        // far as the caller and the signature are concerned, but PHP coerces a
        // numeric one to an int the moment it is used as an array key. That is
        // why the check has to be made at runtime: the declared
        // array<string, ...> cannot be enforced for this value. Quoted, it named
        // the derived table `1` — something the caller never wrote and could not
        // reference. A plain list has the key 0 and ends the same way.
        $alias = $this->aliasFromConfiguration();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be given an alias as the array key');

        (new ActiveQuery())->from([$alias => 'SELECT 1 AS a']);
    }

    /**
     * An alias arriving as a string, as one read from outside the code would.
     *
     * @return string
     */
    private function aliasFromConfiguration(): string
    {
        return '1';
    }

    #[Test]
    public function anEmptyFromArrayIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be given an alias as the array key');

        (new ActiveQuery())->from([]);
    }

    #[Test]
    public function aSubQueryStrippedOfItsAliasIsRefusedRatherThanNamedNothing(): void
    {
        // alias() is public, so this can happen after from() accepted a good
        // one. Quoting the null gave ``, which MySQL accepts and PostgreSQL
        // rejects — the same builder producing SQL that parses on one engine
        // and not the other.
        $query = (new ActiveQuery())->from(['u' => 'SELECT 1 AS a'])->withConnection('mysql');
        $query->alias(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must keep an alias');

        $query->getSQL();
    }

    #[Test]
    public function aValidAliasNamesTheDerivedTableAndQualifiesItsColumns(): void
    {
        $query = (new ActiveQuery())->from(['u' => 'SELECT 1 AS a'])->withConnection('mysql');

        $this->assertSame('SELECT `u`.* FROM (SELECT 1 AS a) `u`', $query->getSQL());
    }

    #[Test]
    public function anUnderscoredOrNumberedAliasIsStillAccepted(): void
    {
        $query = (new ActiveQuery())->from(['t_1' => 'SELECT 1 AS a'])->withConnection('mysql');

        $this->assertSame('t_1', $query->getAlias());
    }
}
