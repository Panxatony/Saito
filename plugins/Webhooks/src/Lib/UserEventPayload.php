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

use App\Model\Behavior\IpLoggingBehavior;
use Cake\Core\Configure;
use Cake\Log\Log;
use Saito\User\ForumsUserInterface;

/**
 * What a user event tells the outside world about a member.
 *
 * **Deliberately three fields.** A webhook is a transfer of personal data to a
 * system the forum does not control, and the receiving end is usually a phone
 * app whose storage nobody here audits. So it carries what an operator needs to
 * act — who, what happened, when — and nothing that would be a loss if the
 * endpoint were ever misconfigured or intercepted.
 *
 * **Not included, and each for a reason.** The email address is the one field
 * that identifies a person outside this forum. No password hash, no IP, no user
 * agent, no category or ignore lists. The username is in because a moderation
 * app that says "user 4711 registered" is useless.
 *
 * If a receiver needs more, it can ask this forum's API with its own
 * credentials. That way the request is authenticated, logged, and refusable —
 * none of which is true of a payload pushed out unasked.
 *
 * **One exception, and it is on its way out.** `legacyContactFields` puts the
 * email address and the IP back in. It is off by default, deprecated on arrival,
 * and will be removed — it exists so a forum that already runs a receiver
 * expecting those fields can upgrade Saito now and rewrite the receiver
 * afterwards, instead of having to do both in one weekend. See
 * `legacyContactFields()` below for what it does and what it still respects.
 */
class UserEventPayload
{
    /**
     * @param string $event one of `register`, `activate`, `delete`
     * @param int $userId the member's id
     * @param string $username the member's display name
     * @param string $occurredAt ISO-8601 timestamp, UTC
     * @param array<string, string> $contact deprecated extra fields, see below
     */
    public function __construct(
        private readonly string $event,
        private readonly int $userId,
        private readonly string $username,
        private readonly string $occurredAt,
        private readonly array $contact = [],
    ) {
    }

    /**
     * Build from a user entity.
     *
     * @param string $event event name
     * @param \Saito\User\ForumsUserInterface $user the member the event is about
     * @param string $occurredAt ISO-8601 timestamp, UTC
     * @return self
     */
    public static function fromUser(
        string $event,
        ForumsUserInterface $user,
        string $occurredAt,
    ): self {
        return new self(
            $event,
            (int)$user->getId(),
            (string)$user->get('username'),
            $occurredAt,
            self::legacyContactFields($user),
        );
    }

    /**
     * The email address and IP, for receivers that have not been rewritten yet.
     *
     * @deprecated 8.3.15 Switched off unless an installation sets
     *   `Saito.webhooks.user.legacyContactFields`, and **due for removal**. It
     *   exists so a forum with an existing receiver can upgrade Saito today and
     *   rewrite that receiver afterwards, rather than having to do both at once.
     *   Plan on it going away: fetch what you need from the API instead, where
     *   the request is authenticated, logged and refusable.
     *
     * The IP follows the forum's own `store_ip` and `store_ip_anonymized`
     * settings rather than ignoring them. A webhook hands the address to a
     * system outside this forum, which is more exposing than writing it to a
     * column here — so an installation that has decided not to keep IPs does not
     * suddenly start posting them, and one that keeps them anonymised sends them
     * anonymised. Saito goes as far as anonymising IPs in its own error logs;
     * this must not be the hole in that.
     * @param \Saito\User\ForumsUserInterface $user the member
     * @return array<string, string>
     */
    private static function legacyContactFields(ForumsUserInterface $user): array
    {
        if (!Configure::read('Saito.webhooks.user.legacyContactFields')) {
            return [];
        }

        Log::warning(
            'Webhooks: `legacyContactFields` is enabled. The email address and '
            . 'IP are being sent to an external system. This setting is '
            . 'deprecated and will be removed; move the receiver to the API.',
        );

        $fields = ['email' => (string)$user->get('user_email')];

        if (Configure::read('Saito.Settings.store_ip')) {
            $ip = (string)env('REMOTE_ADDR');
            if ($ip !== '') {
                $fields['ip'] = Configure::read('Saito.Settings.store_ip_anonymized')
                    ? IpLoggingBehavior::anonymizeIp($ip)
                    : $ip;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'user' => [
                'id' => $this->userId,
                'username' => $this->username,
            ] + $this->contact,
            'occurredAt' => $this->occurredAt,
        ];
    }

    /**
     * The body as it goes on the wire.
     *
     * @return string
     */
    public function toJson(): string
    {
        return (string)json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return string
     */
    public function getEvent(): string
    {
        return $this->event;
    }
}
