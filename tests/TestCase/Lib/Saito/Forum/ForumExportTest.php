<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Lib\Saito\Forum;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Saito\Forum\ForumExport;

/**
 * The whole-forum export streams the entire forum. The tests weigh two things:
 * that every row of each table is yielded (the export is not quietly partial),
 * and that credentials never leave through it.
 */
class ForumExportTest extends TestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.User',
        'app.Setting',
        'plugin.ImageUploader.Uploads',
    ];

    private ForumExport $export;

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('Saito.Settings.forum_name', 'Test Forum');
        $this->export = new ForumExport();
    }

    /**
     * @param string $table table registry alias
     * @return int
     */
    private function rowCount(string $table): int
    {
        return TableRegistry::getTableLocator()->get($table)->find()->count();
    }

    /**
     * @param iterable<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function collect(iterable $records): array
    {
        return iterator_to_array($records, false);
    }

    public function testMetaNamesTheFormatAndCountsEverything(): void
    {
        $meta = $this->export->meta();

        $this->assertSame('meta', $meta['type']);
        $this->assertSame(ForumExport::FORMAT, $meta['format']);
        $this->assertSame(ForumExport::FORMAT_VERSION, $meta['version']);
        $this->assertSame('Test Forum', $meta['forum']);

        $this->assertSame($this->rowCount('Users'), $meta['counts']['users']);
        $this->assertSame($this->rowCount('Categories'), $meta['counts']['categories']);
        $this->assertSame($this->rowCount('Entries'), $meta['counts']['postings']);
        $this->assertSame($this->rowCount('ImageUploader.Uploads'), $meta['counts']['uploads']);
    }

    public function testEachSectionYieldsEveryRow(): void
    {
        $this->assertCount($this->rowCount('Users'), $this->collect($this->export->eachUser()));
        $this->assertCount($this->rowCount('Categories'), $this->collect($this->export->eachCategory()));
        $this->assertCount($this->rowCount('Entries'), $this->collect($this->export->eachPosting()));
        $this->assertCount($this->rowCount('ImageUploader.Uploads'), $this->collect($this->export->eachUpload()));
    }

    public function testUserRecordsCarryNoCredentials(): void
    {
        $users = $this->collect($this->export->eachUser());
        $this->assertNotEmpty($users);
        foreach ($users as $user) {
            $this->assertSame('user', $user['type']);
            $this->assertArrayHasKey('username', $user);
            // The whole point: a plaintext content file must not carry auth.
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayNotHasKey('activate_code', $user);
        }
    }

    public function testPostingRecordsCarryAuthorAndThreadReferences(): void
    {
        $postings = $this->collect($this->export->eachPosting());
        $this->assertNotEmpty($postings);
        $first = $postings[0];
        $this->assertSame('posting', $first['type']);
        // The references an importer needs to rebuild the structure.
        foreach (['id', 'user_id', 'reply_to', 'thread', 'category_id'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
    }

    public function testUploadRecordsAreMetadataWithTheAuthorAndUrl(): void
    {
        $uploads = $this->collect($this->export->eachUpload());
        $this->assertNotEmpty($uploads);
        $first = $uploads[0];
        $this->assertSame('upload', $first['type']);
        $this->assertArrayHasKey('user_id', $first);
        $this->assertStringStartsWith('/useruploads/', $first['url']);
    }
}
