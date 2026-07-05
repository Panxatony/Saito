<?php

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\UserReadsTable;
use Saito\Test\Model\Table\SaitoTableTestCase;

class UserReadsTableTest extends SaitoTableTestCase
{

    public $tableClass = 'UserReads';

    /** @var UserReadsTable */
    public $Table;

    /**
     * Fixtures
     *
     * @var array
     */
    public array $fixtures = [
        'app.UserRead',
        'app.User',
        'app.UserOnline',
        'app.Entry',
        'app.Category',
        'plugin.Bookmarks.Bookmark',
    ];

    /**
     * tests that only new entries are stored to the DB
     */
    public function testSetEntriesForUserExistingEntry()
    {
        $userId = 1;
        $entryId = 2;

        $User = $this->getMockForModel('UserReads', ['getUser', 'save']);

        $User->expects($this->once())
            ->method('getUser')
            ->with($userId)
            ->willReturn([$entryId]);
        // The entry already exists (getUser returns it), so the dedup filter
        // short-circuits and nothing is persisted. (Was mocking a non-existent
        // `create` method, a Cake-3 relic PHPUnit now deprecates.)
        $User->expects($this->never())->method('save');

        $User->setEntriesForUser([$entryId], $userId);
    }

    public function testGarbageCollection()
    {
        $this->Table->deleteAll([]);

        $willBeDeleted = $this->Table->newEntity([
            'entry_id' => 1,
            'user_id' => 1,
            'created' => (new \DateTimeImmutable(UserReadsTable::GC))->sub(new \DateInterval('P0Y2D')),
        ]);
        $this->Table->save($willBeDeleted);
        $isGoingToStay = $this->Table->newEntity([
            'entry_id' => 2,
            'user_id' => 1,
            'created' => (new \DateTimeImmutable(UserReadsTable::GC))->add(new \DateInterval('P0Y2D')),
        ]);
        $this->Table->save($isGoingToStay);

        $this->assertEquals(2, $this->Table->find()->count());

        $this->Table->garbageCollection();

        $this->assertEquals(1, $this->Table->find()->count());
    }
}
