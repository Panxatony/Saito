<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace SaitoSearch\Test\Controller;

use Cake\ORM\TableRegistry;
use DateTimeImmutable;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;

/**
 * SearchesController Test Case
 *
 */
class SearchesControllerTest extends IntegrationTestCase
{

    /** @var array Fixtures */
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserRead',
        'app.UserOnline',
    ];

    /**
     * The island search had no tests of its own: it only inherited coverage
     * through the shared runSimpleSearch()/prepareAdvancedSearch() helpers,
     * which the retired SPA endpoints exercised. Removing those endpoints would
     * have removed the only thing testing the island's search too, so these are
     * ports of the tests that went with them — same queries, same expectations,
     * pointed at the island actions.
     *
     * @return void
     */
    public function testSimpleSearchSortByRank()
    {
        $this->skipOnDataSource('Postgres');
        $this->_loginUser(1);

        $this->get('/searches/htmx-simple?searchTerm="Second_Subject"&order=rank');

        $this->assertResponseCode(200);
        $result = $this->viewVariable('results');
        $this->assertEquals(2, $result->items()->first()->get('id'));
        $this->assertEquals(5, $result->items()->skip(1)->first()->get('id'));
    }

    /**
     * The important one: results are filtered by what the reader may see. An
     * admin finds the posting in the restricted category...
     *
     * @return void
     */
    public function testSimpleSearchShowsRestrictedCategoryToAdmin()
    {
        $this->skipOnDataSource('Postgres');
        $this->_loginUser(1);

        $this->get('/searches/htmx-simple?searchTerm="Third+Thread+First_Subject"');

        $this->assertCount(1, $this->viewVariable('results'));
    }

    /**
     * ...and a plain member does not. Search is a classic way to leak content
     * past a category permission, so this is the test worth keeping.
     *
     * @return void
     */
    public function testSimpleSearchHidesRestrictedCategoryFromMember()
    {
        $this->_loginUser(3);

        $this->get('/searches/htmx-simple?searchTerm="Third+Thread+First_Subject"');

        $this->assertCount(0, $this->viewVariable('results'));
    }

    /**
     * The same permission boundary through the advanced search.
     *
     * @return void
     */
    public function testAdvancedSearchRespectsCategoryPermissions()
    {
        $url = '/searches/htmx-advanced?subject=Third+Thread+First_Subject&since=1999-01';

        $this->_loginUser(3);
        $this->get($url);
        $this->assertCount(0, $this->viewVariable('results'), 'a member saw a restricted posting');

        $this->_loginUser(1);
        $this->get($url);
        $this->assertCount(1, $this->viewVariable('results'), 'an admin did not see it');
    }

    /**
     * htmx swaps only the results fragment, so an HX-Request must not carry the
     * page shell — otherwise "load more" would nest a second copy of the whole
     * page inside the results.
     *
     * @return void
     */
    public function testHxRequestReturnsTheFragmentOnly()
    {
        $this->skipOnDataSource('Postgres');
        $this->configRequest(['headers' => ['HX-Request' => 'true']]);
        $this->get('/searches/htmx-simple?searchTerm="Second_Subject"');

        $this->assertResponseOk();
        $this->assertResponseNotContains('<html');
    }

    /**
     * An empty term renders the form rather than every posting in the forum.
     *
     * @return void
     */
    public function testSimpleSearchWithoutATermShowsNoResults()
    {
        $this->get('/searches/htmx-simple');

        $this->assertResponseOk();
        $this->assertNull($this->viewVariable('results'));
    }
}
