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

use App\Auth\LegacyPasswordHasherSaltless;
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
        // password against the saito-specific hashers.
        $service->loadIdentifier('Authentication.Password', [
            'fields' => ['username' => 'username', 'password' => 'password'],
            'resolver' => [
                'className' => 'Authentication.Orm',
                'userModel' => 'Users',
            ],
            'passwordHasher' => [
                'className' => 'Authentication.Fallback',
                'hashers' => [
                    'Authentication.Default',
                    [
                        'className' => 'App\\Auth\\Mlf2PasswordHasher',
                    ],
                ],
            ],
        ]);

        // Authenticators are checked in order of registration.
        // Leave Session first.
        // `identify` stays false: Saito does not configure an Identifier
        // for the session, so the session payload is the source of truth.
        $service->loadAuthenticator('Authentication.Session');
        $service->loadAuthenticator(
            'Authentication.Cookie',
            [
                'cookie' => [
                    'expire' => new \DateTimeImmutable('+10 days'),
                    'httpOnly' => true,
                    'name' => Configure::read('Security.cookieAuthName'),
                    'path' => Router::url('/', false),
                ],
            ]
        );
        $service->loadAuthenticator(
            'Authentication.Form',
            ['loginUrl' => Router::url(['_name' => 'login'])]
        );

        return $service;
    }
}
