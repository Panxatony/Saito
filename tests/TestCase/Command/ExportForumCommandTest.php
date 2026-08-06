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

        // What the header promised is what the file holds.
        $this->assertSame($meta['counts']['users'], $counts['user']);
        $this->assertSame($meta['counts']['categories'], $counts['category']);
        $this->assertSame($meta['counts']['postings'], $counts['posting']);
        $this->assertSame($meta['counts']['uploads'], $counts['upload']);
    }
}
