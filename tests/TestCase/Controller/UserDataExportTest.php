<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\I18n\DateTime;
use Saito\Test\IntegrationTestCase;

/**
 * The export hands a person a file about themselves, so the tests are weighted
 * towards what must *not* be in it.
 *
 * Three things could go wrong here and only one of them is a bug in the usual
 * sense: handing the file to the wrong person, putting somebody else's data in
 * it, and putting credentials in it. Each gets its own test, and each is written
 * so that it fails loudly rather than by omission — asserting on the absence of
 * a string is only worth something if something else proves the export is not
 * simply empty.
 */
class UserDataExportTest extends IntegrationTestCase
{
    public array $fixtures = [
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
        'app.Draft',
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
    ];

    /**
     * Fetch the export for the logged-in member and decode it.
     *
     * @param int $userId member to log in as
     * @return array<string, mixed>
     */
    private function exportFor(int $userId): array
    {
        $this->_loginUser($userId);
        $this->get('/users/export');
        $this->assertResponseOk();

        return json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A guest gets nothing.
     *
     * @return void
     */
    public function testAGuestCannotExportAnything(): void
    {
        $this->get('/users/export');

        $this->assertRedirect();
        $this->assertResponseNotContains('username');
    }

    /**
     * The file is a download, is valid JSON, and is about the member who asked.
     *
     * @return void
     */
    public function testAMemberGetsTheirOwnDataAsADownload(): void
    {
        $this->_loginUser(3);
        $this->get('/users/export');

        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'application/json');
        $this->assertHeaderContains('Content-Disposition', 'attachment');
        $this->assertHeaderContains('Content-Disposition', '.json');

        $data = json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $data['account']['id']);
    }

    /**
     * There is no parameter to point it at somebody else.
     *
     * The action reads the account from the session and takes no argument, so
     * this asserts the shape of the defence rather than probing one guess at a
     * time: whatever is appended to the URL, the answer is about the member who
     * is logged in.
     *
     * @return void
     */
    public function testItCannotBeAimedAtAnotherMember(): void
    {
        $this->_loginUser(3);

        foreach (['/users/export/1', '/users/export?id=1', '/users/export/1.json'] as $url) {
            $this->get($url);
            if ($this->_response->getStatusCode() !== 200) {
                // A route that does not exist is an acceptable answer too.
                continue;
            }
            $data = json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(3, $data['account']['id'], "leaked through $url");
        }
    }

    /**
     * No credentials in the file.
     *
     * Checked against the raw body rather than the decoded array: a hash could
     * appear anywhere, including somewhere nobody thought to look.
     *
     * @return void
     */
    public function testNoCredentialsAreHandedOut(): void
    {
        $this->_loginUser(3);
        $this->get('/users/export');
        $body = (string)$this->_response->getBody();

        $password = $this->getTableLocator()->get('Users')->get(3)->get('password');

        $this->assertNotEmpty($password, 'the fixture must have a password, or this proves nothing');
        $this->assertStringNotContainsString($password, $body, 'password hash leaked');
        $this->assertStringNotContainsString('"password"', $body);
        $this->assertStringNotContainsString('activate_code', $body);
    }

    /**
     * Postings the member wrote are in it, with their text.
     *
     * The positive control for every "must not contain" above: without this the
     * absence assertions would pass on an empty file.
     *
     * @return void
     */
    public function testTheirOwnPostingsAreIncludedInFull(): void
    {
        $data = $this->exportFor(3);

        $this->assertNotEmpty($data['postings'], 'nothing to check against');
        $subjects = array_column($data['postings'], 'subject');
        $this->assertContains('First_Subject', $subjects);
        foreach ($data['postings'] as $posting) {
            $this->assertArrayHasKey('text', $posting);
            $this->assertArrayHasKey('written', $posting);
        }
    }

    /**
     * Other people's postings are not.
     *
     * @return void
     */
    public function testOtherPeoplesPostingsAreNotIncluded(): void
    {
        $Entries = $this->getTableLocator()->get('Entries');
        $foreign = $Entries->find()->where(['user_id !=' => 3])->firstOrFail();

        $data = $this->exportFor(3);

        $ids = array_column($data['postings'], 'id');
        $this->assertNotContains($foreign->get('id'), $ids);
    }

    /**
     * Who imposed a block is not named.
     *
     * The block is about this member and is included with its reason; the
     * moderator behind it is another person, and Art. 15(4) says the copy must
     * not come at their expense.
     *
     * @return void
     */
    public function testABlockIsShownWithoutNamingTheModerator(): void
    {
        $Blocks = $this->getTableLocator()->get('UserBlocks');
        $Blocks->save($Blocks->newEntity([
            'user_id' => 3,
            'blocked_by_user_id' => 1,
            'reason' => 'a stated reason',
            'ends' => new DateTime('+1 day'),
        ], ['accessibleFields' => ['*' => true]]));

        $data = $this->exportFor(3);

        $this->assertNotEmpty($data['blocks_against_this_account']);
        $block = $data['blocks_against_this_account'][0];
        $this->assertSame('a stated reason', $block['reason'], 'the reason is theirs to have');
        $this->assertArrayNotHasKey('blocked_by_user_id', $block);
        $this->assertSame('a moderator', $block['imposed_by']);
    }

    /**
     * Who ignores *this* member stays out; who *they* ignore comes along.
     *
     * The two directions live in the same table and differ by one column, which
     * is exactly the kind of thing that gets mixed up once.
     *
     * @return void
     */
    public function testOnlyTheirOwnIgnoreListIsExported(): void
    {
        $Ignores = $this->getTableLocator()->get('UserIgnores');
        // 3 ignores 1 — theirs.
        $Ignores->save($Ignores->newEntity(
            ['user_id' => 3, 'blocked_user_id' => 1],
            ['accessibleFields' => ['*' => true]],
        ));
        // 2 ignores 3 — somebody else's decision about their own reading.
        $Ignores->save($Ignores->newEntity(
            ['user_id' => 2, 'blocked_user_id' => 3],
            ['accessibleFields' => ['*' => true]],
        ));

        $data = $this->exportFor(3);

        $ignored = array_column($data['people_this_account_ignores'], 'user_id');
        $this->assertContains(1, $ignored, 'their own list must be in it');
        $this->assertCount(1, $ignored, 'and nothing about who ignores them');
    }
}
