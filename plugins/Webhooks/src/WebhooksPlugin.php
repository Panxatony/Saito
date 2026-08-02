<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Webhooks;

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use Cake\Event\EventManager;
use Webhooks\Listener\UserEventListener;

/**
 * Outbound notifications for user lifecycle events.
 *
 * The plugin is always loaded and does nothing at all until an installation
 * configures `Saito.webhooks.user.url` — so the listener is only attached when
 * there is somewhere to send to. That keeps `Model.afterDelete`, which fires for
 * every table in the application, out of the hot path on the installations that
 * do not use this.
 */
class WebhooksPlugin extends BasePlugin
{
    /**
     * @inheritDoc
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        if (trim((string)Configure::read('Saito.webhooks.user.url')) === '') {
            return;
        }

        EventManager::instance()->on(new UserEventListener());
    }
}
