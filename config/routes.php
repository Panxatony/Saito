<?php
/**
 * Routes configuration
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\RouteBuilder;
use Cake\Routing\Route\DashedRoute;

/** @var \Cake\Routing\RouteBuilder $routes — injected by Application::routes() */

/**
 * The default class to use for all routes
 *
 * The following route classes are supplied with CakePHP and are appropriate
 * to set as the default:
 *
 * - Route
 * - InflectedRoute
 * - DashedRoute
 *
 * If no call is made to `Router::defaultRouteClass()`, the class used is
 * `Route` (`Cake\Routing\Route\Route`)
 *
 * Note that `Route` does not do any inflections on URLs which will result in
 * inconsistently cased URLs when used with `:plugin`, `:controller` and
 * `:action` markers.
 *
 * Cache: Routes are cached to improve performance, check the RoutingMiddleware
 * constructor in your `src/Application.php` file to change this behavior.
 *
 */
$routes->setRouteClass(DashedRoute::class);

$routes->scope('/', function (RouteBuilder $routes) {
    /**
     * Root URL. There is one frontend now, so there is nothing to branch on —
     * `Entries::index`, the Backbone/Marionette shell this used to fall back to,
     * no longer exists.
     */
    $routes->connect('/', ['controller' => 'Entries', 'action' => 'htmxIndex']);

    /**
     * ...and connect the rest of 'Pages' controller's URLs.
     */
    $routes->connect('/pages/*', ['controller' => 'Pages', 'action' => 'display']);

    /**
     * /users/login -> /login
     */
    $routes->connect(
        '/login',
        ['controller' => 'Users', 'action' => 'login'],
        ['_name' => 'login']
        );

    /**
     * /users/login -> /login
     */
    $routes->connect(
        '/logout',
        ['controller' => 'Users', 'action' => 'logout'],
        ['_name' => 'logout']
        );

    /**
     * Published URLs of the retired Backbone/Marionette frontend.
     *
     * These are not kept for the SPA's sake — the SPA is gone. They are kept
     * because they were *published*: two decades of search-engine entries,
     * bookmarks and links from other sites point at them. A forum that breaks
     * its own archive discards the part of itself that other people built.
     *
     * 301 (RedirectRoute's default), so clients and crawlers learn the new
     * address instead of asking forever; `persist` carries the trailing ID
     * through to the island action. Registered above `fallbacks()` on purpose —
     * the catch-all below would otherwise match `/entries/view/123` first and
     * answer with a missing-action error.
     *
     * Only addresses worth linking to are listed: a posting, a thread, a
     * profile, the two indexes and the registration form. Form and moderation
     * endpoints (edit, merge, avatar, role, lock, …) were reachable from the
     * old interface only and nobody links to them from outside.
     */
    $routes->redirect(
        '/entries/view/*',
        ['controller' => 'Entries', 'action' => 'htmxPosting'],
        ['persist' => true]
    );
    $routes->redirect(
        '/entries/mix/*',
        ['controller' => 'Entries', 'action' => 'htmxThread'],
        ['persist' => true]
    );
    $routes->redirect('/entries/index', ['controller' => 'Entries', 'action' => 'htmxIndex']);
    $routes->redirect(
        '/users/view/*',
        ['controller' => 'Users', 'action' => 'htmxProfile'],
        ['persist' => true]
    );
    $routes->redirect('/users/index', ['controller' => 'Users', 'action' => 'htmxUsers']);
    $routes->redirect('/users/register', ['controller' => 'Users', 'action' => 'htmxRegister']);

    /**
     * Connect catchall routes for all controllers.
     *
     * Using the argument `DashedRoute`, the `fallbacks` method is a shortcut for
     *    `$routes->connect('/:controller', ['action' => 'index'], ['routeClass' => 'DashedRoute']);`
     *    `$routes->connect('/:controller/:action/*', [], ['routeClass' => 'DashedRoute']);`
     *
     * Any route class can be used with this method, such as:
     * - DashedRoute
     * - InflectedRoute
     * - Route
     * - Or your own route class
     *
     * You can remove these routes once you've connected the
     * routes you want in your application.
     */
    $routes->fallbacks(DashedRoute::class);
});
