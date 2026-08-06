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

use App\Controller\Component\AuthUserComponent;
use App\Model\Table\TwoFactorCredentialsTable;
use App\Model\Table\TwoFactorRecoveryCodesTable;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;
use OTPHP\TOTP;
use Saito\Test\IntegrationTestCase;

/**
 * The two-phase login (#62).
 *
 * These are the tests that decide whether the feature is real. A second factor
 * that can be walked past is worse than none, because it is believed in — so
 * the weight here is on what must *not* happen: no identity from the password
 * alone, no session, no remember-me cookie, and no way to spend one code twice.
 */
class TwoFactorLoginTest extends IntegrationTestCase
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
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    private TwoFactorCredentialsTable $Credentials;
    private TwoFactorRecoveryCodesTable $Codes;
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
        /** @var TwoFactorRecoveryCodesTable $codes */
        $codes = TableRegistry::getTableLocator()->get('TwoFactorRecoveryCodes');
        $this->Codes = $codes;
    }

    /**
     * Enrol the test account and confirm it, so the second factor is live.
     *
     * @return void
     */
    private function enrol(): void
    {
        $this->secret = $this->Credentials->beginEnrolment(self::USER_ID);
        $this->Credentials->confirmEnrolment(self::USER_ID, $this->currentCode());
    }

    /**
     * @return string a code valid right now
     */
    private function currentCode(): string
    {
        return TOTP::createFromSecret($this->secret)->now();
    }

    /**
     * @return void
     */
    private function postPassword(): void
    {
        $this->mockSecurity();
        $this->post('/login', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
    }

    /**
     * The pending marker as the server actually stored it.
     *
     * `Session::write()` reads dots as a path, so the constant
     * `Saito.pending2fa` lands at `$_SESSION['Saito']['pending2fa']` — not
     * under its own literal key.
     *
     * @return array<string, mixed>|null
     */
    private function pendingMarker(): ?array
    {
        $marker = $_SESSION['Saito']['pending2fa'] ?? null;

        return is_array($marker) ? $marker : null;
    }

    /**
     * Hand what the server wrote into the session to the next request.
     *
     * The test harness builds each request's session from what the test seeded,
     * not from the previous response, so a two-request flow has to carry it
     * across by hand. The browser does this with a cookie.
     *
     * @return void
     */
    private function carrySession(): void
    {
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
    }

    /**
     * The heart of it: the right password, and still not logged in.
     *
     * @return void
     */
    public function testThePasswordAloneDoesNotLogIn(): void
    {
        $this->enrol();
        $this->postPassword();

        // No identity was set…
        $this->assertArrayNotHasKey('Auth', $_SESSION);
        // …and the account is parked for its second factor instead.
        $this->assertNotNull($this->pendingMarker());
        $this->assertSame(self::USER_ID, (int)$this->pendingMarker()['userId']);
    }

    /**
     * The remember-me cookie is minted by setIdentity(). If it appeared here it
     * would be a standing bypass of the factor that has not been proved yet.
     *
     * @return void
     */
    public function testNoRememberMeCookieIsMintedBeforeTheSecondFactor(): void
    {
        $this->enrol();
        $this->mockSecurity();
        $this->post('/login', [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
            'remember_me' => 1,
        ]);

        $this->assertEmpty(
            $this->_response->getCookie((string)Configure::read('Security.cookieAuthName')),
            'no persistent-login cookie may be minted before the second factor',
        );
    }

    /**
     * The other half of the same hole: a cookie minted *before* the account
     * enrolled. It cannot be revoked — it validates against username and
     * password hash, not against anything the server keeps — so it has to be
     * refused at the door, or it would walk past 2FA until it expired.
     *
     * @return void
     */
    public function testARememberMeCookieIsRefusedForAnAccountWithASecondFactor(): void
    {
        // A cookie of exactly the shape the CookieAuthenticator mints, made
        // before enrolment.
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(self::USER_ID);
        $value = $user->get('username') . $user->get('password');
        $hmac = hash_hmac('sha1', $value, Security::getSalt());
        $token = (new DefaultPasswordHasher())->hash($value . $hmac);
        $cookieName = (string)Configure::read('Security.cookieAuthName');

        $this->enrol();
        $this->cookie($cookieName, json_encode([$user->get('username'), $token]));

        $this->get('/');

        $this->assertResponseOk();
        $this->assertFalse(
            $this->_controller->CurrentUser->isLoggedIn(),
            'a remember-me cookie must not stand in for the second factor',
        );
    }

    public function testTheCodeCompletesTheLogin(): void
    {
        $this->enrol();
        $this->postPassword();
        $this->carrySession();

        $this->mockSecurity();
        $this->post('/users/two-factor', ['code' => $this->currentCode()]);

        $this->assertRedirect();
        $this->assertArrayHasKey('Auth', $_SESSION);
        $this->assertNull($this->pendingMarker());
    }

    public function testAWrongCodeDoesNotLogIn(): void
    {
        $this->enrol();
        $this->postPassword();
        $this->carrySession();

        $this->mockSecurity();
        $this->post('/users/two-factor', ['code' => '000000']);

        $this->assertArrayNotHasKey('Auth', $_SESSION);
        // Still pending, so the member may try again — but only within budget.
        $this->assertNotNull($this->pendingMarker());
    }

    public function testARecoveryCodeAlsoCompletesTheLoginAndIsThenSpent(): void
    {
        $this->enrol();
        $codes = $this->Codes->issueFor(self::USER_ID);
        $this->postPassword();
        $this->carrySession();

        $this->mockSecurity();
        $this->post('/users/two-factor', ['code' => $codes[0]]);
        $this->assertRedirect();
        $this->assertArrayHasKey('Auth', $_SESSION);

        $this->assertSame(
            TwoFactorRecoveryCodesTable::CODE_COUNT - 1,
            $this->Codes->remainingFor(self::USER_ID),
        );
    }

    /**
     * The challenge is reachable without an identity by necessity. What stands
     * in for one is the pending marker — so without it there is nothing to do.
     *
     * @return void
     */
    public function testTheChallengeIsUselessWithoutAPendingLogin(): void
    {
        $this->enrol();

        $this->mockSecurity();
        $this->post('/users/two-factor', ['code' => $this->currentCode()]);

        $this->assertRedirectContains('login');
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }

    /**
     * A password verified long enough ago should not still be redeemable.
     *
     * @return void
     */
    public function testAStalePendingLoginExpires(): void
    {
        $this->enrol();
        $this->postPassword();

        // Wind the marker back past its life.
        $this->session([
            'Saito' => [
                'pending2fa' => [
                    'userId' => self::USER_ID,
                    'at' => time() - AuthUserComponent::PENDING_2FA_TTL - 1,
                ],
            ],
        ]);

        $this->mockSecurity();
        $this->post('/users/two-factor', ['code' => $this->currentCode()]);

        $this->assertRedirectContains('login');
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }

    /**
     * An account without a second factor is unaffected — the ordinary login
     * still logs in, in one step.
     *
     * @return void
     */
    public function testAnAccountWithoutASecondFactorLogsInAsBefore(): void
    {
        $this->postPassword();

        $this->assertArrayHasKey('Auth', $_SESSION);
        $this->assertNull($this->pendingMarker());
    }

    /**
     * Guessing six digits must cost something.
     *
     * @return void
     */
    public function testTheSecondFactorIsThrottled(): void
    {
        $this->enrol();
        $this->postPassword();
        $this->carrySession();

        for ($i = 0; $i < 12; $i++) {
            $this->mockSecurity();
            $this->post('/users/two-factor', ['code' => '000000']);
        }

        // Even the right code is refused once the budget is spent.
        $this->mockSecurity();
        $this->post('/users/two-factor', ['code' => $this->currentCode()]);
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }
}
