<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Webhooks\Listener;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\I18n\DateTime;
use Saito\User\ForumsUserInterface;
use Webhooks\Lib\UserEventPayload;
use Webhooks\Lib\WebhookDispatcher;

/**
 * Turns the three user lifecycle events into outbound webhooks.
 *
 * **This exists so nobody has to patch `UsersTable` again.** The pattern it
 * replaces — copying a core table class and adding a call after the save — works
 * exactly until the next upgrade replaces that file, at which point the feature
 * disappears with no error anywhere. Saito already announces all three moments;
 * listening is what the announcement is for.
 *
 * `register` and `activate` are separate on purpose and an installation
 * subscribes to either. They mean different things: a registration is somebody
 * filling in a form, an activation is that person proving they own the address.
 * A moderation app usually wants the second; an app that watches for abuse
 * wants the first.
 */
class UserEventListener implements EventListenerInterface
{
    /**
     * @param \Webhooks\Lib\WebhookDispatcher|null $dispatcher injectable for tests
     */
    public function __construct(private ?WebhookDispatcher $dispatcher = null)
    {
    }

    /**
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        return [
            'saito.core.user.register.after' => 'onRegister',
            'saito.core.user.activate.after' => 'onActivate',
            // Deletion has no Saito event, so this is CakePHP's own — filtered
            // below, because it fires for every table in the application.
            'Model.afterDelete' => 'onDelete',
        ];
    }

    /**
     * @param \Cake\Event\EventInterface $event the register event
     * @return void
     */
    public function onRegister(EventInterface $event): void
    {
        $this->dispatch('register', $event->getData('subject'));
    }

    /**
     * @param \Cake\Event\EventInterface $event the activate event
     * @return void
     */
    public function onActivate(EventInterface $event): void
    {
        $this->dispatch('activate', $event->getData('subject'));
    }

    /**
     * @param \Cake\Event\EventInterface $event CakePHP's model event
     * @return void
     */
    public function onDelete(EventInterface $event): void
    {
        $table = $event->getSubject();
        // `Model.afterDelete` is global: postings, bookmarks, drafts and
        // everything else pass through here too. Only the users table is ours.
        if (!method_exists($table, 'getAlias') || $table->getAlias() !== 'Users') {
            return;
        }

        $this->dispatch('delete', $event->getData('entity'));
    }

    /**
     * @param string $name event name
     * @param mixed $user the entity the event carried
     * @return void
     */
    private function dispatch(string $name, mixed $user): void
    {
        if (!$user instanceof ForumsUserInterface) {
            return;
        }

        $dispatcher = $this->dispatcher ?? new WebhookDispatcher();
        $dispatcher->send(UserEventPayload::fromUser(
            $name,
            $user,
            // UTC and ISO-8601, because the receiver is a different machine in
            // a different place and the forum's display timezone is none of
            // its business.
            DateTime::now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
        ));
    }
}
