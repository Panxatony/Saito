<?php

/**
 * Saito - The Threaded Web Forum
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Model\Behavior;

use App\Model\Table\EntriesTable;
use Saito\Test\Model\Table\SaitoTableTestCase;

class PostingBehaviorTest extends SaitoTableTestCase
{
    public $tableClass = 'Entries';

    /** @var EntriesTable */
    public $Table;

    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.User',
        'plugin.Bookmarks.Bookmark',
    ];

    public function testDeletePostingCompleteThread()
    {
        $tid = 1;

        //= test thread exists before we delete it
        $countBeforeDelete = $this->Table->find()
            ->where(['tid' => $tid])
            ->count();
        $expected = 6;
        $this->assertEquals($countBeforeDelete, $expected);

        $allBookmarksBeforeDelete = $this->Table->Bookmarks->find()->count();

        $result = $this->Table->deletePosting($tid);
        $this->assertTrue($result);

        //= all postings in thread should be deleted
        $result = $this->Table->find()->where(['tid' => $tid])->count();
        $expected = 0;
        $this->assertEquals($result, $expected);

        // delete associated bookmarks
        $allBookmarksAfterDelete = $this->Table->Bookmarks->find()->count();
        $numberOfBookmarksForTheDeletedThread = 2;
        $this->assertEquals(
            $allBookmarksBeforeDelete - $numberOfBookmarksForTheDeletedThread,
            $allBookmarksAfterDelete
        );
    }

    public function testDeletePostingSubthread()
    {
        $tid = 1;

        /// test thread exists before we delete it
        $countBeforeDelete = $this->Table->find()
            ->where(['tid' => $tid])
            ->count();
        $expected = 6;
        $this->assertEquals($countBeforeDelete, $expected);

        $this->Table->deletePosting(2);

        $after = $this->Table->find(
            'list',
            where: ['tid' => $tid],
            keyField: 'id',
            valueField: 'id',
        )->toArray();

        $this->assertArrayHasKey(1, $after);
        $this->assertArrayHasKey(8, $after);
    }

    public function testLockNotRootEntryFailure()
    {
        $entity = $this->Table->get(2);
        $this->assertFalse($entity->get('locked'));

        // `locked` is not assignable from a plain array (Entry::$_accessible);
        // this test is about the behaviour's rule that only a thread's root may
        // be locked, so it names the field the way the one authorized path
        // does.
        $patched = $this->Table->patchEntity(
            $entity,
            ['locked' => true],
            ['accessibleFields' => ['locked' => true]]
        );
        $this->Table->save($patched);

        $this->assertTrue($patched->hasErrors());
        $this->assertNotEmpty($patched->getError('locked'));
    }

    public function testLockSuccess()
    {
        $entity = $this->Table->get(1);
        $this->assertEquals(0, $this->Table->find()->where(['tid' => 1, 'locked' => true])->all()->count());

        $patched = $this->Table->patchEntity(
            $entity,
            ['locked' => true],
            ['accessibleFields' => ['locked' => true]]
        );
        $this->Table->save($patched);

        $this->assertGreaterThan(1, $this->Table->find()->where(['tid' => 1, 'locked' => true])->all()->count());
    }

    /**
     * A reply belongs to its parent's thread, and `tid` is how it says so.
     *
     * This is the test that was missing. `tid` is denied on the entity so a
     * request cannot move a posting between threads — but `createEntry()` has
     * to be able to set it, because PostingComponent hands it in from the
     * parent. When it could not, replies were saved with `tid` 0: present in
     * the database, absent from their own thread, and the island answered the
     * author with an error while their text sat there unreachable.
     *
     * Four of them reached production before anyone noticed.
     *
     * @return void
     */
    public function testAReplyInheritsItsParentsThreadId()
    {
        $parent = $this->Table->get(1);
        $reply = $this->Table->createEntry([
            'pid' => $parent->get('id'),
            'tid' => $parent->get('tid'),
            'subject' => 'Antwort',
            'text' => 'Text',
            'name' => 'Ulysses',
            'user_id' => 3,
            'category_id' => $parent->get('category_id'),
        ]);

        $this->assertNotNull($reply);
        $this->assertEmpty($reply->getErrors());
        $this->assertSame(
            $parent->get('tid'),
            $reply->get('tid'),
            'a reply saved without its thread id is invisible in its own thread'
        );
    }

    /**
     * And the author is still taken from the caller rather than the request —
     * the other field `createEntry()` names explicitly.
     *
     * @return void
     */
    public function testCreateEntryKeepsTheAuthorItWasGiven()
    {
        $posting = $this->Table->createEntry([
            'pid' => 0,
            'subject' => 'Neu',
            'text' => 'Text',
            'name' => 'Ulysses',
            'user_id' => 3,
            'category_id' => 1,
        ]);

        $this->assertSame(3, $posting->get('user_id'));
    }

    /**
     * The other half of the two tests above: without naming the field, a plain
     * array must not be able to set it.
     *
     * These four are what a request could otherwise carry into a posting —
     * authorship, moderation state, and the thread a posting belongs to — and
     * the protection is a property of the entity, so this is where it is
     * checked rather than at each call site that happens to be careful today.
     *
     * @return void
     */
    public function testPrivilegedFieldsAreNotMassAssignable()
    {
        $entity = $this->Table->get(1);
        $before = [
            'user_id' => $entity->get('user_id'),
            'locked' => $entity->get('locked'),
            'fixed' => $entity->get('fixed'),
            'tid' => $entity->get('tid'),
        ];

        $this->Table->patchEntity($entity, [
            'user_id' => 999,
            'locked' => true,
            'fixed' => true,
            'tid' => 4711,
        ]);

        foreach ($before as $field => $value) {
            $this->assertSame($value, $entity->get($field), "$field must not be mass-assignable");
        }
    }

    /**
     * And the authorized path still works.
     *
     * @return void
     */
    public function testSetPostingStatePins()
    {
        $entity = $this->Table->get(1);
        $this->assertFalse((bool)$entity->get('fixed'));

        $this->Table->setPostingState($entity, 'fixed', true);

        $this->assertTrue((bool)$this->Table->get(1)->get('fixed'));
    }

    /**
     * Category change is only allowed on root postings
     *
     * That will also change all posting in the root postings thread
     */
    public function testChangeCategoryOnNonRootFailure()
    {
        $posting = $this->Table->get(2, ['return' => 'Entity']);
        $posting->set('category_id', 3);
        $success = $this->Table->save($posting);

        $this->assertFalse($success);
        $errors = $posting->getErrors();
        $this->assertArrayHasKey('checkCategoryChangeOnlyOnRootPostings', $errors['category_id']);
    }

    /**
     * Test changing the category of a thread
     *
     * - Should change category-ID of every posting
     * - Should update the counter-cache for threads in category
     */
    public function testChangeThreadCategory()
    {
        $tid = 1;
        $oldCategory = 2;
        $newCategory = 1;

        $nPostingsBefore = $this->Table->find()
            ->where(['tid' => $tid, 'category_id' => $oldCategory])
            ->count();
        // there should be postings in that thread we move
        $this->assertGreaterThan(1, $nPostingsBefore);

        $nThreadsOldCategoryBefore = $this->Table->find()
            ->where(['pid' => 0, 'category_id' => $oldCategory])
            ->count();
        $categoryOld = $this->Table->Categories->find()
            ->where(['id' => $oldCategory])
            ->first();
        // check that thread counter cache is in order for old category
        $this->assertEquals($categoryOld->get('thread_count'), $nThreadsOldCategoryBefore);

        $nThreadsNewCategoryBefore = $this->Table->find()
            ->where(['pid' => 0, 'category_id' => $newCategory])
            ->count();
        $categoryNew = $this->Table->Categories->find()
            ->where(['id' => $newCategory])
            ->first();
        // check that thread counter cache is in order for new category
        $this->assertEquals($categoryNew->get('thread_count'), $nThreadsNewCategoryBefore);

        $posting = $this->Table->get(1, ['return' => 'Entity']);
        $this->Table->patchEntity($posting, ['category_id' => $newCategory]);
        $this->Table->save($posting);

        $nThreadsOldCategoryAfter = $this->Table->find()
            ->where(['pid' => 0, 'category_id' => $oldCategory])
            ->count();
        // thread should be removed from old category
        $this->assertEquals(--$nThreadsOldCategoryBefore, $nThreadsOldCategoryAfter);

        $categoryOld = $this->Table->Categories->find()
            ->where(['id' => $oldCategory])
            ->first();
        // check that thread counter cache is in order for old category
        $this->assertEquals($categoryOld->get('thread_count'), $nThreadsOldCategoryAfter);

        $nThreadsNewCategoryAfter = $this->Table->find()
            ->where(['pid' => 0, 'category_id' => $newCategory])
            ->count();
        // thread should be added to new category
        $this->assertEquals(++$nThreadsNewCategoryBefore, $nThreadsNewCategoryAfter);

        $categoryNew = $this->Table->Categories->find()
            ->where(['id' => $newCategory])
            ->first();
        // check that thread counter cache is in order for old category
        $this->assertEquals($categoryNew->get('thread_count'), $nThreadsNewCategoryAfter);

        $nPostingsAfter = $this->Table->find()
            ->where(['tid' => $tid, 'category_id' => $newCategory])
            ->count();
        // check category was changed on all postings
        $this->assertEquals($nPostingsBefore, $nPostingsAfter);
    }

    /**
     * Test merge
     *
     * Merge thread 2 (root-id: 4) onto entry 2 in thread 1
     */
    public function testThreadMerge()
    {
        // entry is not appended yet
        $appendedEntry = $this->Table->find()
            ->where(['id' => 4, 'pid' => 2])
            ->count();
        $this->assertEquals($appendedEntry, 0);

        // count both threads
        $targetEntryCount = $this->Table->find()->where(['tid' => 1])->count();
        $sourceEntryCount = $this->Table->find()->where(['tid' => 4])->count();

        // do the merge
        $this->Table->threadMerge(4, 2);

        // target thread is contains now all entries
        $targetEntryCountAfterMerge = $this->Table->find()
            ->where(['tid' => 1])
            ->count();
        $this->assertEquals(
            $targetEntryCountAfterMerge,
            $sourceEntryCount + $targetEntryCount
        );

        //appended entries have category of target thread
        $targetCategoryCount = $this->Table->find()
            ->where(['tid' => 1, 'category_id' => 2])
            ->count();
        $this->assertEquals(
            $targetCategoryCount,
            $targetEntryCount + $sourceEntryCount
        );

        // source thread is gone
        $sourceEntryCountAfterMerge = $this->Table->find()
            ->where(['tid' => 4])
            ->count();
        $this->assertEquals($sourceEntryCountAfterMerge, 0);

        // posting is appended now
        $appendedEntry = $this->Table->find()
            ->where(['id' => 4, 'pid' => 2])
            ->count();
        $this->assertEquals($appendedEntry, 1);
    }

    /**
     * test that a unpinned source thread is pinned after merge if target is
     * pinned
     */
    public function testThreadMergePin()
    {
        /// unlock source the fixture thread
        $posting = $this->Table->get(12);
        $this->assertTrue($posting->isLocked());

        /// lock the target fixture thread
        $posting = $this->Table->get(2);
        $this->assertFalse($posting->isLocked());

        /// merge
        $this->Table->threadMerge(1, 12);

        /// test
        $posting = $this->Table->get(2);
        $this->assertTrue($posting->isLocked());
    }

    /**
     * test that a pinned source thread is unpinned before merge
     */
    public function testThreadMergeUnpin()
    {
        $posting = $this->Table->get(4);
        $this->assertTrue($posting->isLocked());

        $success = $this->Table->threadMerge(4, 2);
        $this->assertTrue($success);

        $posting = $this->Table->get(4);
        $this->assertFalse($posting->isRoot());
        $this->assertEquals(1, $posting->get('tid'));
    }

    /**
     * Merge subposting 5 in thread 2 onto root-posting in thread 1
     */
    public function testThreadMergeSourceIsNoThreadRoot()
    {
        $result = $this->Table->threadMerge(5, 1);
        $this->assertFalse($result);
    }

    public function testThreadMergeThreadOntoItself()
    {
        $result = $this->Table->threadMerge(2, 1);
        $this->assertFalse($result);
    }

    /**
     * Merging onto a reply must not drag the target thread's last answer
     * backwards.
     *
     * Only a thread's root carries a current `last_answer` — afterSave() bumps
     * the root alone, so every reply keeps whatever it held when it was written.
     * Comparing the source against the *reply* therefore compared against a
     * stale date, and any source newer than that reply overwrote the root even
     * when the root was newer still. The thread then sank down a front page
     * sorted by exactly that column although it had just been answered.
     *
     * Fixture: root 1 last answered 2000-01-04, its reply 2 stale at
     * 2000-01-01 20:01. The source is put between the two.
     *
     * @return void
     */
    public function testThreadMergeDoesNotAgeTheTargetThread()
    {
        $source = $this->Table->get(6);
        $this->Table->save($this->Table->patchEntity(
            $source,
            ['last_answer' => '2000-01-02 12:00:00']
        ));
        $before = $this->Table->get(1)->get('last_answer');

        $this->assertTrue($this->Table->threadMerge(6, 2));

        $this->assertEquals(
            $before,
            $this->Table->get(1)->get('last_answer'),
            'the target root kept its own, newer last answer'
        );
    }

    /**
     * The other half: a source that really is newer than the target *root* does
     * move it forward.
     *
     * @return void
     */
    public function testThreadMergeCarriesOverANewerLastAnswer()
    {
        $source = $this->Table->get(6);
        $this->Table->save($this->Table->patchEntity(
            $source,
            ['last_answer' => '2000-01-09 12:00:00']
        ));

        $this->assertTrue($this->Table->threadMerge(6, 2));

        $this->assertEquals(
            '2000-01-09 12:00:00',
            $this->Table->get(1)->get('last_answer')->format('Y-m-d H:i:s')
        );
    }

    /**
     * A failure part-way through leaves nothing behind.
     *
     * The merge is five dependent writes. Before they were wrapped in a
     * transaction, an exception after the first one left the source root
     * re-parented into the target thread while its whole subtree still carried
     * the old `tid` — and it could not be retried, because isRoot() is false by
     * then and threadMerge() refuses at its first check.
     *
     * The listener throws while the target root's last answer is being written,
     * which is the fourth write; by then the re-parenting and the subtree's tid
     * update have already happened.
     *
     * @return void
     */
    public function testThreadMergeLeavesNothingBehindWhenItFailsMidway()
    {
        $source = $this->Table->get(6);
        $this->Table->save($this->Table->patchEntity(
            $source,
            ['last_answer' => '2000-01-09 12:00:00']
        ));

        $this->Table->getEventManager()->on(
            'Model.afterSave',
            function ($event, $entity): void {
                if ((int)$entity->get('id') === 1) {
                    throw new \RuntimeException('failure part-way through the merge');
                }
            }
        );

        try {
            $this->Table->threadMerge(6, 2);
            $this->fail('the listener should have aborted the merge');
        } catch (\RuntimeException $e) {
            $this->assertSame('failure part-way through the merge', $e->getMessage());
        }

        $source = $this->Table->get(6);
        $this->assertTrue($source->isRoot(), 'the source root was not re-parented');
        $this->assertEquals(6, (int)$source->get('tid'), 'the source thread still exists');
        $this->assertEquals(
            0,
            $this->Table->find()->where(['tid' => 6, 'pid' => 2])->count(),
            'nothing was appended to the target'
        );
    }

    public function testChangeThreadCategoryNotAnExistingCategory()
    {
        $newCategory = 9999;

        $posting = $this->Table->get(1, ['return' => 'Entity']);
        $this->Table->patchEntity($posting, ['category_id' => $newCategory]);
        $result = $this->Table->save($posting);
        $this->assertFalse($result);
    }
}
