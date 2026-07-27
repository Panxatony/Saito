<?php

namespace App\Test\TestCase\Controller;

use App\Controller\EntriesController;
use App\Model\Entity\Entry;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Database\Schema\Table;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\BadRequestException;
use Cake\Controller\Exception\InvalidParameterException;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;

class EntriesMockController extends EntriesController
{

    // @codingStandardsIgnoreStart
    public $uses = ['Entries'];

    // @codingStandardsIgnoreEnd

    public function getInitialThreads(
        $User,
        $order = ['Entry.last_answer' => 'DESC']
    ) {
        $this->_getInitialThreads($User, $order);
    }
}

/**
 * Class EntriesControllerTestCase
 *
 * @package App\Test\TestCase\Controller
 */
class EntriesControllerTest extends IntegrationTestCase
{

    /**
     * @var table for the controller
     */
    public $Table;

    public array $fixtures = [
        'plugin.Bookmarks.Bookmark',
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
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->Table = TableRegistry::getTableLocator()->get('Entries');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        unset($this->Table);
    }

    /**
     * The island's single-posting page: posting plus the tree of its thread.
     *
     * @return void
     */
    public function testHtmxPostingShowsPostingAndThread(): void
    {
        $this->get('/entries/htmx-posting/1');

        $this->assertResponseOk();
        $this->assertNotEmpty($this->viewVariable('entry'), 'the posting itself');
        $this->assertNotEmpty($this->viewVariable('tree'), 'the thread it belongs to');
    }

    /**
     * An HX-Request gets the bare posting fragment — that is what the island
     * fetches to open a posting inline. Without disabling the layout the whole
     * page would be swapped into the thread line.
     *
     * @return void
     */
    public function testHtmxPostingReturnsFragmentForHtmx(): void
    {
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->get('/entries/htmx-posting/1');

        $this->assertResponseOk();
        $this->assertResponseNotContains('<html');
        // The tree is only needed by the full page.
        $this->assertNull($this->viewVariable('tree'));
    }

    /**
     * Regression guard. The answering panel is switched on by action name, and
     * the list knew only view/mix — so when the inline open moved to this
     * action, the reply button silently disappeared from every expanded
     * posting. It took a user to notice.
     *
     * @return void
     */
    public function testHtmxPostingOffersReplyToMembers(): void
    {
        $this->_loginUser(1);
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->get('/entries/htmx-posting/1');

        $this->assertResponseOk();
        $this->assertTrue(
            $this->viewVariable('showAnsweringPanel'),
            'a logged-in member must be offered the reply button'
        );
    }

    /**
     * The same guard for the thread view, which shares the rule.
     *
     * @return void
     */
    public function testHtmxThreadOffersReplyToMembers(): void
    {
        $this->_loginUser(1);
        $this->get('/entries/htmx-thread/1');

        $this->assertResponseOk();
        $this->assertTrue($this->viewVariable('showAnsweringPanel'));
    }

    /**
     * Guests get no reply button — they cannot post.
     *
     * @return void
     */
    public function testHtmxPostingHidesReplyFromGuests(): void
    {
        $this->get('/entries/htmx-posting/1');

        $this->assertResponseOk();
        $this->assertFalse($this->viewVariable('showAnsweringPanel'));
    }

    /**
     * view_posting reads the thread's root to decide who may mark an answer as
     * helpful. Without it the button vanishes — the same class of bug as the
     * reply button above.
     *
     * @return void
     */
    public function testHtmxPostingProvidesRootEntry(): void
    {
        $this->get('/entries/htmx-posting/1');

        $this->assertResponseOk();
        $this->assertNotEmpty($this->viewVariable('rootEntry'));
    }

    /**
     * A category the reader may not read stays closed, exactly as on the SPA
     * route it replaces.
     *
     * @return void
     */
    public function testHtmxPostingNoAuthorization(): void
    {
        $url = '/entries/htmx-posting/4';
        $this->get($url);

        $this->assertRedirectLogin($url);
    }

    /**
     * A nonsensical id must not reach the database layer.
     *
     * @return void
     */
    public function testHtmxPostingRejectsBadId(): void
    {
        // Wie die uebrigen Tests dieser Datei: die Ausnahme wird durchgereicht,
        // nicht als Antwort gerendert.
        $this->expectException(BadRequestException::class);
        $this->get('/entries/htmx-posting/0');
    }

    /**
     * A posting that does not exist behaves like the SPA route it replaces.
     *
     * @return void
     */
    public function testHtmxPostingNotFound(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->get('/entries/htmx-posting/9999');
    }

    /**
     * Regression: pinning/locking is authorized by saito.core.posting.pinAndLock,
     * so a moderator must be able to toggle a thread even when they may not
     * *edit* it. Make root entry 10 the moderator's own, past the edit window and
     * unpinned -> isEditingAllowed() is false. ajaxToggle used to route through
     * PostingComponent::update() (which requires edit permission) and threw a
     * SaitoForbiddenException here.
     */
    public function testAjaxToggleFixedAllowedForModerator()
    {
        $this->Table->updateAll(
            ['user_id' => 2, 'time' => '2015-01-01 00:00:00', 'fixed' => 0],
            ['id' => 10]
        );

        // Mitch (role mod): has pinAndLock but not edit.unrestricted.
        $this->_loginUser(2);
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $this->get('/entries/ajaxToggle/10/fixed');

        $this->assertResponseOk();
        $this->assertResponseContains('OK');
        $this->assertTrue((bool)$this->Table->get(10)->get('fixed'));
    }

