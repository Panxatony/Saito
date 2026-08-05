<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Saito\Test\IntegrationTestCase;

/**
 * "Where is this upload used?" (issue #64).
 *
 * @covers \App\Controller\EntriesController::htmxUploadUsage
 */
class EntriesControllerUploadUsageTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Draft',
        'app.Entry',
        'app.Setting',
        'app.Smiley',
        'app.SmileyCode',
        'app.User',
        'app.UserOnline',
        'app.UserRead',
        'plugin.ImageUploader.Uploads',
        'plugin.Bookmarks.Bookmark',
    ];

    protected \App\Model\Table\EntriesTable $Entries;
    protected \Cake\ORM\Table $Uploads;

    public function setUp(): void
    {
        parent::setUp();
        $this->Entries = TableRegistry::getTableLocator()->get('Entries');
        $this->Uploads = TableRegistry::getTableLocator()->get('ImageUploader.Uploads');
    }

    /**
     * Only the owner's postings that actually embed the upload are listed.
     */
    public function testListsOnlyOwnerPostingsThatEmbedIt(): void
    {
        $this->_loginUser(1);

        // Upload 1 is 'll-my-upload.png' owned by user 1.
        $name = $this->Uploads->get(1)->get('name');
        $embed = '[img src=upload]' . $name . '[/img]';

        // Entry 1: user 1 embeds it -> listed. Entry 2: user 1, no embed.
        // Entry 3: user 3 embeds it -> excluded, the scan is owner-scoped.
        $this->Entries->updateAll(['user_id' => 1, 'text' => 'see ' . $embed], ['id' => 1]);
        $this->Entries->updateAll(['user_id' => 1, 'text' => 'nothing to see'], ['id' => 2]);
        $this->Entries->updateAll(['user_id' => 3, 'text' => $embed], ['id' => 3]);

        $this->get('/entries/htmx-upload-usage/1');

        $this->assertResponseOk();
        $this->assertResponseContains('entries/view/1');
        $this->assertResponseNotContains('entries/view/2');
        $this->assertResponseNotContains('entries/view/3');
    }

    /**
     * A filename's underscores are matched literally, not as LIKE wildcards.
     */
    public function testUnderscoreInFilenameIsNotAWildcard(): void
    {
        $this->_loginUser(1);

        // Give upload 2 a name with an underscore, owned by user 1.
        $this->Uploads->updateAll(['name' => 'holiday_2020.png', 'user_id' => 1], ['id' => 2]);

        // Entry 4 has the literal name; entry 5 has a string that only matches
        // if the underscore is treated as a single-character wildcard.
        $this->Entries->updateAll(['user_id' => 1, 'text' => '[img src=upload]holiday_2020.png[/img]'], ['id' => 4]);
        $this->Entries->updateAll(['user_id' => 1, 'text' => 'holidayX2020.png'], ['id' => 5]);

        $this->get('/entries/htmx-upload-usage/2');

        $this->assertResponseOk();
        $this->assertResponseContains('entries/view/4');
        $this->assertResponseNotContains('entries/view/5');
    }

    /**
     * An upload nothing embeds returns the empty state, not a list.
     */
    public function testUnusedUploadShowsTheEmptyState(): void
    {
        $this->_loginUser(1);

        $this->Uploads->updateAll(['name' => 'never_referenced_zzz.png', 'user_id' => 1], ['id' => 1]);

        $this->get('/entries/htmx-upload-usage/1');

        $this->assertResponseOk();
        $this->assertResponseContains('upload-usage-none');
        $this->assertResponseNotContains('entries/view/');
    }

    /**
     * A member cannot list the usage of another member's upload.
     */
    public function testCannotViewAnotherMembersUploadUsage(): void
    {
        // Upload 1 belongs to user 1; act as user 3 (a plain member).
        $this->_loginUser(3);

        $this->expectException(\Saito\Exception\SaitoForbiddenException::class);
        $this->get('/entries/htmx-upload-usage/1');
    }
}
