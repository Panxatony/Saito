<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers 2018
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller\Component;

use App\Auth\AuthenticationServiceFactory;
use App\Controller\Component\AuthUserComponent;
use App\Model\Table\UserIgnoresTable;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Authentication\PasswordHasher\PasswordHasherFactory;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\Http\Session;
use Cake\ORM\TableRegistry;
use Psr\Http\Message\ServerRequestInterface;
use Saito\Test\IntegrationTestCase;
use Saito\User\CurrentUser\CurrentUserInterface;

class AuthUserComponentTest extends IntegrationTestCase
{
    /**
     * {@inheritDoc}
     */
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserIgnore',
        'app.UserOnline',
    ];

    /**
     * @var AuthUserComponent
     */
    public $component = null;

    /**
     * @var Controller
     */
    public $controller = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->_setup();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        // Clean up after we're done
        unset($this->component, $this->controller);
    }

    /**
     * Cookie should not be set on anonoymous user
     *
     * @return void
     */
    public function testSetJwtCookieNoCookieSet()
    {
        $event = new Event('Controller.shutdown', $this->controller);
        $this->component->afterFilter($event);

        $cookie = $this->controller->getResponse()->getCookie('Saito-jwt');
        $this->assertNull($cookie);
    }

    /**
     * Integration guard for the event wiring: a real logged-in request must
     * issue the JWT cookie the SPA reads for API auth. Cake 5 maps
     * Controller.shutdown to a component's afterFilter() (not shutdown()); if
     * that callback isn't wired the cookie is never set and every /api/v2
     * request returns 401.
     *
     * @return void
     */
    public function testJwtCookieIssuedOnLoggedInRequest()
    {
        $this->_loginUser(1);
        $this->get('/');

        $this->assertResponseOk();
        $this->assertNotEmpty($this->_response->getCookie('Saito-JWT'));
    }

    /**
     * Set cookie on logged-in user 1
     *
     * @return void
     */
    public function testSetJwtCookieLoggedInSetCookieSet()
    {
        $user = $this->_loginUser(1);
        $this->component->getUser()->setSettings($user);

        $event = new Event('Controller.shutdown', $this->controller);
        $this->component->afterFilter($event);

        $cookie = $this->controller->getResponse()->getCookie('Saito-JWT');
        $this->assertNotEmpty($cookie);
        $this->assertSame('Saito-JWT', $cookie['name']);
        $this->assertFalse($cookie['httponly']);
    }

    /**
     * Delete cookie if set and user is not logged-in
     *
     * @return void
     */
    public function testSetJwtCookieDeleteCookieIfNotLoggedIn()
    {
        $request = $this->controller->getRequest();
        $request = $request->withCookieParams(['Saito-JWT' => 'foo']);
        $this->controller->setRequest($request);

        $event = new Event('Controller.shutdown', $this->controller);
        $this->component->afterFilter($event);

        $cookie = $this->controller->getResponse()->getCookie('Saito-JWT');
        $this->assertNotEmpty($cookie);
        $this->assertSame('Saito-JWT', $cookie['name']);
        $this->assertSame(1, $cookie['expires']);
    }

    /**
     * Replace token if token doesn't belong to current user
     *
     * @return void
     */
    public function testSetJwtCookieCheckUserAndReplace()
    {
        $newUser = 1;
        $user = $this->_loginUser($newUser);
        $this->component->getUser()->setSettings($user);

        $jwtKey = Configure::read('Security.cookieSalt');

        $oldUser = 2;
        $jwtPayload = ['sub' => $oldUser, 'exp' => time() + 10];
        $jwtToken = \Firebase\JWT\JWT::encode($jwtPayload, $jwtKey, 'HS256');
        $request = $this->controller->getRequest();
        $request = $request->withCookieParams(['Saito-JWT' => $jwtToken]);
        $this->controller->setRequest($request);

        $event = new Event('Controller.shutdown', $this->controller);
        $this->component->afterFilter($event);

        $cookie = $this->controller->getResponse()->getCookie('Saito-JWT');
        $this->assertNotEmpty($cookie);
        $this->assertSame('Saito-JWT', $cookie['name']);

        $payload = \Firebase\JWT\JWT::decode($cookie['value'], new \Firebase\JWT\Key($jwtKey, 'HS256'));
        $this->assertEquals(1, $payload->sub);
    }

    public function testLoginSuccessSession()
    {
        $request = ServerRequestFactory::fromGlobals();

        /** @var UserIgnoresTable $Ignores */
        $Ignores = TableRegistry::getTableLocator()->get('UserIgnores');
        $Ignores->ignore(3, 7);

        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['read', 'check', 'write', 'renew', 'destroy', 'id', 'start'])
            ->getMock();
        $session->expects($this->atLeastOnce())
           ->method('read')
           ->willReturnCallback(function ($key) {
               return $key === 'Auth' ? ['username' => 'Ulysses'] : null;
           });
        // SessionAuthenticator::persistIdentity()/clearIdentity() call
        // check()/renew()/write()/destroy() when AuthenticationComponent
        // setIdentity() is called from login().
        $session->method('check')->willReturn(true);
        $session->method('id')->willReturn('test-session-id');

        $request = $request->withAttribute('session', $session);
        $this->_setup($request);

        $this->component->login();

        /// CurrentUser exists and is set
        $CU = $this->component->getUser();
        $this->assertInstanceOf(CurrentUserInterface::class, $CU);
        $this->assertSame($CU, $this->controller->CurrentUser);
        $this->assertEquals('Ulysses', $CU->get('username'));

        /// Check that ignores data is attached to CurrentUser
        $this->assertTrue($CU->ignores(7));
    }

    /**
     * Test that the authentication cookie is refreshed.
     *
     * @return void
     */
    public function testAuthenticationRefresh()
    {
        /// Setup the request for the authenticator
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(1);
        $hasher = PasswordHasherFactory::build(DefaultPasswordHasher::class);
        $username = $user->get('username');
        // Cake 4 CookieAuthenticator token format:
        // hash(username . password . hmac_sha1(username.password, Security::salt))
        $value = $username . $user->get('password');
        $hmac = hash_hmac('sha1', $value, \Cake\Utility\Security::getSalt());
        $hash = $hasher->hash($value . $hmac);
        $cookieName = Configure::read('Security.cookieAuthName');
        $webroot = '/sub/';
        $request = (new ServerRequest([
            'cookies' => [$cookieName => json_encode([$username, $hash])],
            'webroot' => $webroot,
        ]));
        $this->_setup($request);

        /// Trigger refresh on cookie-login
        $this->component->login();

        /// Test that cookie is set
        $cookie = $this->controller->getResponse()->getCookie($cookieName);
        $this->assertNotEmpty($cookie);

        /// Test that cookie expiry is set
        $authProvider = $this->component->Authentication
            ->getAuthenticationService()
            ->authenticators()
            ->get('Cookie');
        $expire = $authProvider->getConfig('cookie.expire');
        $this->assertWithinRange($expire->getTimestamp(), (int)$cookie['expires'], 2);
        $this->assertEquals($webroot, $cookie['path']);
    }

    private function _setup(?ServerRequestInterface $request = null)
    {
        // buildApp() needs the routes (uses Router::url(['_name' => 'login']))
        // and so does the JWT cookie path on the component.
        \Cake\Routing\Router::reload();
        $app = new \App\Application(CONFIG);
        $app->bootstrap();
        $app->pluginBootstrap();
        $builder = \Cake\Routing\Router::createRouteBuilder('/');
        $app->routes($builder);
        $app->pluginRoutes($builder);

        $request = $request ?: new ServerRequest();
        // AuthUserComponent uses is('bot'), so register the detector the
        // Detectors plugin would normally provide.
        $request->addDetector('bot', function () { return false; });
        $response = new Response();

        $service = AuthenticationServiceFactory::buildApp();
        // v2 authenticate signature: only the request, returns a Result.
        $result = $service->authenticate($request);

        $request = $request->withAttribute('authentication', $service);
        $request = $request->withAttribute('authenticationResult', $result);

        // Anonymous subclass declares $CurrentUser so AuthUserComponent can set
        // it without triggering PHP 8.2's dynamic-property deprecation. In
        // production this property is declared on App\Controller\AppController.
        $controller = new class ($request) extends Controller {
            public $CurrentUser;
        };

        $registry = new ComponentRegistry($controller);
        $component = new AuthUserComponent($registry);

        $this->component = $component;
        $this->controller = $controller;
    }
}
