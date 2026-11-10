<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Auth;

use Authentication\Authenticator\JwtAuthenticator;

/**
 * JWT authenticator that refuses tokens without an expiry (#166).
 *
 * firebase/php-jwt checks `exp` when a token has one and accepts a token
 * without it forever. A bearer token that never expires is a password that
 * cannot be changed; require it.
 */
class ExpiringJwtAuthenticator extends JwtAuthenticator
{
    /**
     * @inheritDoc
     */
    protected function decodeToken(string $token): ?object
    {
        $payload = parent::decodeToken($token);
        if ($payload === null || !isset($payload->exp)) {
            return null;
        }

        return $payload;
    }
}
