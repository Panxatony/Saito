<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;

$routes->plugin(
    'ImageUploader',
    ['path' => '/api/v2'],
    function (RouteBuilder $routes) {
        $routes->get(
            '/uploads/thumb/{id}',
            ['controller' => 'Thumbnail', 'action' => 'thumb'],
            'imageUploader-thumbnail'
        )
            ->setPatterns(['id' => '[0-9]+']);

        $routes->setExtensions(['json']);
        // Only the actions the controller actually has. resources() registers
        // the full REST set, so `GET /api/v2/uploads/{id}` and `PUT/PATCH` were
        // routed at a `view`/`edit` that does not exist — those two addresses
        // answered with MissingActionException instead of a clean 404.
        $routes->resources('Uploads', ['only' => ['index', 'create', 'delete']]);
    }
);
