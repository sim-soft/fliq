<?php

namespace Integration;

use Models\Setting;
use PHPUnit\Framework\Attributes\Test;
use Simsoft\DB\Builder\ActiveQuery;

/**
 * Integration tests for JSON column queries against a real database.
 *

 */
class JsonQueryTest extends DatabaseTestCase
{
    #[Test]
    public function whereJsonSimplePath(): void
    {
        // Find settings where metadata->priority = 1
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJson('metadata->priority', '=', 1)
            ->withConnection('mysql');

        $results = $query->query($query);

        // 3 settings have priority 1: general/site_name, mail/driver, cache/driver
        $this->assertCount(3, $results);
    }

    #[Test]
    public function whereJsonBooleanValue(): void
    {
        // Find settings where metadata->editable = false
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJson('metadata->editable', '=', 'false')
            ->withConnection('mysql');

        $results = $query->query($query);

        // mail/driver and cache/driver are not editable
        $this->assertCount(2, $results);
    }

    #[Test]
    public function whereJsonGreaterThan(): void
    {
        // Find settings where metadata->priority > 3
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJson('metadata->priority', '>', 3)
            ->withConnection('mysql');

        $results = $query->query($query);

        // mail/from_name (4), mail/from_address (5)
        $this->assertCount(2, $results);
    }

    #[Test]
    public function whereJsonContainsValue(): void
    {
        // Find settings where metadata->tags contains "core"
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonContains('metadata->tags', 'core')
            ->withConnection('mysql');

        $results = $query->query($query);

        // site_name, mail/driver, cache/driver have "core" tag
        $this->assertCount(3, $results);
    }

    #[Test]
    public function whereJsonContainsBranding(): void
    {
        // Find settings where metadata->tags contains "branding"
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonContains('metadata->tags', 'branding')
            ->withConnection('mysql');

        $results = $query->query($query);

        // site_name and mail/from_name have "branding" tag
        $this->assertCount(2, $results);
    }

    #[Test]
    public function whereJsonLength(): void
    {
        // Find settings where metadata->tags has exactly 2 items
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonLength('metadata->tags', '=', 2)
            ->withConnection('mysql');

        $results = $query->query($query);

        // site_name ["core","branding"], locale ["locale","i18n"],
        // mail/driver ["mail","core"], mail/from_name ["mail","branding"]
        $this->assertGreaterThanOrEqual(4, count($results));
    }

    #[Test]
    public function whereJsonWithModelFind(): void
    {
        // Use Model::find() with JSON condition
        $query = Setting::find()
            ->whereJson('metadata->editable', '=', 'true')
            ->where('group', 'mail');

        $results = $query->query($query);

        // mail group editable: host, port, from_name, from_address (4 items)
        $this->assertCount(4, $results);

        foreach ($results as $row) {
            $this->assertEquals('mail', $row['group']);
        }
    }

    #[Test]
    public function whereJsonValueShorthand(): void
    {
        // whereJsonValue is shorthand for whereJson with '='
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonValue('metadata->priority', 2)
            ->withConnection('mysql');

        $results = $query->query($query);

        // general/timezone, mail/host, cache/ttl have priority 2
        $this->assertCount(3, $results);
    }

    #[Test]
    public function jsonCastOnModel(): void
    {
        // Verify the metadata json cast works on the model
        $setting = Setting::findByPk(1);

        $this->assertNotNull($setting);
        /** @var array<string, mixed> $metadata */
        $metadata = $setting->metadata;
        $this->assertNotEmpty($metadata);
        $this->assertEquals(1, $metadata['priority']);
        $this->assertTrue($metadata['editable']);
        $this->assertContains('core', $metadata['tags']);
    }

    // ------------------------------------------------------------------
    // whereJsonDoesntContain
    // ------------------------------------------------------------------

    #[Test]
    public function whereJsonDoesntContain(): void
    {
        // Find settings where metadata->tags does NOT contain "core"
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonDoesntContain('metadata->tags', 'core')
            ->withConnection('mysql');

        $results = $query->query($query);

        // 7 settings don't have "core" tag (10 total - 3 with core)
        $this->assertCount(7, $results);
    }

    // ------------------------------------------------------------------
    // whereJsonContainsKey / whereJsonDoesntContainKey
    // ------------------------------------------------------------------

    #[Test]
    public function whereJsonContainsKey(): void
    {
        // All settings have metadata->priority key
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonContainsKey('metadata->priority')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertCount(10, $results);
    }

    #[Test]
    public function whereJsonContainsKeyNested(): void
    {
        // No settings have metadata->nonexistent key
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonContainsKey('metadata->nonexistent')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertEmpty($results);
    }

    #[Test]
    public function whereJsonDoesntContainKey(): void
    {
        // No settings lack metadata->editable key (all have it)
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonDoesntContainKey('metadata->editable')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertEmpty($results);
    }

    #[Test]
    public function whereJsonDoesntContainKeyMissing(): void
    {
        // All settings lack metadata->nonexistent key
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonDoesntContainKey('metadata->nonexistent')
            ->withConnection('mysql');

        $results = $query->query($query);

        $this->assertCount(10, $results);
    }

    // ------------------------------------------------------------------
    // Or variants
    // ------------------------------------------------------------------

    #[Test]
    public function orWhereJsonContains(): void
    {
        // Settings with "core" tag OR "locale" tag
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJsonContains('metadata->tags', 'core')
            ->orWhereJsonContains('metadata->tags', 'locale')
            ->withConnection('mysql');

        $results = $query->query($query);

        // core: site_name, mail/driver, cache/driver (3)
        // locale: timezone, locale (2)
        // total unique: 5
        $this->assertCount(5, $results);
    }

    #[Test]
    public function orWhereJson(): void
    {
        // Settings with priority 1 OR priority 5
        $query = (new ActiveQuery())
            ->from('setting')
            ->whereJson('metadata->priority', '=', 1)
            ->orWhereJson('metadata->priority', '=', 5)
            ->withConnection('mysql');

        $results = $query->query($query);

        // priority 1: site_name, mail/driver, cache/driver (3)
        // priority 5: mail/from_address (1)
        $this->assertCount(4, $results);
    }
}
