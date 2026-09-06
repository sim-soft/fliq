<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;
use Simsoft\DB\DB;
use Simsoft\DB\Exceptions\QueryException;

/**
 * The FROM and JOIN sources are quoted when the SQL is built, not when they are
 * named.
 *
 * They used to be rendered eagerly, which froze whichever grammar was current
 * into the stored string. A connection chosen afterwards — which the fluent
 * order invites, and which DB::table() does internally — produced a statement
 * quoted for one engine and run against another.
 */
class DeferredQuotingTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::reset();
        Connection::add('mysql', [
            'driver' => 'mysqli', 'host' => 'localhost',
            'database' => 'test', 'username' => 'root', 'password' => '',
        ]);
        Connection::add('pg', [
            'driver' => 'pgsql', 'host' => 'localhost',
            'database' => 'test', 'username' => 'postgres', 'password' => '',
        ]);
        Connection::setDefault('mysql');
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    #[Test]
    public function fromIsQuotedForTheConnectionNamedAfterIt(): void
    {
        $sql = (new ActiveQuery())->from('user')->withConnection('pg')->getSQL();

        $this->assertStringContainsString('FROM "user"', $sql);
        $this->assertStringNotContainsString('`', $sql);
    }

    #[Test]
    public function dbTableQuotesForItsOwnConnection(): void
    {
        // DB::table() calls from() before withConnection(), so this shape broke
        // for every connection that was not the default.
        $sql = DB::table('user', 'pg')->getSQL();

        $this->assertStringContainsString('FROM "user"', $sql);
        $this->assertStringNotContainsString('`', $sql);
    }

    #[Test]
    public function aliasedFromIsQuotedForTheLaterConnection(): void
    {
        $sql = (new ActiveQuery())->from('user u')->withConnection('pg')->getSQL();

        $this->assertStringContainsString('FROM "user" "u"', $sql);
        $this->assertStringNotContainsString('`', $sql);
    }

    #[Test]
    public function joinIsQuotedForTheConnectionNamedAfterIt(): void
    {
        $sql = (new ActiveQuery())
            ->from('user u')
            ->join('post p', ['user_id' => '!u.id'])
            ->withConnection('pg')
            ->getSQL();

        $this->assertSame(
            'SELECT "u".* FROM "user" "u" INNER JOIN "post" AS "p" ON "p"."user_id" = "u"."id"',
            $sql
        );
    }

    #[Test]
    public function joinSubQueryWrapperIsQuotedForTheLaterConnection(): void
    {
        // The alias and ON clause are the parts this query owns, and they
        // follow its connection. The sub-query is a builder in its own right
        // and renders with whichever connection it carries — it is given one
        // here for that reason.
        $sub = (new ActiveQuery())->from('post')->where('view_count', '>', 10)->withConnection('pg');

        $sql = (new ActiveQuery())
            ->from('user u')
            ->join(['p' => $sub], ['user_id' => '!u.id'])
            ->withConnection('pg')
            ->getSQL();

        $this->assertStringContainsString('AS "p" ON "p"."user_id" = "u"."id"', $sql);
        $this->assertStringNotContainsString('`', $sql);
    }

    #[Test]
    public function getTableReturnsTheRawNameWithoutItsAlias(): void
    {
        // It used to return the rendered "`user` `u`", which callers tried to
        // unpick with trim($t, '`"') — that cannot remove the interior
        // backticks, so they asked the server for a table named "user` `u".
        $this->assertSame('user', (new ActiveQuery())->from('user u')->getTable());
        $this->assertSame('user', (new ActiveQuery())->from('user')->getTable());
        $this->assertSame('user', (new ActiveQuery())->from('user AS u')->getTable());
    }

    #[Test]
    public function getTableIsNullForASubQuerySource(): void
    {
        $sub = (new ActiveQuery())->from('user')->where('role', 'admin');
        $query = (new ActiveQuery())->from(['t' => $sub]);

        $this->assertNull($query->getTable());
        $this->assertNotNull($query->getFromSubQuery());
    }

    #[Test]
    public function updateAllOnASubQuerySourceIsRefused(): void
    {
        // The sub-query SQL used to be passed to Update as though it were a
        // table name, producing an UPDATE against a table named after a whole
        // SELECT.
        $sub = (new ActiveQuery())->from('user')->where('role', 'admin');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot update a query that selects from a sub-query.');

        (new ActiveQuery())->from(['t' => $sub])->updateAll(['score' => 1]);
    }

    #[Test]
    public function anAliasEqualToTheTableNameIsNotRepeated(): void
    {
        // alias() overriding back to the table's own name emitted
        // "FROM `user` `user`", which is legal but pointless; the check used to
        // compare against the rendered string and so never matched.
        $sql = (new ActiveQuery())->from('user u')->alias('user')->getSQL();

        $this->assertSame('SELECT `user`.* FROM `user`', $sql);
    }

    #[Test]
    public function aSchemaQualifiedTableQualifiesColumnsByItsLastPart(): void
    {
        $sql = (new ActiveQuery())->from('public.user')->where('id', 1)->getSQL();

        $this->assertStringContainsString('FROM `public`.`user`', $sql);
        $this->assertStringContainsString('`user`.`id`', $sql);
        $this->assertStringNotContainsString('`public`.`user`.`id`', $sql);
    }
}
