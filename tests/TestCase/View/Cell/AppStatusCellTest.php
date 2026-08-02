<?php

namespace App\Test\TestCase\View\Cell;

use App\View\Cell\AppStatusCell;
use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;
use Saito\Test\SaitoTestCase;

/**
 * App\View\Cell\AppStatusCell Test Case
 */
class AppStatusCellTest extends SaitoTestCase
{

    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.UserOnline',
        'app.User',
    ];

    protected $request;

    protected $response;

    protected $AppStatus;

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createStub('Cake\Http\ServerRequest');
        $this->response = $this->createStub('Cake\Http\Response');

        $this->AppStatus = new AppStatusCell($this->request, $this->response);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown(): void
    {
        unset($this->AppStatus);

        parent::tearDown();
    }

    /**
     * The cell hands the footer two things: the current user and the forum's
     * statistics. That is all it does, so that is what is checked.
     *
     * This test used to be `assertTrue(true)` after a full setUp — it loaded four
     * fixtures and built the cell, then asserted nothing about it. Anything could
     * have broken here and it would still have passed.
     *
     * @return void
     */
    public function testDisplayProvidesTheCurrentUserAndTheStatistics()
    {
        $CurrentUser = \Saito\User\CurrentUser\CurrentUserFactory::createDummy();

        $this->AppStatus->display($CurrentUser);

        $variablen = $this->AppStatus->viewBuilder()->getVars();
        $this->assertArrayHasKey('CurrentUser', $variablen);
        $this->assertArrayHasKey('Stats', $variablen);
        $this->assertSame($CurrentUser, $variablen['CurrentUser']);
        $this->assertNotNull($variablen['Stats'], 'the statistics the footer prints');
    }
}
