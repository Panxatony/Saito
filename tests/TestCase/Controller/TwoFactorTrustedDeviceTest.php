<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller;

use App\Model\Table\TwoFactorCredentialsTable;
use App\Model\Table\TwoFactorTrustedDevicesTable;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use OTPHP\TOTP;
use Saito\Test\IntegrationTestCase;

/**
 * "Stay signed in" with a second factor switched on.
 *
 * Turning 2FA on used to take remember-me away entirely, and the report that
 * followed was the honest kind: repeated logins on a phone, where the browser
 * evicts sessions freely. Two separate faults, both mine — the checkbox never
 * reached the step that could act on it, and the cookie was refused on the way
 * back in regardless.
 *
 * So these tests are written in pairs. Each one that proves the convenience is
 * back sits next to one proving the hole it replaced is still shut, because the
 * temptation when fixing this is to simply stop refusing the cookie — which
 * would hand every pre-enrolment cookie a free pass past the factor.
 */
class TwoFactorTrustedDeviceTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.Smiley',
        'app.SmileyCode',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
        'app.TwoFactorCredential',
        'app.TwoFactorRecoveryCode',
        'app.TwoFactorTrustedDevice',
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    private TwoFactorCredentialsTable $Credentials;
    private TwoFactorTrustedDevicesTable $Devices;
    private string $secret = '';

    /** Ulysses, id 3 in the fixture, with the password the other login tests use. */
    private const USER_ID = 3;
    private const USERNAME = 'Ulysses';
    private const PASSWORD = 'test';

    public function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        /** @var TwoFactorCredentialsTable $credentials */
        $credentials = TableRegistry::getTableLocator()->get('TwoFactorCredentials');
        $this->Credentials = $credentials;
        /** @var TwoFactorTrustedDevicesTable $devices */
        $devices = TableRegistry::getTableLocator()->get('TwoFactorTrustedDevices');
        $this->Devices = $devices;
    }

    private function authCookieName(): string
    {
        return (string)Configure::read('Security.cookieAuthName');
    }

    private function deviceCookieName(): string
    {
        return $this->authCookieName() . '-2FA';
    }

    private function enrol(): void
    {
        $this->secret = $this->Credentials->beginEnrolment(self::USER_ID);
        $this->Credentials->confirmEnrolment(self::USER_ID, $this->currentCode());
    }

    private function currentCode(): string
    {
        return TOTP::createFromSecret($this->secret)->now();
    }

    /**
     * Every cookie the last response set, by name.
     *
     * Both halves have to be collected: the remember-me cookie is written by
     * the authenticator as a raw `Set-Cookie` header, the device cookie through
     * the response's cookie collection. A browser sees no difference; this
     * harness does.
     *
     * @return array<string, string>
     */
    private function issuedCookies(): array
    {
        $cookies = [];
        foreach ($this->_response->getHeader('Set-Cookie') as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $cookies[$name] = rawurldecode($value);
        }
        foreach ($this->_response->getCookies() as $name => $cookie) {
            $cookies[$name] = (string)$cookie['value'];
        }

        return $cookies;
    }

    /**
     * The whole two-step login, with "stay signed in" ticked on the first step.
     *
     * @param bool $rememberMe whether to tick the box
     * @return array<string, string> the cookies the login handed out
     */
    private function completeLogin(bool $rememberMe = true): array
    {
        $this->enrol();

        $data = ['username' => self::USERNAME, 'password' => self::PASSWORD];
        if ($rememberMe) {
            $data['remember_me'] = 1;
        }
        $this->mockSecurity();
        $this->post('/login', $data);

        // The harness builds each request's session from what the test seeded,
        // so carry across what the server just wrote. A browser uses a cookie.
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
        $this->mockSecurity();
        $this->post('/users/two-factor', ['code' => $this->currentCode()]);

        return $this->issuedCookies();
    }

    /**
     * The first fault: the checkbox lives on the password form, but the cookie
     * it asks for can only be minted a step later. Nothing carried the answer
     * across, so remember-me was silently dead for every enrolled account.
     *
     * @return void
     */
    public function testTheSecondStepMintsTheRememberMeCookieTheFirstStepAskedFor(): void
    {
        $cookies = $this->completeLogin(rememberMe: true);

        $this->assertArrayHasKey(
            $this->authCookieName(),
            $cookies,
            '"stay signed in" has to survive the second factor, or 2FA quietly revokes it',
        );
        $this->assertArrayHasKey($this->deviceCookieName(), $cookies);
        $this->assertSame(1, $this->Devices->find()->where(['user_id' => self::USER_ID])->count());
    }

    /**
     * Unticked stays unticked. The second factor is not a reason to start
     * persisting a login the member did not ask to persist.
     *
     * @return void
     */
    public function testWithoutTheCheckboxNothingIsPersisted(): void
    {
        $cookies = $this->completeLogin(rememberMe: false);

        $this->assertArrayNotHasKey($this->authCookieName(), $cookies);
        $this->assertArrayNotHasKey($this->deviceCookieName(), $cookies);
        $this->assertSame(0, $this->Devices->find()->count());
    }

    /**
     * The point of the whole exercise: come back later with no session, and be
     * let in.
     *
     * @return void
     */
    public function testATrustedDeviceIsLetBackInByItsCookies(): void
    {
        $cookies = $this->completeLogin();

        $this->cookie($this->authCookieName(), $cookies[$this->authCookieName()]);
        $this->cookie($this->deviceCookieName(), $cookies[$this->deviceCookieName()]);
        $this->get('/');

        $this->assertResponseOk();
        $this->assertTrue(
            $this->_controller->CurrentUser->isLoggedIn(),
            'a device that proved the second factor must not have to prove it again every session',
        );
    }

    /**
     * The hole this replaced, and the reason the fix could not simply be "stop
     * refusing the cookie": a remember-me cookie is stateless, so one minted
     * before enrolment is indistinguishable from one minted after and cannot be
     * revoked. Without the device token beside it, it still gets nowhere.
     *
     * @return void
     */
    public function testTheRememberMeCookieAloneIsStillRefused(): void
    {
        $cookies = $this->completeLogin();

        // Exactly the cookie a browser holds — only without the device token.
        $this->cookie($this->authCookieName(), $cookies[$this->authCookieName()]);
        $this->get('/');

        $this->assertResponseOk();
        $this->assertFalse(
            $this->_controller->CurrentUser->isLoggedIn(),
            'the remember-me cookie must not stand in for the second factor on its own',
        );
    }

    /**
     * A token is an answer for one account only, so holding a valid one does
     * not make somebody else's remember-me cookie work.
     *
     * @return void
     */
    public function testADeviceTokenIssuedToAnotherAccountAdmitsNothing(): void
    {
        $cookies = $this->completeLogin();
        $foreign = $this->Devices->issueFor(self::USER_ID + 1);

        $this->cookie($this->authCookieName(), $cookies[$this->authCookieName()]);
        $this->cookie($this->deviceCookieName(), $foreign);
        $this->get('/');

        $this->assertFalse($this->_controller->CurrentUser->isLoggedIn());
    }

    /**
     * Trust rests on a factor. Take the factor away and the trust goes with it,
     * or switching 2FA off would leave standing permissions behind it.
     *
     * @return void
     */
    public function testDisablingTheSecondFactorWithdrawsEveryDevice(): void
    {
        $this->completeLogin();
        $this->assertSame(1, $this->Devices->find()->where(['user_id' => self::USER_ID])->count());

        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'disable', 'password' => self::PASSWORD]);

        $this->assertSame(0, $this->Devices->find()->where(['user_id' => self::USER_ID])->count());
    }

    /**
     * Signing out on the phone has no business signing out the laptop — that is
     * the whole reason the trust is recorded per device rather than per account.
     *
     * @return void
     */
    public function testLoggingOutForgetsOnlyTheDeviceDoingIt(): void
    {
        $cookies = $this->completeLogin();
        $otherDevice = $this->Devices->issueFor(self::USER_ID);

        $this->cookie($this->deviceCookieName(), $cookies[$this->deviceCookieName()]);
        $this->get('/logout');

        $this->assertFalse($this->Devices->isTrusted(self::USER_ID, $cookies[$this->deviceCookieName()]));
        $this->assertTrue($this->Devices->isTrusted(self::USER_ID, $otherDevice));
    }

    /**
     * An expired row is not a valid one — otherwise the trust would be
     * permanent, which is not what a thirty-day window means.
     *
     * @return void
     */
    public function testTrustExpires(): void
    {
        $token = $this->Devices->issueFor(self::USER_ID);
        $this->assertTrue($this->Devices->isTrusted(self::USER_ID, $token));

        $this->Devices->updateAll(
            ['expires' => new DateTime('-1 minute')],
            ['user_id' => self::USER_ID],
        );

        $this->assertFalse($this->Devices->isTrusted(self::USER_ID, $token));
    }

    /**
     * The cookie is half of a login credential, so it carries the same flags as
     * the remember-me cookie it travels with — a weaker flag on either is a
     * weaker flag on both, and this one is the newer and easier to forget.
     *
     * Secure is not asserted here because it follows the request's scheme, and
     * the harness does not speak https; on a real installation `App.fullBaseUrl`
     * is derived from `env('HTTPS')` in config/bootstrap.php.
     *
     * @return void
     */
    public function testTheDeviceCookieIsNotReadableByScriptAndDoesNotTravelCrossSite(): void
    {
        $this->completeLogin();

        $cookie = $this->_response->getCookies()[$this->deviceCookieName()] ?? null;
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie['httponly'], 'a script must not be able to read the device token');
        $this->assertSame('Lax', $cookie['samesite']);
        $this->assertNotEmpty($cookie['expires'], 'a session cookie would defeat the point');
    }

    /**
     * The token is a credential: a database read must not yield something that
     * can be put straight into a cookie.
     *
     * @return void
     */
    public function testTheTokenIsNotStoredInPlaintext(): void
    {
        $token = $this->Devices->issueFor(self::USER_ID);

        $stored = (string)$this->Devices->find()->where(['user_id' => self::USER_ID])->first()->get('token_hash');

        $this->assertNotSame($token, $stored);
        $this->assertStringNotContainsString($token, $stored);
    }
}
