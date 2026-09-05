<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;

/**
 * Unit tests for joins that carry no ON clause.
 *
 * crossJoin() had no tests. Its $on parameter defaults to an empty array —
 * which is the normal way to write a CROSS JOIN, since it pairs every row and
 * has nothing to match on — but the builder took the first key and value of
 * that empty array anyway and assembled an ON clause out of empty strings:
 * ``ON `post`.`` = {}``. The server rejects that outright, so the documented
 * call could not run at all.
 */
class JoinWithoutOnTest extends TestCase
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

    /**
     * A query on `user` with the given join applied.
     *
     * @return ActiveQuery
     */
    private function query(): ActiveQuery
    {
        return (new ActiveQuery())->from('user')->on('mysql');
    }

    #[Test]
    public function crossJoinWithoutKeysHasNoOnClause(): void
    {
        $sql = $this->query()->crossJoin('post')->getSQL();

        $this->assertSame('SELECT `user`.* FROM `user` CROSS JOIN `post`', $sql);
        $this->assertStringNotContainsString(' ON ', $sql);
    }

    #[Test]
    public function crossJoinWithoutKeysEmitsNoEmptyIdentifier(): void
    {
        // The old output contained an empty backtick pair and an unresolved
        // deferred placeholder, both of which are syntax errors.
        $sql = $this->query()->crossJoin('post')->getSQL();

        $this->assertStringNotContainsString('``', $sql);
        $this->assertStringNotContainsString('{}', $sql);
    }

    #[Test]
    public function crossJoinKeepsAnAlias(): void
    {
        $this->assertSame(
            'SELECT `user`.* FROM `user` CROSS JOIN `post` AS `p`',
            $this->query()->crossJoin('post p')->getSQL()
        );
    }

    #[Test]
    public function crossJoinStillAcceptsKeysWhenGiven(): void
    {
        // Passing keys is unusual for a CROSS JOIN but was previously the only
        // form that worked, so it keeps building an ON clause.
        $this->assertSame(
            'SELECT `user`.* FROM `user` CROSS JOIN `post` ON `post`.`user_id` = `user`.`id`',
            $this->query()->crossJoin('post', ['post.user_id' => 'user.id'])->getSQL()
        );
    }

    #[Test]
    public function anyJoinTypeWithoutKeysOmitsTheOnClause(): void
    {
        // The empty-array case is handled in join(), so every join type that
        // reaches it behaves the same way rather than only crossJoin().
        $this->assertSame(
            'SELECT `user`.* FROM `user` INNER JOIN `post`',
            $this->query()->join('post')->getSQL()
        );
    }

    #[Test]
    public function joinsWithKeysAreUnaffected(): void
    {
        $this->assertSame(
            'SELECT `user`.* FROM `user` LEFT JOIN `post` AS `p` ON `p`.`user_id` = `user`.`id`',
            $this->query()->leftJoin('post p', ['p.user_id' => 'user.id'])->getSQL()
        );
    }

    #[Test]
    public function outerJoinVariantsBuildTheirKeyword(): void
    {
        $this->assertStringContainsString(
            'LEFT OUTER JOIN `post` AS `p` ON `p`.`user_id` = `user`.`id`',
            $this->query()->leftOuterJoin('post p', ['p.user_id' => 'user.id'])->getSQL()
        );
        $this->assertStringContainsString(
            'RIGHT OUTER JOIN `post` AS `p` ON `p`.`user_id` = `user`.`id`',
            $this->query()->rightOuterJoin('post p', ['p.user_id' => 'user.id'])->getSQL()
        );
    }

    #[Test]
    public function twoCrossJoinsBothSurvive(): void
    {
        // Joins are keyed by alias internally, so two of them must not collide.
        $sql = $this->query()->crossJoin('post')->crossJoin('comment')->getSQL();

        $this->assertStringContainsString('CROSS JOIN `post`', $sql);
        $this->assertStringContainsString('CROSS JOIN `comment`', $sql);
    }
}
