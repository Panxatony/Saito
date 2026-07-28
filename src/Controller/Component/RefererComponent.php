<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\Controller;
use Cake\Event\Event;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;

class RefererComponent extends Component
{
    /**
     * Last request values
     *
     * @var array
     */
    private $last = ['action' => 'index', 'controller' => 'entries'];

    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        // In Cake 4 `Controller::referer()` defaults to a local path with
        // `$local=true`. Use the full referer URL to decide if it's local.
        $fullReferer = $event->getSubject()->referer(null, false);
        $baseUrl = Router::url('/', true);
        if (empty($fullReferer) || strpos($fullReferer, $baseUrl) !== 0) {
            $this->last = [];

            return;
        }
        $referer = $event->getSubject()->referer(null, true);
        try {
            // GET context: most route maps require an HTTP method to match.
            $request = (new ServerRequest(['url' => $referer]))->withMethod('GET');
            $parsed = Router::getRouteCollection()->parseRequest($request);
        } catch (\Cake\Routing\Exception\MissingRouteException $e) {
            $parsed = [];
        }
        foreach (['action', 'controller'] as $type) {
            if (isset($parsed[$type])) {
                $this->last[$type] = strtolower($parsed[$type]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function beforeRender(\Cake\Event\EventInterface $event)
    {
        $controller = $event->getSubject();
        $controller->set('referer', $this->last);
    }

    /**
     * Check if referer was controller $controller
     *
     * @param string $controller Controller to check for
     * @return bool
     */
    public function wasController(string $controller): bool
    {
        return !empty($this->last['controller'])
            && strcasecmp($this->last['controller'], $controller) === 0;
    }

    /**
     * Check if referer was action $action
     *
     * @param string $action Action to check for.
     * @return bool
     */
    public function wasAction(string $action): bool
    {
        // Case-insensitive, because beforeFilter() stores the parsed action
        // lower-cased while callers naturally pass the method name. With the
        // retired frontend every action was lower-case anyway ('index', 'view')
        // so an exact comparison happened to work; every island action is
        // camelCase, and this would have silently answered false for all of
        // them.
        return !empty($this->last['action'])
            && strcasecmp($this->last['action'], $action) === 0;
    }
}
