<?php

namespace Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Traits\Qualifier;

/**
 * A bare user of the trait, with no builder around it.
 *
 * Qualifier is used by every builder, but what it does with a table name and an
 * alias does not depend on any of them, so it is exercised on its own here.
 */
class BareQualifier
{
    use Qualifier;
}

/**
 * Naming a sub-query used as a table.
 */
class QualifiedSubQueryTest extends TestCase
{
    private function qualifier(): BareQualifier
    {
        return new BareQualifier();
    }

    #[Test]
    public function anAliasNamesTheDerivedTableAndBecomesTheColumnQualifier(): void
    {
        $qualifier = $this->qualifier();

        $this->assertSame('(SELECT 1) `t`', $qualifier->getQualifiedSubQuery('SELECT 1', 't'));
        $this->assertSame('t', $qualifier->getAlias());
    }

    #[Test]
    public function aSubQueryWithNoAliasIsRefusedRatherThanNamedNothing(): void
    {
        // A null alias was quoted anyway, giving `` — an empty identifier that
        // MySQL and SQLite happen to accept and PostgreSQL rejects outright, so
        // the same call built a statement that ran on two engines and would not
        // parse on the third. Neither is what the caller wanted: MySQL and
        // PostgreSQL both require a derived table to be named.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be given an alias');

        $this->qualifier()->getQualifiedSubQuery('SELECT 1');
    }

    #[Test]
    public function aHostileAliasIsRefused(): void
    {
        // Quoting alone left the injected backtick doubled but intact, so the
        // alias arrived as the identifier "t`;--" rather than being rejected.
        // Every other identifier on this path is validated; this one was not.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        $this->qualifier()->getQualifiedSubQuery('SELECT 1', 't`;--');
    }

    #[Test]
    public function aRefusedAliasDoesNotBecomeTheQualifier(): void
    {
        $qualifier = $this->qualifier();
        $qualifier->alias('keep');

        try {
            $qualifier->getQualifiedSubQuery('SELECT 1', 'bad alias');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // The alias is only adopted once it is known to be usable, so a
            // rejected one cannot leave the query qualifying its columns
            // against a name that was never emitted.
            $this->assertSame('keep', $qualifier->getAlias());
        }
    }

    #[Test]
    public function aQualifiedTableKeepsOnlyItsLastPartAsTheColumnQualifier(): void
    {
        // schema.table.column is not a valid reference, so columns qualify
        // against the table name alone.
        $qualifier = $this->qualifier();

        $this->assertSame('`public`.`user`', $qualifier->getQualifiedTable('public.user'));
        $this->assertSame('user', $qualifier->getAlias());
        $this->assertSame('`user`.`score`', $qualifier->getQualifiedAttribute('score'));
    }

    #[Test]
    public function anAliasedTableQualifiesColumnsAgainstTheAlias(): void
    {
        $qualifier = $this->qualifier();

        $this->assertSame('`user` `u`', $qualifier->getQualifiedTable('user', 'u'));
        $this->assertSame('`u`.`score`', $qualifier->getQualifiedAttribute('score'));
    }

    #[Test]
    public function anUnaliasedTableIsItsOwnQualifier(): void
    {
        $qualifier = $this->qualifier();

        $this->assertSame('`user`', $qualifier->getQualifiedTable('user'));
        $this->assertSame('user', $qualifier->getAlias());
    }

    #[Test]
    public function aHostileTableNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        $this->qualifier()->getQualifiedTable('user`; DROP TABLE x --');
    }

    #[Test]
    public function aHostileTableAliasIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        $this->qualifier()->getQualifiedTable('user', 'u`;--');
    }

    #[Test]
    public function anEmptyTableNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->qualifier()->getQualifiedTable('');
    }

    #[Test]
    public function starIsNotQuotedAsThoughItWereAColumn(): void
    {
        $qualifier = $this->qualifier();
        $this->assertSame('*', $qualifier->getQualifiedAttribute('*'));

        $qualifier->alias('u');
        $this->assertSame('`u`.*', $qualifier->getQualifiedAttribute('*'));
    }
}
