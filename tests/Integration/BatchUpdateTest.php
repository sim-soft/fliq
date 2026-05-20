<?php

namespace Integration;

use Models\Setting;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for Model::updateBatch().
 */
class BatchUpdateTest extends DatabaseTestCase
{
    #[Test]
    public function updateBatchUpdatesMultipleRows(): void
    {
        $result = Setting::updateBatch([
            ['id' => 1, 'value' => 'Updated Site Name'],
            ['id' => 2, 'value' => 'UTC'],
            ['id' => 3, 'value' => 'fr'],
        ]);

        $this->assertTrue($result);

        $setting1 = Setting::findByPk(1);
        $setting2 = Setting::findByPk(2);
        $setting3 = Setting::findByPk(3);

        $this->assertNotNull($setting1);
        $this->assertNotNull($setting2);
        $this->assertNotNull($setting3);
        $this->assertEquals('Updated Site Name', $setting1->value);
        $this->assertEquals('UTC', $setting2->value);
        $this->assertEquals('fr', $setting3->value);
    }

    #[Test]
    public function updateBatchWithMultipleColumns(): void
    {
        $result = Setting::updateBatch([
            ['id' => 4, 'group' => 'email', 'value' => 'sendmail'],
            ['id' => 5, 'group' => 'email', 'value' => 'smtp.gmail.com'],
        ]);

        $this->assertTrue($result);

        $setting4 = Setting::findByPk(4);
        $setting5 = Setting::findByPk(5);

        $this->assertNotNull($setting4);
        $this->assertNotNull($setting5);
        $this->assertEquals('email', $setting4->group);
        $this->assertEquals('sendmail', $setting4->value);
        $this->assertEquals('email', $setting5->group);
        $this->assertEquals('smtp.gmail.com', $setting5->value);
    }

    #[Test]
    public function updateBatchReturnsFalseForEmptyArray(): void
    {
        $result = Setting::updateBatch([]);
        $this->assertFalse($result);
    }

    #[Test]
    public function updateBatchWithSingleRow(): void
    {
        $result = Setting::updateBatch([
            ['id' => 10, 'value' => '7200'],
        ]);

        $this->assertTrue($result);

        $setting = Setting::findByPk(10);
        $this->assertNotNull($setting);
        $this->assertEquals('7200', $setting->value);
    }

    #[Test]
    public function updateBatchDoesNotAffectOtherRows(): void
    {
        // Get original value of setting 9
        $original = Setting::findByPk(9);
        $this->assertNotNull($original);
        $originalValue = $original->value;

        // Update only settings 1 and 2
        Setting::updateBatch([
            ['id' => 1, 'value' => 'Batch Test'],
            ['id' => 2, 'value' => 'Batch Test 2'],
        ]);

        // Setting 9 should be unchanged
        $unchanged = Setting::findByPk(9);
        $this->assertNotNull($unchanged);
        $this->assertEquals($originalValue, $unchanged->value);
    }
}
