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

use App\Auth\Mlf2PasswordHasher;
use Authentication\AuthenticationService;
use Cake\Core\Configure;
use Cake\Routing\Router;

/**
 * Builds AuthenticationService consumed by Authentication middleware
 */
class AuthenticationServiceFactory
{
    /**
     * Build authentication service for JWT based API
     *
     * @return AuthenticationService
     */
    public static function buildJwt(): AuthenticationService
    {
        $service = new AuthenticationService();
        // returnPayload=true: Saito does not configure an Identifier, so let
        // the JWT payload (carrying 'sub' = user-id) be the identity. The
        // controller layer (CurrentUser/AuthUserComponent) hydrates the
        // actual User entity from the database when needed.
        $service->loadAuthenticator('Authentication.Jwt', [
            'returnPayload' => true,
            // Do not accept the JWT via a `?token=` query parameter (the Cake
            // default): bearer tokens in URLs leak into access logs, browser
            // history and Referer headers. The token travels in the
            // Authorization header, and only there — TokenAuthenticator::
            // getToken() reads the header and the query parameter, never a
            // cookie, so the `<Session.cookie>-JWT` cookie Saito used to mint
            // was never an authentication path on this side.
            'queryParam' => null,
            'secretKey' => Configure::read('Security.jwtSalt')
                ?: Configure::read('Security.cookieSalt'),
        ]);

        return $service;
    }

    /**
     * Build authentication service with Session, Cookie and Form
     *
     * @return AuthenticationService
     */
    public static function buildApp(): AuthenticationService
    {
        $service = new AuthenticationService();

        $service->setConfig('queryParam', 'redirect');
        $service->setConfig('unauthenticatedRedirect', Router::url(['_name' => 'login'], false));

        // Password identifier looks up users by username and verifies the
        // password against the saito-specific hashers. Passed directly to the
        // identifying authenticators (Cookie, Form); loadIdentifier() is
        // deprecated since authentication 3.3.0.
        $passwordIdentifier = [
            'Authentication.Password' => [
                'fields' => ['username' => 'username', 'password' => 'password'],
                'resolver' => [
                    'className' => 'Authentication.Orm',
                    'userModel' => 'Users',
                ],
                // Two formats are accepted, and the list is deliberately this
                // short. `Default` is bcrypt, which every password set by this
                // software has used for years. `Mlf2PasswordHasher` reads the
                // salted-sha1 form mylittleforum 2.x wrote, because Saito is
                // what those forums upgraded into; a hash it matches is
                // rewritten as bcrypt on the next successful login.
                //
                // What is *not* here is the plain md5/sha1 of even older
                // installations, and it is not an oversight: accepting a
                // thirteen-year-old unsalted hash would turn something
                // trivially crackable back into a working credential. Such an
                // account is not lost — the password reset issues a bcrypt
                // hash like any other, without reading the old value.
                //
                // A reader for that format used to sit in src/Auth as
                // `LegacyPasswordHasherSaltless`, wired into nothing and with
                // no supported way to wire it in, since this chain is not
                // configurable. It was removed in 8.4.14 (#99) rather than
                // left to imply a capability that did not exist.
                // `bin/cake clear_unusable_passwords` reports what an
                // installation still carries in that format, and empties it on
                // request. Measured on macnemo.de in 2026-08: 534 accounts,
                // none used since 2013, against 287 on bcrypt.
                'passwordHasher' => [
                    'className' => 'Authentication.Fallback',
                    'hashers' => [
                        'Authentication.Default',
                        [
                            'className' => 'App\\Auth\\Mlf2PasswordHasher',
                        ],
                    ],
                ],
            ],
        ];

        // Authenticators are checked in order of registration.
        // Personalized RSS feed token (Feeds plugin). Checked first so a signed
        // feed URL identifies the user; on any other path it reports
        // credentials-missing and the session/cookie authenticators take over.
        $service->loadAuthenticator('Feeds.FeedToken');
        // Leave Session first (after the stateless feed token).
        // `identify` stays false: Saito does not configure an Identifier
        // for the session, so the session payload is the source of truth.
        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator(
            'Authentication.Cookie',
            [
                'identifier' => $passwordIdentifier,
                // Cake 5's Cookie::create() only understands the lower-case
                // keys expires/httponly/secure/samesite; the legacy Cake 3
                // spellings ('expire', 'httpOnly') are silently dropped, which
                // turned the remember-me cookie into a flag-less session cookie
                // (no expiry -> users logged out daily). Keep these in sync with
                // AuthUserComponent::refreshAuthenticationProvider().
                'cookie' => [
                    'expires' => new \DateTimeImmutable('+10 days'),
                    'path' => Router::url('/', false),
                    'name' => Configure::read('Security.cookieAuthName'),
                    'httponly' => true,
                    'secure' => str_starts_with(
                        (string)Configure::read('App.fullBaseUrl'),
                        'https',
                    ),
                    'samesite' => 'Lax',
                ],
            ]
        );
        $service->loadAuthenticator(
            'Authentication.Form',
            [
                'identifier' => $passwordIdentifier,
                'loginUrl' => Router::url(['_name' => 'login']),
            ]
        );

        return $service;
    }
}
