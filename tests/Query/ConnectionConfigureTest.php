<?php

namespace Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\DB\Connection;

/**
 * Loading connections from a config file.
 *
 * configure() is the documented bootstrap entry point, and it is the one place
 * a whole application's connection setup can go wrong at once. It deliberately
 * does not throw — an application that cannot read its config is left to fail
 * on the first get() — so the only way a mistake reaches anyone is the warning
 * it raises. These tests hold it to raising one.
 */
class ConnectionConfigureTest extends TestCase
{
    /** @var string Directory holding this test's config fixtures. */
    private string $dir = '';

    protected function setUp(): void
    {
        Connection::reset();
        $this->dir = sys_get_temp_dir() . '/fliq_cfg_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        Connection::reset();
    }

    /**
     * Write a config file and return its path.
     *
     * @param string $name File name within the fixture directory.
     * @param string $contents Verbatim PHP source.
     * @return string The full path.
     */
    private function file(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * The connection names currently registered, in registration order.
     *
     * @return array<int, string> Registered names.
     */
    private function registered(): array
    {
        $config = new ReflectionProperty(Connection::class, 'config')->getValue();
        $this->assertIsArray($config);

        return array_map(strval(...), array_keys($config));
    }

    /**
     * Run configure() and capture the warnings it raises.
     *
     * @param string $path The config file to load.
     * @return array<int, string> Warning messages, in order.
     */
    private function warningsFrom(string $path): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            Connection::configure($path);
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    #[Test]
    public function aValidFileRegistersEveryConnection(): void
    {
        $path = $this->file('good.php', <<<'PHP'
            <?php
            return [
                'primary' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'replica' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ];
            PHP);

        $this->assertSame([], $this->warningsFrom($path));
        $this->assertSame(['primary', 'replica'], $this->registered());
    }

    #[Test]
    public function aLoadedConnectionIsUsable(): void
    {
        // Registering the name is not the point; being able to connect is.
        $path = $this->file('usable.php', <<<'PHP'
            <?php
            return ['loaded' => ['driver' => 'sqlite', 'database' => ':memory:']];
            PHP);

        Connection::configure($path);

        $this->assertTrue(Connection::has('loaded'));
        // get() throws when the config is missing or the driver cannot
        // connect, so reaching a working driver is the assertion.
        $this->assertFalse(Connection::get('loaded')->hasError());
    }

    #[Test]
    public function configureAddsToWhatIsAlreadyRegistered(): void
    {
        Connection::add('manual', ['driver' => 'sqlite', 'database' => ':memory:']);

        $path = $this->file('add.php', <<<'PHP'
            <?php
            return ['from_file' => ['driver' => 'sqlite', 'database' => ':memory:']];
            PHP);

        Connection::configure($path);

        $this->assertSame(['manual', 'from_file'], $this->registered());
    }

    #[Test]
    public function aMissingFileWarnsInsteadOfPassingSilently(): void
    {
        // The failure mode this replaces: a typo in the path registered
        // nothing, said nothing, and surfaced as "connection not found"
        // thrown much later from somewhere with no bearing on the mistake.
        $path = $this->dir . '/absent.php';

        $warnings = $this->warningsFrom($path);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('not found', $warnings[0]);
        $this->assertStringContainsString('absent.php', $warnings[0]);
        $this->assertSame([], $this->registered());
    }

    #[Test]
    public function aDirectoryPathIsRejectedAsAMissingFile(): void
    {
        // file_exists() accepts a directory, so this used to reach require()
        // and report a raw "failed to open stream" against a path the caller
        // had deliberately passed.
        $warnings = $this->warningsFrom($this->dir);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('not found', $warnings[0]);
        $this->assertSame([], $this->registered());
    }

    #[Test]
    public function aFileReturningANonArrayWarnsWithTheTypeItGaveBack(): void
    {
        $path = $this->file('string.php', "<?php\nreturn 'not an array';\n");

        $warnings = $this->warningsFrom($path);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('must return an array', $warnings[0]);
        $this->assertStringContainsString('string returned', $warnings[0]);
        $this->assertSame([], $this->registered());
    }

    #[Test]
    public function aFileReturningNothingWarns(): void
    {
        // A config file where someone forgot the return statement. PHP hands
        // back int(1) for this, which is why the message names the type.
        $path = $this->file('noreturn.php', "<?php\n\$connections = [];\n");

        $warnings = $this->warningsFrom($path);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('must return an array', $warnings[0]);
        $this->assertSame([], $this->registered());
    }

    #[Test]
    public function aFileReturningNullWarns(): void
    {
        $path = $this->file('null.php', "<?php\nreturn null;\n");

        $warnings = $this->warningsFrom($path);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('null returned', $warnings[0]);
    }

    #[Test]
    public function anEmptyArrayIsAcceptedWithoutComplaint(): void
    {
        // Declaring no connections is legitimate, not an error.
        $path = $this->file('empty.php', "<?php\nreturn [];\n");

        $this->assertSame([], $this->warningsFrom($path));
        $this->assertSame([], $this->registered());
    }

    #[Test]
    public function aFileThatThrowsIsReportedAndDoesNotEscape(): void
    {
        $path = $this->file('throws.php', "<?php\nthrow new \\RuntimeException('config unavailable');\n");

        $warnings = $this->warningsFrom($path);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('failed to load', $warnings[0]);
        $this->assertStringContainsString('config unavailable', $warnings[0]);
        $this->assertSame([], $this->registered());
    }

    #[Test]
    public function theWarningNamesTheFileThatFailed(): void
    {
        // With several config files in a bootstrap, a message that does not
        // say which one broke is barely better than silence.
        $path = $this->file('named.php', "<?php\nthrow new \\RuntimeException('boom');\n");

        $warnings = $this->warningsFrom($path);

        $this->assertStringContainsString('named.php', $warnings[0]);
    }

    #[Test]
    public function aMalformedEntryIsSkippedRatherThanEndingTheFile(): void
    {
        // The defect this covers: add() raised a TypeError on the scalar,
        // the catch sat outside the loop, and every connection declared after
        // the bad one was silently discarded. The application then booted
        // half-configured, with no indication which half.
        $path = $this->file('mixed.php', <<<'PHP'
            <?php
            return [
                'first'  => ['driver' => 'sqlite', 'database' => ':memory:'],
                'second' => 'not-an-array',
                'third'  => ['driver' => 'sqlite', 'database' => ':memory:'],
            ];
            PHP);

        $warnings = $this->warningsFrom($path);

        $this->assertSame(['first', 'third'], $this->registered());
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'second'", $warnings[0]);
        $this->assertStringContainsString('Skipped', $warnings[0]);
    }

    #[Test]
    public function everyMalformedEntryIsReportedSeparately(): void
    {
        $path = $this->file('many_bad.php', <<<'PHP'
            <?php
            return [
                'a' => 'nope',
                'b' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'c' => 42,
                'd' => null,
            ];
            PHP);

        $warnings = $this->warningsFrom($path);

        $this->assertCount(3, $warnings);
        $this->assertSame(['b'], $this->registered());
    }

    #[Test]
    public function theSkipWarningNamesTheTypeThatWasGiven(): void
    {
        $path = $this->file('typed.php', "<?php\nreturn ['broken' => 42];\n");

        $warnings = $this->warningsFrom($path);

        $this->assertStringContainsString('int given', $warnings[0]);
    }

    #[Test]
    public function aSkippedEntryIsNotRegisteredAtAll(): void
    {
        // Skipping must mean skipping — not registering something unusable
        // that fails later at get().
        $path = $this->file('skip.php', "<?php\nreturn ['bad' => 'nope'];\n");

        $this->warningsFrom($path);

        $this->assertFalse(Connection::has('bad'));
    }

    #[Test]
    public function aConnectionsSurvivingEntryIsFullyUsable(): void
    {
        // The point of not aborting: what came after the bad entry works.
        $path = $this->file('survivor.php', <<<'PHP'
            <?php
            return [
                'broken'  => 'not-an-array',
                'working' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ];
            PHP);

        $this->warningsFrom($path);

        $this->assertTrue(Connection::has('working'));
        $this->assertFalse(Connection::get('working')->hasError());
    }

    #[Test]
    public function anIntegerKeyedEntryIsRegisteredUnderItsStringForm(): void
    {
        // A config written as a list rather than a map. add() takes a string,
        // and PHP array keys come back as int, so the cast is what keeps this
        // from being a TypeError that skips the entry.
        $path = $this->file('list.php', <<<'PHP'
            <?php
            return [['driver' => 'sqlite', 'database' => ':memory:']];
            PHP);

        $this->assertSame([], $this->warningsFrom($path));
        $this->assertSame(['0'], $this->registered());
        $this->assertTrue(Connection::has('0'));
    }
}
