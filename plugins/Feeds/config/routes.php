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
    }
);
