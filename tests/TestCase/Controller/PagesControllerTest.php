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

use Cake\Core\Configure;
use Saito\Test\IntegrationTestCase;

class PagesControllerTest extends IntegrationTestCase
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
    ];

    public function testRssFeedsPageAnonymousShowsPublicLinks()
    {
        $this->get('/pages/rss_feeds');

        $this->assertResponseOk();
        // Public, un-tokenized feed URLs for a guest.
        $this->assertResponseContains('/feeds/postings/new.rss');
        $this->assertResponseNotContains('/feeds/f/');
    }

    public function testRssFeedsPageLoggedInShowsPersonalTokenizedLinks()
    {
        $this->_loginUser(3);
        $this->get('/pages/rss_feeds');

        $this->assertResponseOk();
        // The personal, signed feed URL for the logged-in user.
        $this->assertResponseContains('/feeds/f/3-');

        // …and the page itself must not offer the un-tokenized one. Scoped to
        // the page's own card: the island layout carries an RSS overlay that
        // lists the public feeds for everybody, which is layout furniture and
        // not an offer made to this reader.
        $body = (string)$this->_response->getBody();
        $card = substr($body, (int)strpos($body, 'card-body panel-content'));
        $card = substr($card, 0, (int)strpos($card, '</div>'));
        $this->assertStringNotContainsString('"/feeds/postings/new.rss"', $card);
    }

    public function testTosPageRendersTheConfiguredTerms()
    {
        Configure::write('Saito.tos', '<p>Diese Nutzungsbedingungen gelten.</p>');
        $this->get('/pages/tos');

        $this->assertResponseOk();
        $this->assertResponseContains('Diese Nutzungsbedingungen gelten.');
    }

    public function testTosPageShowsANoticeWhenNotConfigured()
    {
        Configure::write('Saito.tos', '');
        $this->get('/pages/tos');

        $this->assertResponseOk();
        $this->assertResponseContains('No terms of service have been configured');
    }
}
