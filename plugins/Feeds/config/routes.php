<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

use Cake\Routing\Router;

$routes->plugin(
    'Feeds',
    function ($routes) {
        $routes->setExtensions(['rss']);
        // Only the two real feeds are routed. Without a catch-all fallback,
        // anything else under /feeds/ — e.g. a reader probing new.rss/feed or
        // new.rss/rss for autodiscovery — returns a clean 404 instead of
        // falling through to an auth-gated route that 302-redirects to /login
        // (which readers misparse as the feed).
        $routes->connect('/postings/new', ['controller' => 'Postings', 'action' => 'new']);
        $routes->connect('/postings/threads', ['controller' => 'Postings', 'action' => 'threads']);

        // Personalized feeds: the signed token in the path lets a logged-in
        // user's RSS reader authenticate (see FeedTokenAuthenticator), so the
        // feed shows their non-public categories. The token is consumed by the
        // authenticator middleware; the action ignores it (not passed).
        $tokenPattern = ['token' => '\d+-[0-9a-f]+'];
        $routes->connect(
            '/f/{token}/postings/new',
            ['controller' => 'Postings', 'action' => 'new']
        )->setPatterns($tokenPattern);
        $routes->connect(
            '/f/{token}/postings/threads',
            ['controller' => 'Postings', 'action' => 'threads']
        )->setPatterns($tokenPattern);
    }
);
