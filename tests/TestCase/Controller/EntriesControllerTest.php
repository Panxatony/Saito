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
    /**
     * After a reply the island reloads the thread in place. Inside a thread box
     * on the front page that has to come back as the tree of subject lines the
     * reader had — the default fragment returns every posting in full, so
     * answering silently unfolded the whole thread.
     *
     * @return void
     */
    /**
     * The front page filter takes several categories at once, as the retired
     * chooser allowed. The query layer has always accepted a list; only the
     * controller read a single value.
     *
     * @return void
     */
    public function testCategoryFilterAcceptsSeveralCategories()
    {
        $this->_loginUser(1);
        $this->get('/entries/htmx-index?category=2,4');

        $this->assertResponseOk();
        $this->assertSame([2, 4], $this->viewVariable('activeCategories'));
    }

    /**
     * A single category still works — the common case must not regress.
     *
     * @return void
     */
    public function testCategoryFilterStillAcceptsOne()
    {
        $this->_loginUser(1);
        $this->get('/entries/htmx-index?category=2');

        $this->assertResponseOk();
        $this->assertSame([2], $this->viewVariable('activeCategories'));
    }

    /**
     * `all` and an absent parameter both mean "no filter".
     *
     * @return void
     */
    public function testCategoryFilterAllMeansUnfiltered()
    {
        $this->_loginUser(1);

        $this->get('/entries/htmx-index?category=all');
        $this->assertSame([], $this->viewVariable('activeCategories'));

        $this->get('/entries/htmx-index');
        $this->assertSame([], $this->viewVariable('activeCategories'));
    }

    /**
     * Garbage in the parameter must not reach the query. Anything that is not a
     * plain number drops out, and a parameter made only of junk falls back to
     * showing everything rather than showing nothing.
     *
     * @return void
     */
    public function testCategoryFilterIgnoresJunk()
    {
        $this->_loginUser(1);

        $this->get('/entries/htmx-index?category=2,%27;DROP,4');
        $this->assertResponseOk();
        $this->assertSame([2, 4], $this->viewVariable('activeCategories'));

        $this->get('/entries/htmx-index?category=nonsense');
        $this->assertResponseOk();
        $this->assertSame([], $this->viewVariable('activeCategories'));
    }

    /**
     * A category the member may not read must not become visible by asking for
     * it in the URL. paginate() intersects the requested list with the readable
     * set — this pins that the filter can only ever narrow, never widen.
     *
     * Category 1 ("Admin") has accession 2 in the fixture, so a plain member
     * cannot read it; category 4 ("Offtopic") is accession 1 and *is* readable,
     * which is what makes the pair worth testing together.
     *
     * @return void
     */
    public function testCategoryFilterCannotWidenAccess()
    {
        $this->_loginUser(3);
        $this->get('/entries/htmx-index?category=1');

        $this->assertResponseOk();
        $this->assertResponseNotContains('Third Thread First_Subject');
    }

    /**
     * And the admin, for whom the same request is legitimate, does see it —
     * otherwise the test above would pass even if the filter were broken.
     *
     * @return void
     */
    public function testCategoryFilterShowsRestrictedCategoryToAdmin()
    {
        $this->_loginUser(1);
        $this->get('/entries/htmx-index?category=1');

        $this->assertResponseOk();
        $this->assertResponseContains('Third Thread First_Subject');
    }

    public function testHtmxThreadTreeFragmentReturnsSubjectLinesOnly()
    {
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->get('/entries/htmx-thread/1?view=tree');

        $this->assertResponseOk();
        // Measured discriminator: the tree is subject links (6 of them for this
        // thread), the full fragment has none because every posting is open.
        $this->assertResponseContains('link_show_thread');
    }

    /**
     * Without the parameter the same route keeps returning the full thread, so
     * the mix button is unaffected.
     *
     * @return void
     */
    public function testHtmxThreadWithoutViewParamStillReturnsFullPostings()
    {
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->get('/entries/htmx-thread/1');

        $this->assertResponseOk();
        $this->assertResponseNotContains(
            'link_show_thread',
            'the default fragment collapsed into subject lines'
        );
    }

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


    /**
     * The "new postings" counter is public, and that is exactly why it needs a
     * test: it reports how much has appeared since a given entry, and it must
     * count only what the visitor is allowed to read. A number is information —
     * telling a guest that six things appeared in a category they cannot open
     * would leak the existence of those postings.
     *
     * The fixture makes this measurable: category 2 is public, category 4 needs
     * an account, categories 1 and 5 are for moderators. A guest asking about
     * everything after entry 3 may therefore only be told about 7, 8, 9, 10 and
     * 13 — never about 4, 5, 11, 12, 14 or 15.
     *
     * @return void
     */
    public function testHtmxNewCountCountsOnlyWhatTheVisitorMayRead(): void
    {
        $this->get('/entries/htmx-new-count?since=3');

        $this->assertResponseOk();
        $this->assertSame(5, $this->viewVariable('newCount'), 'public category only');
    }

    /**
     * The counterpart: a member sees their categories counted too. Without this
     * the test above would also pass if the counter always returned the same
     * small number, or nothing at all.
     *
     * @return void
     */
    public function testHtmxNewCountIncludesMemberCategoriesForMembers(): void
    {
        $this->_loginUser(3);
        $this->get('/entries/htmx-new-count?since=3');

        $this->assertResponseOk();
        // 5 from the public category plus 4 from the members-only one; still
        // nothing from the two that need moderator rights.
        $this->assertSame(9, $this->viewVariable('newCount'), 'public plus members-only');
    }

    /**
     * Without a reference point there is nothing to count, and the action must
     * not fall back to "everything".
     *
     * @return void
     */
    public function testHtmxNewCountWithoutSinceCountsNothing(): void
    {
        $this->get('/entries/htmx-new-count');

        $this->assertResponseOk();
        $this->assertSame(0, $this->viewVariable('newCount'));
    }

    /**
     * The widget rail is public: a guest gets who is online and what was written
     * recently, but no "your postings" — there is no "your" to speak of.
     *
     * @return void
     */
    public function testHtmxWidgetsArePublicButWithoutOwnPostsForGuests(): void
    {
        $this->get('/entries/htmx-widgets');

        $this->assertResponseOk();
        $this->assertNotNull($this->viewVariable('recentEntries'), 'recent postings');
        $this->assertNull($this->viewVariable('myPosts'), 'nothing personal for a guest');
    }

    /**
     * Signed in, the third widget appears.
     *
     * @return void
     */
    public function testHtmxWidgetsIncludeOwnPostsForMembers(): void
    {
        $this->_loginUser(3);
        $this->get('/entries/htmx-widgets');

        $this->assertResponseOk();
        $this->assertNotNull($this->viewVariable('myPosts'));
    }

    /**
     * The order the widgets appear in, as rendered.
     *
     * @return list<string>
     */
    protected function renderedWidgetOrder(): array
    {
        preg_match_all('/data-widget="([a-z]+)"/', (string)$this->_response->getBody(), $matches);

        return $matches[1];
    }

    /**
     * Give member 3 a stored rail arrangement.
     *
     * @param list<string> $order the order to store
     * @return void
     */
    protected function storeWidgetOrderForUser3(array $order): void
    {
        $users = TableRegistry::getTableLocator()->get('Users');
        $user = $users->get(3);
        $users->patchEntity($user, [
            'slidetab_order' => \Saito\User\WidgetPreferences::write($order, [], EntriesController::WIDGETS),
        ]);
        $users->saveOrFail($user);
    }

    /**
     * The rail is rendered in the member's order rather than reshuffled by a
     * script afterwards — otherwise it visibly rearranges itself on every load,
     * and on every one of the sidebar's 60-second refreshes.
     *
     * @return void
     */
    public function testHtmxWidgetsRenderInTheMembersOrder(): void
    {
        $this->storeWidgetOrderForUser3(['mine', 'recent', 'online']);
        $this->_loginUser(3);
        $this->get('/entries/htmx-widgets');

        $this->assertResponseOk();
        $this->assertSame(['mine', 'recent', 'online'], $this->renderedWidgetOrder());
    }

    /**
     * A member who never dragged anything gets the catalogue order. Worth
     * pinning: the rendering loop is driven by stored data, and "nothing
     * stored" is the state almost every member is in.
     *
     * @return void
     */
    public function testHtmxWidgetsFallBackToTheCatalogueOrder(): void
    {
        $this->_loginUser(3);
        $this->get('/entries/htmx-widgets');

        $this->assertResponseOk();
        $this->assertSame(EntriesController::WIDGETS, $this->renderedWidgetOrder());
    }

    /**
     * A stored order naming a widget the viewer does not get — a guest has no
     * "your postings" — must not leave a gap or drop the others.
     *
     * @return void
     */
    public function testHtmxWidgetsSkipAnOrderedWidgetTheViewerCannotSee(): void
    {
        $this->get('/entries/htmx-widgets');

        $this->assertResponseOk();
        $this->assertSame(['online', 'recent'], $this->renderedWidgetOrder());
    }

    /**
     * "Mark all read" from the island answers with an empty 204 and a trigger the
     * thread list listens for. Returning a redirect instead — which is what the
     * classic path does — would make htmx replace the list with a whole page.
     *
     * @return void
     */
    public function testUpdateAnswersHtmxWithAnEmptyResponseAndATrigger(): void
    {
        $this->_loginUser(3);
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->get('/entries/update');

        $this->assertResponseCode(204);
        $this->assertHeader('HX-Trigger', 'refresh-recent');
        $this->assertResponseEmpty();
    }

    /**
     * Without htmx the same action redirects, so the no-JavaScript path still
     * lands somewhere sensible.
     *
     * @return void
     */
    public function testUpdateRedirectsWithoutHtmx(): void
    {
        $this->_loginUser(3);
        $this->get('/entries/update');

        $this->assertRedirect();
    }
}
