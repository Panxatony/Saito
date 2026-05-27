<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Installer;

use Cake\Core\BasePlugin;
use Cake\Core\Plugin as CakePlugin;
use Cake\Core\PluginApplicationInterface;

class Plugin extends BasePlugin
{
    /**
     * {@inheritdoc}
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        // In Cake 5 the Application typically loads Migrations itself via
        // its command runner; only fall through if nobody else loaded it.
        if (!CakePlugin::isLoaded('Migrations')) {
            $app->addPlugin('Migrations');
        }
    }
}
