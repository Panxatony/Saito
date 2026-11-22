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

use App\Model\Entity\WebauthnCredential;
use App\Model\Table\TwoFactorTrustedDevicesTable;
use App\Model\Table\WebauthnCredentialsTable;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use OpenSSLAsymmetricKey;
use OTPHP\TOTP;
use Saito\Test\IntegrationTestCase;
use Saito\User\Auth\WebauthnService;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * A passkey login that succeeds (#81).
 *
 * {@see WebauthnTest} covers the gates around the ceremony and deliberately
 * stops short of a valid signature, so until this class existed nothing drove
 * the path a member actually takes: what the session holds afterwards, which
 * cookies are handed out, whether the signature counter is written back. All
 * of that sits behind `verifyAssertion()` returning, and it only returns for a
 * real signature.
 *
 * So this class uses a small software authenticator
 * ({@see SoftwareAuthenticatorTrait}): an ES256 key pair, the public half
 * stored as the credential, the private half signing the assertion the way a
 * device does. The cryptography is still the library's to verify —
 * `testASignatureFromAnotherKeyIsRefused` is here to show that it does, and
 * that the tests around it do not pass because nothing is checked.
 */
class WebauthnLoginTest extends IntegrationTestCase
{
    use SoftwareAuthenticatorTrait;

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
        'app.WebauthnCredential',
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    /** Ulysses, with the password the other login tests use. */
    private const USER_ID = 3;
    private const USERNAME = 'Ulysses';
    private const PASSWORD = 'test';
    private const ORIGIN = 'https://forum.example.org';
    private const RAW_ID = 'software-authenticator-1';

    private WebauthnCredentialsTable $Passkeys; // assigned in setUp() skipcq: PHP-E1008
    private TwoFactorTrustedDevicesTable $Devices; // assigned in setUp() skipcq: PHP-E1008
    private mixed $fullBaseUrl = null;

    public function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        /** @var WebauthnCredentialsTable $passkeys */
        $passkeys = TableRegistry::getTableLocator()->get('WebauthnCredentials');
        $this->Passkeys = $passkeys;
        /** @var TwoFactorTrustedDevicesTable $devices */
        $devices = TableRegistry::getTableLocator()->get('TwoFactorTrustedDevices');
        $this->Devices = $devices;

        // The relying party and the origin both come from here. A passkey is
        // bound to them, so the ceremony needs a definite, https one.
        $this->fullBaseUrl = Configure::read('App.fullBaseUrl');
        Configure::write('App.fullBaseUrl', self::ORIGIN);

        /** @var \App\Model\Table\TwoFactorCredentialsTable $credentials */
        $credentials = TableRegistry::getTableLocator()->get('TwoFactorCredentials');
        $secret = $credentials->beginEnrolment(self::USER_ID);
        $credentials->confirmEnrolment(self::USER_ID, TOTP::createFromSecret($secret)->now());
    }

    public function tearDown(): void
    {
        Configure::write('App.fullBaseUrl', $this->fullBaseUrl);
        parent::tearDown();
    }

    /**
     * Register the public half of a key as the member's passkey.
     *
     * Stored directly, without a ceremony: these tests are about the login.
     * {@see WebauthnRegistrationTest} registers through the ceremony.
     *
     * @param \OpenSSLAsymmetricKey $key the authenticator's key
     * @return \App\Model\Entity\WebauthnCredential
     */
    private function registerPasskey(OpenSSLAsymmetricKey $key): WebauthnCredential
    {
        $record = CredentialRecord::create(
            self::RAW_ID,
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            $this->coseKey($key),
            (new WebauthnService())->userHandle(self::USER_ID),
            0,
        );

        return $this->Passkeys->store(self::USER_ID, $record, 'Software authenticator');
    }

    /**
     * What this class's authenticator answers a login challenge with.
     *
     * @param \OpenSSLAsymmetricKey $key the key that signs
     * @param string $challenge the challenge as the server sent it (base64url)
     * @param int $counter the authenticator's signature counter
     * @return string the credential as a browser serialises it
     */
    private function assertion(OpenSSLAsymmetricKey $key, string $challenge, int $counter = 1): string
    {
        return $this->assertionFor($key, $challenge, self::RAW_ID, self::USER_ID, self::ORIGIN, $counter);
    }

    /**
     * The password step, then the challenge for the second one.
     *
     * @param bool $rememberMe whether "stay signed in" is ticked
     * @return string the challenge the server issued (base64url)
     */
    private function passwordStepAndChallenge(bool $rememberMe = false): string
    {
        $data = ['username' => self::USERNAME, 'password' => self::PASSWORD];
        if ($rememberMe) {
            $data['remember_me'] = 1;
        }
        $this->mockSecurity();
        $this->post('/login', $data);
        // The harness builds each request's session from what the test seeded,
        // so carry across what the server just wrote. A browser uses a cookie.
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);

        $this->get('/users/webauthn-login-options');
        $this->assertResponseOk();
        $options = json_decode((string)$this->_response->getBody(), true);
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);

        return (string)$options['challenge'];
    }

    private function answer(WebauthnCredential $passkey, string $assertion): void
    {
        $this->mockSecurity();
        $this->post('/users/webauthn-login', [
            'credentialId' => $passkey->get('credential_id'),
            'credential' => $assertion,
        ]);
    }

    /**
     * Every cookie the last response set, by name: the remember-me cookie is a
     * raw `Set-Cookie` header, the device cookie sits in the cookie collection.
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

        return array_filter($cookies, fn(string $value): bool => $value !== '' && $value !== 'deleted');
    }

    private function authCookieName(): string
    {
        return (string)Configure::read('Security.cookieAuthName');
    }

    public function testAPasskeyCompletesThePendingLogin(): void
    {
        $key = $this->newKey();
        $passkey = $this->registerPasskey($key);

        $challenge = $this->passwordStepAndChallenge();
        $this->answer($passkey, $this->assertion($key, $challenge, counter: 7));

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['ok'] ?? false);
        $this->assertTrue($this->_controller->CurrentUser->isLoggedIn());
        $this->assertSame(self::USER_ID, (int)$this->_controller->CurrentUser->getId());

        // The counter is what makes a cloned authenticator detectable, so it
        // has to be written back, along with the time of use.
        $stored = $this->Passkeys->get($passkey->get('id'));
        $this->assertSame(7, (int)$stored->get('sign_count'));
        $this->assertNotNull($stored->get('last_used_at'));
        $this->assertSame(7, $this->Passkeys->toRecord($stored)->counter);
    }

    /**
     * Without the box ticked, a passkey login is a session and nothing more.
     *
     * @return void
     */
    public function testAPasskeyLoginWithoutRememberMeHandsOutNoPersistentCookie(): void
    {
        $key = $this->newKey();
        $passkey = $this->registerPasskey($key);

        $challenge = $this->passwordStepAndChallenge(rememberMe: false);
        $this->answer($passkey, $this->assertion($key, $challenge));

        $this->assertResponseOk();
        $cookies = $this->issuedCookies();
        $this->assertArrayNotHasKey($this->authCookieName(), $cookies);
        $this->assertArrayNotHasKey($this->authCookieName() . '-2FA', $cookies);
        $this->assertSame(0, $this->Devices->find()->where(['user_id' => self::USER_ID])->count());
    }

    /**
     * "Stay signed in" is asked on the password form and can only be honoured
     * a step later. The code field carries it across; the passkey has to as
     * well, or the same account keeps its login with one factor and loses it
     * with the other.
     *
     * @return void
     */
    public function testAPasskeyLoginHonoursRememberMe(): void
    {
        $key = $this->newKey();
        $passkey = $this->registerPasskey($key);

        $challenge = $this->passwordStepAndChallenge(rememberMe: true);
        $this->answer($passkey, $this->assertion($key, $challenge));

        $this->assertResponseOk();
        $cookies = $this->issuedCookies();
        $this->assertArrayHasKey($this->authCookieName(), $cookies);
        $this->assertArrayHasKey($this->authCookieName() . '-2FA', $cookies);
        $this->assertSame(1, $this->Devices->find()->where(['user_id' => self::USER_ID])->count());
    }

    /**
     * The control for everything above: the same ceremony, signed by a key
     * that is not the registered one, must fail. If this passed, the other
     * tests would be proving that nothing is verified.
     *
     * @return void
     */
    public function testASignatureFromAnotherKeyIsRefused(): void
    {
        $passkey = $this->registerPasskey($this->newKey());

        $challenge = $this->passwordStepAndChallenge(rememberMe: true);
        $this->answer($passkey, $this->assertion($this->newKey(), $challenge));

        $this->assertResponseCode(400);
        $this->assertArrayNotHasKey('Auth', $_SESSION);
        $this->assertSame([], $this->issuedCookies());
        $this->assertSame(0, (int)$this->Passkeys->get($passkey->get('id'))->get('sign_count'));
    }

    /**
     * A valid answer is valid once. Sent again — a captured ceremony — it
     * meets a challenge that is already spent.
     *
     * @return void
     */
    public function testAValidAssertionCannotBeReplayed(): void
    {
        $key = $this->newKey();
        $passkey = $this->registerPasskey($key);

        $challenge = $this->passwordStepAndChallenge();
        $assertion = $this->assertion($key, $challenge);
        $this->answer($passkey, $assertion);
        $this->assertResponseOk();

        // Somebody holding the captured answer, with a pending login of the
        // same account. The challenge it answers was consumed above.
        $this->session(['Saito' => []]);
        $this->mockSecurity();
        $this->post('/login', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
        $this->answer($passkey, $assertion);

        $this->assertResponseCode(400);
    }

    /**
     * An answer to somebody else's challenge is no answer: the challenge is
     * inside what was signed.
     *
     * @return void
     */
    public function testAnAssertionForADifferentChallengeIsRefused(): void
    {
        $key = $this->newKey();
        $passkey = $this->registerPasskey($key);

        $this->passwordStepAndChallenge();
        $this->answer($passkey, $this->assertion($key, $this->base64url(random_bytes(32))));

        $this->assertResponseCode(400);
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }
}
