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
        $this->request = $this->createMock('Cake\Http\ServerRequest');
        $this->response = $this->createMock('Cake\Http\Response');

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
     * Test display method
     *
     * @return void
     */
    public function testDisplay()
    {
        $this->assertTrue(true);
    }
}
