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
        $this->assertResponseNotContains('"/feeds/postings/new.rss"');
    }
}
