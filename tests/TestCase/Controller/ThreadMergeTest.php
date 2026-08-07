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

use Cake\Http\Exception\NotFoundException;
use Cake\ORM\TableRegistry;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;

/**
 * Hanging one thread under another (#83).
 *
 * The most destructive moderation action that asks for no second confirmation,
 * and it had no test. A merge cannot be undone with a button: get it wrong and
 * threads are rearranged for every member at once, repairable only by hand in
 * the database.
 *
 * The weight is on what must *not* happen — an ordinary member cannot do it, a
 * thread cannot be hung under itself, a reply is not a thread — and on one thing
 * that is easy to get backwards. A merge does move postings into the target's
 * category. The first draft of this file asserted the opposite from intuition
 * rather than from `PostingBehavior::threadMerge()`, which calls
 * `threadChangeCategory()` and says so in a comment. So a merge is also a
 * category move, which is why it is a moderator's permission and not the
 * author's.
 */
class ThreadMergeTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.Smiley',
        'app.SmileyCode',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    /** Alice, an administrator: allowed to merge. */
    private const ADMIN_ID = 1;

    /** Ulysses, an ordinary member. */
    private const MEMBER_ID = 3;

    /**
     * @param int $id posting id
     * @return array{pid: int, tid: int, category_id: int}
     */
    private function posting(int $id): array
    {
        $row = TableRegistry::getTableLocator()->get('Entries')
            ->find()->select(['pid', 'tid', 'category_id'])
            ->where(['id' => $id])->first();

        return [
            'pid' => (int)$row->get('pid'),
            'tid' => (int)$row->get('tid'),
            'category_id' => (int)$row->get('category_id'),
        ];
    }

    /**
     * @param int $sourceId thread root to move
     * @param int $targetId posting it should hang under
     * @return void
     */
    private function merge(int $sourceId, int $targetId): void
    {
        $this->configureHtmxRequest();
        $this->mockSecurity();
        $this->post('/entries/htmx-merge/' . $sourceId, ['targetId' => $targetId]);
    }

    /**
     * Thread 10 (a root of its own) moved under posting 1, the root of thread 1.
     *
     * @return void
     */
    public function testAModeratorCanMergeAThread(): void
    {
        $this->_loginUser(self::ADMIN_ID);
        $this->merge(10, 1);

        $moved = $this->posting(10);
        $this->assertSame(1, $moved['pid'], 'the source root must hang under the target');
        $this->assertSame(1, $moved['tid'], 'and belong to the target thread');
        $this->assertNotEmpty(
            $this->_response->getHeaderLine('HX-Redirect'),
            'htmx follows a 302 and would swap the thread into the merge form',
        );
    }

    /**
     * The subtree has to come along. Moving only the root would leave its
     * replies orphaned in a thread whose root is somewhere else — invisible,
     * and unreachable through the interface.
     *
     * @return void
     */
    public function testTheWholeSubtreeMovesWithTheRoot(): void
    {
        $this->_loginUser(self::ADMIN_ID);
        // Thread 4 has replies 5 and 12.
        $this->merge(4, 1);

        $this->assertSame(1, $this->posting(4)['tid']);
        $this->assertSame(1, $this->posting(5)['tid'], 'a reply must not be left behind');
        $this->assertSame(1, $this->posting(12)['tid']);
    }

    public function testAnOrdinaryMemberCannotMerge(): void
    {
        $this->_loginUser(self::MEMBER_ID);

        $this->expectException(SaitoForbiddenException::class);
        $this->merge(10, 1);
    }

    /**
     * A thread cannot be hung under itself — it would become its own ancestor
     * and the tree would no longer terminate.
     *
     * @return void
     */
    public function testAThreadCannotBeMergedIntoItself(): void
    {
        $this->_loginUser(self::ADMIN_ID);
        $before = $this->posting(1);

        // Posting 2 is a reply inside thread 1, so its tid is thread 1's.
        $this->merge(1, 2);

        $this->assertSame($before, $this->posting(1), 'nothing may move');
        $this->assertResponseContains('alert-error');
    }

    /**
     * Only a thread root can be moved. A reply already hangs somewhere; moving
     * it through this action would be an edit, not a merge, and it would leave
     * its own replies behind.
     *
     * @return void
     */
    public function testAReplyCannotBeMergedAsIfItWereAThread(): void
    {
        $this->_loginUser(self::ADMIN_ID);

        // Posting 2 is a reply, not a root. The action throws rather than
        // rendering a 404, and the harness disables the error handler, so the
        // exception is what arrives here.
        $this->configureHtmxRequest();
        $this->mockSecurity();

        try {
            $this->post('/entries/htmx-merge/2', ['targetId' => 10]);
            $this->fail('a reply must not be mergeable as if it were a thread');
        } catch (NotFoundException) {
            // expected
        }

        $this->assertSame(1, $this->posting(2)['pid'], 'nothing may move');
    }

    /**
     * A merge is also a category move, and that is deliberate.
     *
     * Written first asserting the opposite — that `category_id` is left alone —
     * which was an invention, not a reading: `PostingBehavior::threadMerge()`
     * calls `threadChangeCategory()` and says so in a comment. The appended
     * postings take the target thread's category.
     *
     * Worth a test precisely because it is easy to miss when reviewing who may
     * merge. The action looks like "rearrange the tree" and is in fact also
     * "move this content into another category", which is why
     * `saito.core.posting.merge` is a moderator permission and not something a
     * thread's author may do to their own thread.
     *
     * @return void
     */
    public function testAMergeMovesThePostingsIntoTheTargetCategory(): void
    {
        $this->_loginUser(self::ADMIN_ID);
        // Thread 6 lives in category 1, restricted to moderators in the
        // fixture; thread 1 lives in the open category 2.
        $this->assertSame(1, $this->posting(6)['category_id'], 'premise of this test');

        $this->merge(6, 1);

        $after = $this->posting(6);
        $this->assertSame(1, $after['tid'], 'the merge itself must have happened');
        $this->assertSame(
            2,
            $after['category_id'],
            'the appended thread takes the target category — see threadChangeCategory()',
        );
    }

    /**
     * …and the reader sees it, because it is genuinely in the open category now.
     *
     * The other half of the same behaviour, stated from the reader's side so
     * nobody has to infer it. This is a moderator deliberately publishing a
     * thread by merging it, not a leak: the same person can move a thread
     * between categories by editing it, and the merge permission is a
     * moderator's.
     *
     * It is pinned here because the alternative — a thread whose postings keep
     * a category the reader cannot see — would render a thread full of holes,
     * and somebody reading only `threadMerge()` might well decide that is the
     * safer behaviour and "fix" it.
     *
     * @return void
     */
    public function testAMergedThreadIsReadableWhereItNowLives(): void
    {
        $this->_loginUser(self::ADMIN_ID);
        $subject = (string)TableRegistry::getTableLocator()->get('Entries')
            ->get(6)->get('subject');
        $this->merge(6, 1);

        $this->_logoutUser();
        $this->_loginUser(self::MEMBER_ID);
        $this->configureHtmxRequest();
        $this->get('/entries/htmx-thread/1');

        $this->assertResponseOk();
        $this->assertResponseContains($subject);
    }
}
