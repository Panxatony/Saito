<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Middleware;

use App\Model\Table\SettingsTable;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Installer\Lib\InstallerState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads Settings from DB into Configure.
 *
 * PSR-15 middleware (Cake 4 dropped the double-pass __invoke style).
 */
class SaitoBootstrapMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        //// start installer
        $url = $request->getUri()->getPath();
        if (!Configure::read('Saito.installed')) {
            if (strpos($url, '.')) {
                // Don't serve anything except existing assets and installer routes.
                // Automatic browser favicon.ico request messes-up installer state.
                return new Response(['status' => 503]);
            }
            // Cake 4 reads routing fields from the 'params' attribute, not
            // from individual attributes.
            $params = (array)$request->getAttribute('params', []);
            $params['plugin'] = 'Installer';
            $params['controller'] = 'Install';
            $request = $request->withAttribute('params', $params);

            return $handler->handle($request);
        } elseif (strpos($url, 'install/finished')) {
            //// User has has removed installer token. Installer no longer available.
            InstallerState::reset();

            return (new Response())->withLocation(Router::url('/'));
        }

        //// load settings
        $tableLocator = TableRegistry::getTableLocator();
        /** @var SettingsTable $settingsTable */
        $settingsTable = $tableLocator->get('Settings');
        $settingsTable->load(Configure::read('Saito.Settings'));

        //// start updater
        $updated = Configure::read('Saito.updated');
        if (!$updated) {
            $dbVersion = Configure::read('Saito.Settings.db_version');
            $saitoVersion = Configure::read('Saito.v');
            if ($dbVersion !== $saitoVersion) {
                $params = (array)$request->getAttribute('params', []);
                $params['plugin'] = 'Installer';
                $params['controller'] = 'Updater';
                $params['action'] = 'start';
                $request = $request->withAttribute('params', $params);
            }
        }

        return $handler->handle($request);
    }
}
