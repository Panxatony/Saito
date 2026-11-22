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

use App\Model\Table\WebauthnCredentialsTable;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use OpenSSLAsymmetricKey;
use OTPHP\TOTP;
use Saito\Test\IntegrationTestCase;

/**
 * A passkey registration that succeeds.
 *
 * {@see WebauthnTest} covers what registration refuses before the ceremony is
 * looked at (no second factor, no password, `{}`), and {@see WebauthnLoginTest}
 * logs in with a credential it writes into the table itself. Nothing drove the
 * ceremony a member goes through when adding a device, so nothing showed that
 * what it stores is a credential the login accepts.
 *
 * The device is {@see SoftwareAuthenticatorTrait}. Its attestation carries no
 * signature (Saito asks for none), so what the server can hold it to is the
 * challenge, the origin and the flags. Each of those has a test here that
 * changes only that and is refused.
 */
class WebauthnRegistrationTest extends IntegrationTestCase
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
    private mixed $fullBaseUrl = null;

    public function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        /** @var WebauthnCredentialsTable $passkeys */
        $passkeys = TableRegistry::getTableLocator()->get('WebauthnCredentials');
        $this->Passkeys = $passkeys;

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
     * Ask for registration options as the signed-in member.
     *
     * @return string the challenge the server issued (base64url)
     */
    private function registrationChallenge(): string
    {
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->get('/users/webauthn-register-options');
        $this->assertResponseOk();
        $options = json_decode((string)$this->_response->getBody(), true);
        // The harness builds each request's session from what the test seeded,
        // so carry across what the server just wrote. A browser uses a cookie.
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);

        return (string)$options['challenge'];
    }

    private function register(string $attestation, string $label = 'My laptop'): void
    {
        $this->mockSecurity();
        $this->post('/users/webauthn-register', [
            'credential' => $attestation,
            'label' => $label,
            'password' => self::PASSWORD,
        ]);
    }

    private function attestation(OpenSSLAsymmetricKey $key, string $challenge): string
    {
        return $this->attestationFor($key, $challenge, self::RAW_ID, self::ORIGIN);
    }

    private function assertRefusedAndNothingStored(): void
    {
        $this->assertResponseCode(400);
        $this->assertSame(0, $this->Passkeys->find()->count());
    }

    public function testADeviceIsRegistered(): void
    {
        $key = $this->newKey();

        $this->register($this->attestation($key, $this->registrationChallenge()));

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($body['ok'] ?? false);

        $stored = $this->Passkeys->find()->all()->toList();
        $this->assertCount(1, $stored);
        $this->assertSame(self::USER_ID, (int)$stored[0]->get('user_id'));
        $this->assertSame('My laptop', $stored[0]->get('label'));
        $this->assertSame(0, (int)$stored[0]->get('sign_count'));

        // What is kept is the device's id and its key, not something the
        // server made up along the way.
        $record = $this->Passkeys->toRecord($stored[0]);
        $this->assertSame(self::RAW_ID, $record->publicKeyCredentialId);
        $this->assertSame($this->coseKey($key), $record->credentialPublicKey);
    }

    /**
     * The point of registering: the stored credential is one the login takes,
     * from the key that was registered and from no other.
     *
     * @return void
     */
    public function testTheRegisteredDeviceCompletesALogin(): void
    {
        $key = $this->newKey();
        $this->register($this->attestation($key, $this->registrationChallenge()));
        $this->assertResponseOk();
        $passkey = $this->Passkeys->find()->firstOrFail();

        foreach ([[$this->newKey(), 400, false], [$key, 200, true]] as [$signingKey, $status, $loggedIn]) {
            $this->_logoutUser();
            $this->session(['Auth' => null, 'Saito' => null]);
            $this->mockSecurity();
            $this->post('/login', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
            $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
            $this->get('/users/webauthn-login-options');
            $this->assertResponseOk();
            $options = json_decode((string)$this->_response->getBody(), true);
            $this->session(['Saito' => $_SESSION['Saito'] ?? []]);

            $this->mockSecurity();
            $this->post('/users/webauthn-login', [
                'credentialId' => $passkey->get('credential_id'),
                'credential' => $this->assertionFor(
                    $signingKey,
                    (string)$options['challenge'],
                    self::RAW_ID,
                    self::USER_ID,
                    self::ORIGIN,
                ),
            ]);

            $this->assertResponseCode($status);
            $this->assertSame($loggedIn, $this->_controller->CurrentUser->isLoggedIn());
        }
    }

    /**
     * A challenge is good for one answer. Otherwise a captured registration
     * could be sent again.
     *
     * @return void
     */
    public function testARegistrationCannotBeReplayed(): void
    {
        $attestation = $this->attestation($this->newKey(), $this->registrationChallenge());
        $this->register($attestation);
        $this->assertResponseOk();

        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
        $this->register($attestation);

        $this->assertResponseCode(400);
        $this->assertSame(1, $this->Passkeys->find()->count());
    }

    public function testAnAnswerToAnotherChallengeIsRefused(): void
    {
        $this->registrationChallenge();

        $this->register($this->attestation($this->newKey(), $this->base64url(random_bytes(32))));

        $this->assertRefusedAndNothingStored();
    }

    /**
     * The origin is what makes a passkey unphishable: a ceremony that ran on
     * another site's page must not register here.
     *
     * @return void
     */
    public function testACeremonyFromAnotherOriginIsRefused(): void
    {
        $challenge = $this->registrationChallenge();

        $this->register(
            $this->attestationFor($this->newKey(), $challenge, self::RAW_ID, 'https://forum.example.com'),
        );

        $this->assertRefusedAndNothingStored();
    }

    /**
     * Saito requires user verification. A device that only noticed somebody
     * was there (flags 0x41, without the verified bit) is something the
     * member has, not a factor they were checked for.
     *
     * @return void
     */
    public function testADeviceThatDidNotVerifyTheUserIsRefused(): void
    {
        $challenge = $this->registrationChallenge();

        $this->register(
            $this->attestationFor($this->newKey(), $challenge, self::RAW_ID, self::ORIGIN, flags: 0x41),
        );

        $this->assertRefusedAndNothingStored();
    }
}
