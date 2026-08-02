<?php
declare(strict_types=1);

namespace Webhooks\Test\TestCase\Listener;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use Webhooks\Lib\UserEventPayload;
use Webhooks\Lib\WebhookDispatcher;
use Webhooks\Listener\UserEventListener;

/**
 * Does the listener actually hear the forum?
 *
 * The unit tests around `WebhookDispatcher` prove it builds and signs the right
 * request. They say nothing about whether anything ever calls it — and that is
 * exactly the failure this plugin exists to replace. macfix's copied
 * `UsersTable` stopped being loaded at some point and nobody noticed, because a
 * notification that never fires looks identical to a quiet forum.
 *
 * So these tests dispatch the real event names through the real event manager.
 * A typo in `implementedEvents()` fails here and nowhere else.
 *
 * @covers \Webhooks\Listener\UserEventListener
 */
class UserEventListenerTest extends TestCase
{
    use LocatorAwareTrait;

    protected array $fixtures = ['app.User', 'app.Entry', 'app.Category', 'app.Setting'];

    /**
     * Records what it was asked to send instead of sending it.
     *
     * @return WebhookDispatcher
     */
    private function recordingDispatcher(array &$sent): WebhookDispatcher
    {
        return new class ($sent) extends WebhookDispatcher {
            /**
             * @param array $sent by-reference sink
             */
            public function __construct(private array &$sent)
            {
                parent::__construct();
            }

            /**
             * @param \Webhooks\Lib\UserEventPayload $payload payload
             * @return bool
             */
            public function send(UserEventPayload $payload): bool
            {
                $this->sent[] = $payload->toArray();

                return true;
            }
        };
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        EventManager::instance()->off('saito.core.user.register.after');
        EventManager::instance()->off('saito.core.user.activate.after');
        parent::tearDown();
    }

    /**
     * Registering a member reaches the dispatcher, with the member in it.
     *
     * @return void
     */
    public function testRegistrationReachesTheDispatcher(): void
    {
        $sent = [];
        EventManager::instance()->on(new UserEventListener($this->recordingDispatcher($sent)));

        $users = $this->getTableLocator()->get('Users');
        $user = $users->get(3);
        $users->dispatchDbEvent('saito.core.user.register.after', [
            'subject' => $user,
            'table' => $users,
        ]);

        $this->assertCount(1, $sent, 'the register event reached the listener');
        $this->assertSame('register', $sent[0]['event']);
        $this->assertSame(3, $sent[0]['user']['id']);
        $this->assertSame($user->get('username'), $sent[0]['user']['username']);
    }

    /**
     * Activation is its own event and must not be reported as a registration.
     *
     * @return void
     */
    public function testActivationIsReportedSeparately(): void
    {
        $sent = [];
        EventManager::instance()->on(new UserEventListener($this->recordingDispatcher($sent)));

        $users = $this->getTableLocator()->get('Users');
        $users->dispatchDbEvent('saito.core.user.activate.after', [
            'subject' => $users->get(3),
            'table' => $users,
        ]);

        $this->assertCount(1, $sent);
        $this->assertSame('activate', $sent[0]['event']);
    }

    /**
     * `Model.afterDelete` fires for every table in the application. A posting
     * being removed is not a member leaving, and must not be announced as one.
     *
     * @return void
     */
    public function testIgnoresDeletesFromOtherTables(): void
    {
        $sent = [];
        $listener = new UserEventListener($this->recordingDispatcher($sent));

        $entries = $this->getTableLocator()->get('Entries');
        $event = new Event('Model.afterDelete', $entries, [
            'entity' => $entries->newEmptyEntity(),
        ]);
        $listener->onDelete($event);

        $this->assertSame([], $sent, 'a deleted posting is not a deleted member');
    }

    /**
     * The timestamp goes out in UTC, whatever the forum displays.
     *
     * @return void
     */
    public function testTimestampIsUtcIso8601(): void
    {
        $sent = [];
        EventManager::instance()->on(new UserEventListener($this->recordingDispatcher($sent)));

        $users = $this->getTableLocator()->get('Users');
        $users->dispatchDbEvent('saito.core.user.register.after', [
            'subject' => $users->get(3),
            'table' => $users,
        ]);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $sent[0]['occurredAt'],
        );
    }
}
