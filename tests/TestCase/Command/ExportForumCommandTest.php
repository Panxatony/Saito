<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * End-to-end: the command writes a file, and every line of it is valid JSON of
 * a known type, headed by the meta record.
 */
class ExportForumCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.User',
        'app.Setting',
        'plugin.ImageUploader.Uploads',
    ];

    private string $outPath = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->outPath = TMP . 'forum-export-test-' . uniqid() . '.jsonl';
    }

    public function tearDown(): void
    {
        if ($this->outPath !== '' && is_file($this->outPath)) {
            unlink($this->outPath);
        }
        parent::tearDown();
    }

    /**
     * The default destination is created for the owner alone. It collects full
     * dumps of personal data, so the group has no business reading — or
     * replacing — what lands there.
     *
     * @return void
     */
    public function testDefaultExportDirectoryIsOwnerOnly(): void
    {
        $dir = TMP . 'export';
        $preexisting = is_dir($dir);

        $this->exec('export_forum');
        $this->assertExitSuccess();
        $this->assertDirectoryExists($dir);

        if (!$preexisting) {
            // Only meaningful for a directory this run created: an existing one
            // keeps whatever mode the operator gave it.
            $this->assertSame(
                '0700',
                substr(sprintf('%o', fileperms($dir)), -4),
                'the export directory must not be readable beyond its owner',
            );
        }

        foreach ((array)glob($dir . DS . 'forum-*.jsonl') as $file) {
            unlink((string)$file);
        }
        if (!$preexisting) {
            rmdir($dir);
        }
    }

    public function testExportsValidJsonLines(): void
    {
        $this->exec('export_forum -o ' . escapeshellarg($this->outPath));

        $this->assertExitSuccess();
        $this->assertFileExists($this->outPath);

        $lines = array_values(array_filter(
            explode("\n", (string)file_get_contents($this->outPath)),
            static fn(string $line): bool => $line !== '',
        ));
        $this->assertNotEmpty($lines);

        // The first line is the header.
        $meta = json_decode($lines[0], true);
        $this->assertSame('meta', $meta['type']);
        $this->assertSame('saito-forum-export', $meta['format']);

        // Every line is valid JSON of a known type.
        $counts = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            $this->assertIsArray($record, "not valid JSON: $line");
            $this->assertArrayHasKey('type', $record);
            $counts[$record['type']] = ($counts[$record['type']] ?? 0) + 1;
        }

        // The fixtures have rows in every section, so each type is present.
        foreach (['meta', 'user', 'category', 'posting', 'upload'] as $type) {
            $this->assertArrayHasKey($type, $counts, "no $type records were written");
        }

        // Every member's e-mail address, and their IPs where the forum keeps
        // them, in one file: readable by its owner and nobody else. The default
        // umask made it 0644, which on a shared host is the whole membership
        // list handed to any local account.
        $this->assertSame(
            '0600',
            substr(sprintf('%o', fileperms($this->outPath)), -4),
            'the export must not be readable beyond its owner',
        );

        // What the header promised is what the file holds.
        $this->assertSame($meta['counts']['users'], $counts['user']);
        $this->assertSame($meta['counts']['categories'], $counts['category']);
        $this->assertSame($meta['counts']['postings'], $counts['posting']);
        $this->assertSame($meta['counts']['uploads'], $counts['upload']);
    }
}
