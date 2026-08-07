<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Test;

use App\Application;
use Cake\Http\Client\Request;
use GuzzleHttp\Psr7\Uri;
use Laminas\Diactoros\Request as ZendRequest;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;

class ApplicationTest extends SaitoTestCase
{
    public array $fixtures = [
        'app.Category',
    ];

    private const API_ROOT = 'api/v2';

    /** @var Application */
    private $application;

    public function setUp(): void
    {
        parent::setUp();

        $this->application = new Application(CONFIG);
        $this->application->bootstrap();
        $this->application->pluginBootstrap();
        $builder = \Cake\Routing\Router::createRouteBuilder('/');
        $this->application->routes($builder);
        $this->application->pluginRoutes($builder);
    }

    public function teardDown()
    {
        unset($this->application);
        parent::tearDown();
    }

    /**
     * Every real route under the API scope gets the JWT service, and nothing
     * else does.
     *
     * The addresses are the ones `bin/cake routes` actually lists, not invented
     * ones: `resources()` in the two plugins' `config/routes.php` binds each to
     * specific HTTP methods, so a guessed path answers 404 and tells you
     * nothing about whether the scope exists.
     *
     * @return void
     */
    public function testGetAuthenticationServiceJwt()
    {
        $urls = [
            '/' . self::API_ROOT . '/bookmarks',
            '/' . self::API_ROOT . '/bookmarks/1',
            '/' . self::API_ROOT . '/uploads',
            '/' . self::API_ROOT . '/uploads/1',
            '/' . self::API_ROOT . '/uploads/thumb/1',
        ];

        foreach ($urls as $url) {
            $request = new ServerRequest([], [], $url);
            $response = new Response();

            $provider = $this->application->getAuthenticationService($request, $response);

            $this->assertTrue($provider->authenticators()->has('Jwt'), $url);
            $this->assertFalse($provider->authenticators()->has('Session'), $url);
            $this->assertFalse($provider->authenticators()->has('Cookie'), $url);
        }
    }

    /**
     * A path that merely *contains* the scope is an ordinary page.
     *
     * This used to be the opposite: the check was an unanchored regex, so
     * `/entries/view/1/api/v2` picked the JWT service and showed a signed-in
     * member as logged out. Stricter rather than looser, so never an
     * escalation — but the CSRF exemption in `middleware()` has always been an
     * anchored prefix, and one loose half of a pair invites somebody to
     * harmonise them in the wrong direction. The comment there records that a
     * substring match on `/api/` was once genuinely exploitable.
     *
     * @return void
     */
    public function testAPathMerelyContainingTheApiScopeIsNotTheApiScope()
    {
        $urls = [
            '/foo/' . self::API_ROOT . '/foo',
            '/entries/view/1/' . self::API_ROOT,
            // No trailing slash: not a route either, and it must not be able to
            // opt out of session authentication or of CSRF.
            '/' . self::API_ROOT,
        ];

        foreach ($urls as $url) {
            $request = new ServerRequest([], [], $url);
            $response = new Response();

            $provider = $this->application->getAuthenticationService($request, $response);

            $this->assertTrue($provider->authenticators()->has('Session'), $url);
            $this->assertFalse($provider->authenticators()->has('Jwt'), $url);
        }
    }

    public function testGetAuthenticationServiceApp()
    {
        $urls = [ '/', '/foo', '/foo/', ];

        foreach ($urls as $url) {
            $request = new ServerRequest([], [], $url);
            $response = new Response();

            $provider = $this->application->getAuthenticationService($request, $response);
            $authenticator = $provider->authenticators()->get('Session');

            $this->assertNotEmpty($authenticator);
        }
    }
}
