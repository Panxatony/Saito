<?php

namespace Saito\Test\User\ReadPostings;

use App\Model\Entity\Entry;
use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Saito\User\Cookie\Storage;
use Saito\User\CurrentUser\CurrentUserFactory;
use Saito\User\LastRefresh\LastRefreshDummy;
use Saito\User\ReadPostings\ReadPostingsCookie;

class ReadPostingsCookieMock extends ReadPostingsCookie
{

    /**
     * @param mixed $maxPostings
     */
    public function setMaxPostings($maxPostings)
    {
        $this->maxPostings = $maxPostings;
    }

    public function setLastRefresh($LR)
    {
        $this->LastRefresh = $LR;
    }

    public function __get($property)
    {
        if ($property === 'Cookie') {
            return $this->storage;
        }
        if (property_exists($this, $property)) {
            return $this->{$property};
        }
    }

    public function __call($method, $arguments)
    {
        if (is_callable([$this, $method])) {
            return call_user_func_array([$this, $method], $arguments);
        }
    }
}

class ReadPostingsCookieTest extends \Saito\Test\SaitoTestCase
{
    public array $fixtures = [
        'app.User',
    ];

    /** @var ReadPostingsCookieMock */
    public $ReadPostings;

    /** @var \PHPUnit\Framework\MockObject\MockObject cookie mock */
    private $cookieMock;

    /** @var \PHPUnit\Framework\MockObject\MockObject lastRefresh mock */
    private $lastRefreshMock;

    public function testAbstractIsReadNoTimestamp()
    {
        $this->mock();
        $this->cookieMock->expects($this->once())
            ->method('read')
            ->will($this->returnValue('1.6'));

        $this->lastRefreshMock->expects($this->never())
            ->method('isNewerThan');

        $this->assertTrue($this->ReadPostings->isRead(1));
        $this->assertTrue($this->ReadPostings->isRead(6));
        $this->assertFalse($this->ReadPostings->isRead(2));
    }

    public function testAbstractIsReadWithTimestamp()
    {
        $this->mock();
        $this->cookieMock->expects($this->once())
            ->method('read')
            ->will($this->returnValue('1'));

        $time = time();

        $callCount = 0;
        $expectedArgs = [$time, $time + 1, $time + 2, $time + 3];
        $returnValues = [null, null, true, false];
        $this->lastRefreshMock->expects($this->exactly(4))
            ->method('isNewerThan')
            ->willReturnCallback(function ($arg) use (&$callCount, $expectedArgs, $returnValues) {
                $this->assertSame($expectedArgs[$callCount], $arg);
                return $returnValues[$callCount++];
            });

        $this->assertTrue($this->ReadPostings->isRead(1, $time));
        $this->assertFalse($this->ReadPostings->isRead(2, $time + 1));
        $this->assertTrue($this->ReadPostings->isRead(3, $time + 2));
        $this->assertFalse($this->ReadPostings->isRead(4, $time + 3));
    }

    public function testDelete()
    {
        $this->mock();
        $this->cookieMock->expects($this->once())
            ->method('delete');

        $this->ReadPostings->delete();
    }

    public function testGet()
    {
        $this->mock();
        $this->cookieMock->expects($this->once())
            ->method('read')
            ->will($this->returnValue('1.6'));

        $this->ReadPostings->isRead(1);

        //# test class cache is set
        $expected = [1 => 1, 6 => 1];
        $actual = $this->ReadPostings->readPostings;
        $this->assertEquals($expected, $actual);

        // test caching: should not read cookie a second time
        $this->ReadPostings->isRead(6);
    }

    public function testSet()
    {
        $this->mock(['_gc', '_get']);
        $this->ReadPostings->expects($this->once())
            ->method('_gc');
        $this->ReadPostings->expects($this->once())
            ->method('_get')
            ->will($this->returnValue([1 => 1, 2 => 1]));

        $time = time();
        $callCount = 0;
        $expectedArgs = [$time, $time + 1, $time + 2];
        $returnValues = [false, true, false];
        $this->lastRefreshMock->expects($this->exactly(3))
            ->method('isNewerThan')
            ->willReturnCallback(function ($arg) use (&$callCount, $expectedArgs, $returnValues) {
                $this->assertSame($expectedArgs[$callCount], $arg);
                return $returnValues[$callCount++];
            });

        /*
         * 1: already stored, will be stored again but not twice
         * 2: already stored, will be stored again
         * 3: not stored, older than last refresh
         * 4: newly stored
         */
        $this->cookieMock->expects($this->once())
            ->method('write')
            ->with('1.2.4');
        $this->ReadPostings->set(
            [
                new Entry(['id' => 1, 'time' => $time]),
                new Entry(['id' => 3, 'time' => $time + 1]),
                new Entry(['id' => 4, 'time' => $time + 2]),
            ]
        );

        // test that class cache is updated
        $expected = [1 => 1, 2 => 1, 4 => 1];
        $actual = $this->ReadPostings->readPostings;
        $this->assertEquals($expected, $actual);
    }

    public function testSetSingle()
    {
        $this->mock();

        $this->lastRefreshMock->expects($this->once())
            ->method('isNewerThan')
            ->will($this->returnValue(false));
        $this->cookieMock->expects($this->once())
            ->method('write')
            ->with('4');

        $this->ReadPostings->set(
            [
                new Entry(['id' => 4, 'time' => 0]),
            ]
        );
    }

    public function testGc()
    {
        $this->mock();
        $this->cookieMock->expects($this->once())
            ->method('write')
            ->with('5.6');

        $this->ReadPostings->setMaxPostings(2);
        $this->ReadPostings->set(
            [
                new Entry(['id' => 1, 'time' => 0]),
                new Entry(['id' => 5, 'time' => 1]),
                new Entry(['id' => 6, 'time' => 2]),
            ]
        );
    }

    public function mock($methods = null)
    {
        $currentUser = CurrentUserFactory::createDummy();

        $request = new ServerRequest();
        $request->getSession()->start();
        $request->getSession()->id('test');
        $response = new Response();

        $controller = new Controller($request);

        $this->cookieMock = $this->getMockBuilder(Storage::class)
            ->setConstructorArgs([$controller, 'Saito-Read'])
            ->onlyMethods(['read', 'write', 'delete'])
            ->getMock();

        if ($methods !== null) {
            $this->ReadPostings = $this->getMockBuilder(ReadPostingsCookieMock::class)
                ->setConstructorArgs([$currentUser, $this->cookieMock])
                ->onlyMethods($methods)
                ->getMock();
        } else {
            $this->ReadPostings = new ReadPostingsCookieMock($currentUser, $this->cookieMock);
        }

        $this->lastRefreshMock = $this->getMockBuilder(LastRefreshDummy::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isNewerThan'])
            ->getMock();
        $this->ReadPostings->setLastRefresh($this->lastRefreshMock);
    }

    public function tearDown(): void
    {
        $this->ReadPostings->delete();
        unset($this->ReadPostings);
        unset($this->CurrentUser);
        parent::tearDown();
    }
}