    /**
     * only logged in users should be able to answer
     */
    public function testAddUserNotLoggedInGet()
    {
        $url = '/entries/add';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }

    public function testDeleteNotLoggedIn()
    {
        $url = '/entries/delete/1';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }

    public function testDeleteSuccess()
    {
        $this->_loginUser(1);
        $count = $this->Table->postingsForThread(1)->getThread()->count();
        $this->assertEquals(6, $count);

        $this->mockSecurity();
        $this->post('/entries/delete/9');

        $count = $this->Table->postingsForThread(1)->getThread()->count();
        $this->assertEquals(4, $count);
    }

    public function testDeleteGetShowsConfirmationAndDoesNotDelete()
    {
        // Regression (CSRF): a bare GET must NOT delete — it renders a
        // CSRF-protected confirmation form; only POST/DELETE performs the
        // deletion. A lured cross-site GET therefore destroys nothing.
        $this->_loginUser(1);
        $before = $this->Table->postingsForThread(1)->getThread()->count();

        $this->get('/entries/delete/9');

        $this->assertResponseOk();
        $after = $this->Table->postingsForThread(1)->getThread()->count();
        $this->assertEquals($before, $after);
        $this->assertResponseContains('action="/entries/delete/9"');
    }

    public function testDeleteNoAuthorization()
    {
        $this->_loginUser(3);
        $this->mockSecurity();
        $this->expectException(SaitoForbiddenException::class);

        $this->post('/entries/delete/1');
    }

    public function testDeletePostingDoesntExist()
    {
        $this->_loginUser(1);
        $this->mockSecurity();
        $this->expectException(RecordNotFoundException::class);
        $this->post('/entries/delete/9999');
    }

    public function testDeletePostingFailureCategoryAccess()
    {
        $this->_loginUser(2);
        $this->mockSecurity();

        ///
        $this->post('/entries/delete/15');
        $this->assertRedirect('/entries/htmx-thread/14');

        // Category 4 new threads are not allowed for mods
        $this->expectException(SaitoForbiddenException::class);
        $this->post('/entries/delete/14');
    }

    /**
     * @param int $postingId
     */
    protected function _viewOk($postingId)
    {
        $this->get('/entries/view/' . $postingId);
        $this->assertResponseOk();
        $this->assertNoRedirect();
        $resultId = $this->viewVariable('entry')->get('id');
        $this->assertEquals($resultId, $postingId);
    }

    /**
     * don't increase view counter if user views its own posting
     */
    public function testViewIncreaseViewCounterSameUser()
    {
        $postingId = 1;

        $EntriesTable = TableRegistry::getTableLocator()->get('Entries');
        $posting = $EntriesTable->get($postingId);
        $viewsExpected = $posting->get('views');

        $this->_loginUser(3);
        $this->get('/entries/view/' . $postingId);

        $posting = $EntriesTable->get($postingId);
        $viewsResult = $posting->get('views');

        $this->assertEquals($viewsExpected, $viewsResult);
    }

    /**
     * don't increase view counter on spiders/crawlers
     */
    public function testViewIncreaseViewCounterCrawler()
    {
        $this->_setUserAgent('A Crawler Agent');
        $postingId = 1;

        $EntriesTable = TableRegistry::getTableLocator()->get('Entries');
        $posting = $EntriesTable->get($postingId);
        $viewsExpected = $posting->get('views');

        $this->_loginUser(3);
        $this->get('/entries/view/' . $postingId);

        $posting = $EntriesTable->get($postingId);
        $viewsResult = $posting->get('views');

        $this->assertEquals($viewsExpected, $viewsResult);
    }

    public function testSolveNotLoggedIn()
    {
        $url = '/entries/solve/1';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }

    public function testSolveNoEntry()
    {
        $this->_loginUser(1);
        $this->expectException(
            'Cake\Http\Exception\BadRequestException'
        );
        $this->get('/entries/solve/9999');
    }

    public function testSolveNotRootEntryDoesntBelongToCurrentUser()
    {
        $this->_loginUser(2);
        $this->expectException(
            'Cake\Http\Exception\BadRequestException'
        );
        $this->get('/entries/solve/2');
    }

    public function testSolveIsRootEntry()
    {
        $this->_loginUser(3);
        $this->expectException(
            'Cake\Http\Exception\BadRequestException'
        );
        $this->get('/entries/solve/1');
    }

    public function testSolveSaveError()
    {
        $Entries = $this->getMockForTable('Entries', ['updateEntry']);
        $this->_loginUser(3);
        $Entries->expects($this->once())
            ->method('updateEntry')
            ->willReturn(null);
        $this->expectException(
            'Cake\Http\Exception\BadRequestException'
        );
        $this->get('/entries/solve/2');
    }

    public function testSolveSuccess()
    {
        $Entries = $this->getMockForTable('Entries', ['updateEntry']);
        $this->_loginUser(3);
        $Entries->expects($this->once())
            ->method('updateEntry')
            ->willReturn(new Entry());
        $this->get('/entries/solve/2');
        $this->assertResponseOk();
        $this->assertResponseEquals('');
    }

    public function testSourceNotLoggedIn()
    {
        $this->get('/entries/source/1');
        $this->assertRedirectContains('/login');
    }

    public function testThreadLineAnon()
    {
        $this->_setJson();
        $this->get('/entries/threadLine/6');
        $this->assertRedirectContains('/login');
    }

}
