<?php

namespace Saito\Test\App;

use Cake\ORM\TableRegistry;
use Saito\App\Stats;
use Saito\Test\SaitoTestCase;

class StatsTest extends SaitoTestCase
{

    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.UserOnline',
        'app.User',
    ];

    /**
     * Test display method
     *
     * @return void
     */
    public function testAppStats()
    {
        $UserOnline = TableRegistry::getTableLocator()->get('UserOnline');

        $UserOnline->setOnline(1, false);
        $UserOnline->setOnline(2, true);
        // a bot/crawler is stored with a "bot" uuid prefix (see AuthUserComponent)
        $UserOnline->setOnline('bot' . str_repeat('a', 8), false);

        $Stats = new Stats();

        $this->assertEquals(3, $Stats->getNumberOfUsersOnline());
        $this->assertEquals(11, $Stats->getNumberOfRegisteredUsers());
        $this->assertEquals(15, $Stats->getNumberOfPostings());
        $this->assertEquals(7, $Stats->getNumberOfThreads());
        $this->assertEquals(1, $Stats->getNumberOfRegisteredUsersOnline());
        // bots are counted separately and excluded from the guest count
        $this->assertEquals(1, $Stats->getNumberOfBotsOnline());
        $this->assertEquals(1, $Stats->getNumberOfAnonUsersOnline());
    }
}
