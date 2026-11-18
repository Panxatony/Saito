<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Auth;

use App\Auth\AuthenticationServiceFactory;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;

class AuthenticationServiceFactoryTest extends TestCase
{
    public array $fixtures = ['app.User'];

    /**
     * @param string $base '' for a forum at the root of its host, '/forum' for one in a directory
     * @param array<string, string> $post form fields
     * @return \Cake\Http\ServerRequest
     */
    private function loginRequest(string $base, array $post): ServerRequest
    {
        $request = new ServerRequest([
            'url' => '/login',
            'base' => $base,
            'webroot' => $base . '/',
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'post' => $post,
        ]);
        Router::reload();
        $this->loadRoutes();
        Router::setRequest($request);

        return $request;
    }

    public function testTheLoginFormAuthenticatesAtTheRootOfAHost(): void
    {
        $request = $this->loginRequest('', ['username' => 'Ulysses', 'password' => 'test']);

        $result = AuthenticationServiceFactory::buildApp()->authenticate($request);

        $this->assertTrue($result->isValid());
    }

    /**
     * A forum installed in a directory (`https://example.org/forum/`).
     *
     * The form authenticator only looks at a request to the login address.
     * authentication 4 resolves that address through the router, and the
     * router puts the directory in front of whatever it is given — so an
     * address that already carries the directory is prefixed twice, matches
     * nothing, and every login is silently ignored.
     *
     * @return void
     */
    public function testTheLoginFormAuthenticatesInASubdirectory(): void
    {
        $request = $this->loginRequest('/forum', ['username' => 'Ulysses', 'password' => 'test']);

        $result = AuthenticationServiceFactory::buildApp()->authenticate($request);

        $this->assertTrue($result->isValid());
    }

    public function testAWrongPasswordDoesNotAuthenticate(): void
    {
        $request = $this->loginRequest('/forum', ['username' => 'Ulysses', 'password' => 'wrong']);

        $result = AuthenticationServiceFactory::buildApp()->authenticate($request);

        $this->assertFalse($result->isValid());
    }
}
