<?php

namespace Integration;

use Models\Setting;
use Models\User;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * Security tests — verifies SQL injection is prevented by parameter binding.
 */
class SecurityTest extends DatabaseTestCase
{
    #[Test]
    public function whereBindingPreventsInjection(): void
    {
        // Attempt SQL injection via where value
        $malicious = "' OR '1'='1";
        $users = User::find()->where('username', $malicious)->get()->all();

        // Should return zero results — injection is neutralized by binding
        $this->assertEmpty($users);
    }

    #[Test]
    public function likeBindingPreventsInjection(): void
    {
        $malicious = "%' OR '1'='1' --";
        $users = User::find()->like('username', $malicious)->get()->all();

        $this->assertEmpty($users);
    }

    #[Test]
    public function inBindingPreventsInjection(): void
    {
        $malicious = ["alice', 1) OR (1=1 --"];
        $users = User::find()->in('username', $malicious)->get()->all();

        $this->assertEmpty($users);
    }

    #[Test]
    public function betweenBindingPreventsInjection(): void
    {
        // Binding prevents SQL injection — the malicious string is treated as a literal value
        // MySQL may cast it to 0, but the query structure is never altered
        $malicious = "0 OR 1=1 --";
        $query = User::find()->between('score', $malicious, $malicious);
        $sql = $query->getSQL();

        // Verify the SQL uses ? placeholders, not the raw string
        $this->assertStringContainsString('BETWEEN ? AND ?', $sql);
        $this->assertStringNotContainsString('OR 1=1', $sql);
    }

    #[Test]
    public function rawQueryWithBindingIsSafe(): void
    {
        $malicious = "' OR '1'='1";
        $raw = new Raw('SELECT * FROM `user` WHERE `username` = ?', [$malicious]);
        $raw->withConnection('mysql');
        $results = $raw->fetchAll();

        $this->assertEmpty($results);
    }

    #[Test]
    public function jsonPathBindingPreventsInjection(): void
    {
        $malicious = "' OR '1'='1";
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJson('metadata->priority', '=', $malicious)
            ->withConnection('mysql');

        $results = $query->query($query);
        $this->assertEmpty($results);
    }

    #[Test]
    public function normalQueriesStillWorkAfterInjectionAttempts(): void
    {
        // Verify the DB is intact after injection attempts
        $count = User::find()->count();
        $this->assertEquals(10, $count);

        $alice = User::find()->where('username', 'alice')->first();
        $this->assertNotNull($alice);
        /** @var User $alice */
        $this->assertEquals('alice', $alice->username);
    }
}
