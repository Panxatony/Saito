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
 * that identifies a person outside this forum; it never leaves. No password
 * hash, no IP, no user agent, no category or ignore lists. The username is in
 * because a moderation app that says "user 4711 registered" is useless.
 *
 * If a receiver needs more, it can ask this forum's API with its own
 * credentials. That way the request is authenticated, logged, and refusable —
 * none of which is true of a payload pushed out unasked.
 */
class UserEventPayload
{
    /**
     * @param string $event one of `register`, `activate`, `delete`
     * @param int $userId the member's id
     * @param string $username the member's display name
     * @param string $occurredAt ISO-8601 timestamp, UTC
     */
    public function __construct(
        private readonly string $event,
        private readonly int $userId,
        private readonly string $username,
        private readonly string $occurredAt,
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
        );
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
            ],
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
