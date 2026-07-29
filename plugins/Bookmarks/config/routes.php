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
    'Bookmarks',
    ['path' => '/api/v2'],
    function ($routes) {
        $routes->setExtensions(['json']);
        // The controller has no view() — resources() would route
        // `GET /api/v2/bookmarks/{id}` at it anyway and answer with
        // MissingActionException rather than a clean 404.
        $routes->resources('Bookmarks', ['only' => ['index', 'create', 'update', 'delete']]);
    }
);
