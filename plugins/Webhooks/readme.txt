Webhooks — outbound notifications for user lifecycle events.

Fires when somebody registers, activates their account, or deletes it, and posts
a small signed JSON body to an address the installation configures. Written for
forums that run a companion app or a moderation queue outside Saito.

Configure in config/saito_config.php:

    'webhooks' => [
        'user' => [
            'url' => 'https://example.org/hooks/saito',
            'secret' => '…',                     // shared with the receiver
            'events' => ['register', 'activate'], // omit for all three
            'timeout' => 3,                       // seconds
        ],
    ],

Leaving `url` empty switches the whole plugin off, listener included.

There is one further key, `legacyContactFields`, and it is deprecated the day it
appears. Setting it true adds the member's email address and IP to the payload.
It is here for one situation only: a forum that already runs a receiver written
against an older integration, so it can upgrade Saito today and rewrite that
receiver afterwards. **Plan on it going away.** Every send logs a warning while
it is on, and the IP still follows the forum's `store_ip` and
`store_ip_anonymized` settings — a webhook must not be the way around a decision
the forum has already made about addresses.

The body:

    {"event":"register","user":{"id":4711,"username":"jane"},
     "occurredAt":"2026-08-02T18:30:00Z"}

and, when a secret is set, a header

    X-Saito-Signature: sha256=<hmac of the exact body>

Verify it before trusting the call. The URL will end up in a log somewhere.

Two properties worth knowing before relying on this:

  - The payload carries no email address, no IP, nothing beyond id, username and
    a timestamp. A receiver needing more should ask the API with its own
    credentials, where the request is authenticated and refusable.

  - **Delivery is best-effort.** The call happens inside the request that
    registered the member, so a slow or dead endpoint must not fail their
    registration. Failures are logged and dropped. If an event must not be
    missed, poll the API instead.
