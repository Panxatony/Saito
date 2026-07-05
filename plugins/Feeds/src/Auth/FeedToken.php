<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\Auth;

use Cake\Utility\Security;

/**
 * Signed, per-user token for personalized RSS feed URLs.
 *
 * The token is `<userId>-<signature>`, where the signature is an HMAC over the
 * user-id keyed with the app salt *and the user's password hash*. Consequences:
 *
 *  - It is unguessable without the server salt — a reader cannot forge one.
 *  - It stores no secret of its own, so it works on every Saito install with
 *    no schema change and nothing to migrate.
 *  - Binding it to the password hash means changing the password silently
 *    invalidates the old feed URLs — free per-user revocation after a leak.
 *
 * The feed is read-only, so a leaked URL only exposes the postings the user may
 * already read; it grants no write access and does not expose the password.
 */
class FeedToken
{
    /**
     * Signature length in hex chars. 32 hex = 128 bit, ample for an
     * unguessable, read-only token while keeping the URL short.
     */
    private const SIGNATURE_LENGTH = 32;

    /**
     * Build the `<userId>-<signature>` token for a user.
     *
     * @param int $userId user-id
     * @param string $passwordHash the user's stored password hash
     * @return string
     */
    public static function build(int $userId, string $passwordHash): string
    {
        return $userId . '-' . self::sign($userId, $passwordHash);
    }

    /**
     * Constant-time check of a signature against a user.
     *
     * @param int $userId user-id
     * @param string $passwordHash the user's stored password hash
     * @param string $signature signature to verify
     * @return bool
     */
    public static function verify(int $userId, string $passwordHash, string $signature): bool
    {
        return hash_equals(self::sign($userId, $passwordHash), $signature);
    }

    /**
     * Compute the signature for a user.
     *
     * @param int $userId user-id
     * @param string $passwordHash the user's stored password hash
     * @return string
     */
    private static function sign(int $userId, string $passwordHash): string
    {
        $key = Security::getSalt() . $passwordHash;

        return substr(hash_hmac('sha256', 'feeds:' . $userId, $key), 0, self::SIGNATURE_LENGTH);
    }
}
