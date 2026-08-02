<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Webhooks\Lib;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Log\Log;
use Throwable;

/**
 * Sends a user event to the address an installation configured, and gets out of
 * the way if anything goes wrong.
 *
 * **The forum's own work must not depend on this.** A webhook fires inside the
 * request that registered a member; if the receiving endpoint is slow, down, or
 * returns nonsense, the member must still get their account. So the timeout is
 * short, every failure is caught, and nothing here throws. The cost of that
 * choice is stated plainly: a delivery can be lost and the member will never
 * know. An endpoint that must not miss events should ask the API rather than be
 * pushed to.
 *
 * The request is signed with HMAC-SHA256 over the exact body, in
 * `X-Saito-Signature`. Without it a receiver has no way to tell the forum's call
 * from anyone else's who guessed the URL — and the URL will end up in a log
 * somewhere eventually.
 */
class WebhookDispatcher
{
    /**
     * @param \Cake\Http\Client|null $client HTTP client, injectable for tests
     */
    public function __construct(private ?Client $client = null)
    {
    }

    /**
     * Deliver one event, if the installation asked for it.
     *
     * @param \Webhooks\Lib\UserEventPayload $payload what happened
     * @return bool true when the endpoint accepted it; false when it was not
     *     configured, not subscribed, or the call failed
     */
    public function send(UserEventPayload $payload): bool
    {
        $config = (array)Configure::read('Saito.webhooks.user');

        $url = trim((string)($config['url'] ?? ''));
        if ($url === '') {
            return false;
        }

        // An installation subscribes per event. Absent means "all of them",
        // because a URL configured with no event list is far more likely to be
        // someone who wants everything than someone who wants silence.
        $events = $config['events'] ?? null;
        if (is_array($events) && !in_array($payload->getEvent(), $events, true)) {
            return false;
        }

        $body = $payload->toJson();
        $secret = (string)($config['secret'] ?? '');

        $headers = ['Content-Type' => 'application/json'];
        if ($secret !== '') {
            $headers['X-Saito-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        try {
            $client = $this->client ?? new Client([
                // Seconds. Long enough for a healthy endpoint on another
                // continent, short enough that a dead one does not hold a
                // member staring at a spinner.
                'timeout' => (int)($config['timeout'] ?? 3),
            ]);
            $response = $client->post($url, $body, ['headers' => $headers]);

            return $response->isOk();
        } catch (Throwable $e) {
            // Deliberately swallowed. See the class comment: the member's
            // registration is not allowed to fail because a phone app is
            // unreachable. Logged so an operator can find out that it is.
            Log::warning(sprintf(
                'Webhook for "%s" failed: %s',
                $payload->getEvent(),
                $e->getMessage(),
            ));

            return false;
        }
    }
}
