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
use App\Model\Table\WebauthnCredentialsTable;
use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;
use OTPHP\TOTP;
use Saito\Test\IntegrationTestCase;
use Saito\User\Auth\WebauthnService;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Passkeys as a second factor (#81).
 *
 * What is *not* tested here is the cryptography: whether a signature verifies
 * is `web-auth/webauthn-lib`'s job, it has its own suite, and a mock ceremony
 * that satisfies our own assertions would prove nothing about a real browser.
 *
 * What is tested is everything we decided ourselves — the gates around the
 * library. Those are where a mistake would be ours, and every one of them is a
 * way past a second factor if it is wrong: reaching the ceremony without a
 * pending login, using somebody else's credential, replaying a spent challenge,
 * guessing without a budget, or keeping a passkey after the factor it belongs
 * to was switched off.
 */
class WebauthnTest extends IntegrationTestCase
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
        'app.WebauthnCredential',
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    private TwoFactorCredentialsTable $Credentials;
    private WebauthnCredentialsTable $Passkeys;
    private string $secret = '';

    /** Ulysses, with the password the other login tests use. */
    private const USER_ID = 3;
    private const USERNAME = 'Ulysses';
    private const PASSWORD = 'test';
    private const OTHER_USER_ID = 1;

    public function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        /** @var TwoFactorCredentialsTable $credentials */
        $credentials = TableRegistry::getTableLocator()->get('TwoFactorCredentials');
        $this->Credentials = $credentials;
        /** @var WebauthnCredentialsTable $passkeys */
        $passkeys = TableRegistry::getTableLocator()->get('WebauthnCredentials');
        $this->Passkeys = $passkeys;
    }

    private function enrol(): void
    {
        $this->secret = $this->Credentials->beginEnrolment(self::USER_ID);
        $this->Credentials->confirmEnrolment(self::USER_ID, TOTP::createFromSecret($this->secret)->now());
    }

    /**
     * A stored credential, built directly rather than through a ceremony.
     *
     * Enough to exercise storage, lookup and scoping. It cannot produce a valid
     * signature and is not meant to — the tests that use it are about which
     * rows are reachable from where.
     *
     * @param int $userId owner
     * @param string $rawId raw credential id
     * @return \App\Model\Entity\WebauthnCredential
     */
    private function seedCredential(int $userId, string $rawId = 'credential-one')
    {
        $record = CredentialRecord::create(
            $rawId,
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'not-a-real-key',
            (new WebauthnService())->userHandle($userId),
            0,
        );

        return $this->Passkeys->store($userId, $record, 'Test device');
    }

    /**
     * Walk the password step so a pending login exists, and carry the session.
     *
     * @return void
     */
    private function startPendingLogin(): void
    {
        $this->mockSecurity();
        $this->post('/login', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
    }

    /**
     * @return array<string, mixed> the decoded JSON body
     */
    private function json(): array
    {
        $body = (string)$this->_response->getBody();

        return json_decode($body, true) ?: [];
    }

    /**
     * A passkey is an addition to the second factor, never a way to acquire
     * one: it lives in a single machine's secure enclave, and without the
     * recovery codes that come with the code there is no way back from a lost
     * device.
     *
     * @return void
     */
    public function testAPasskeyCannotBeRegisteredBeforeTheSecondFactorIsOn(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->get('/users/webauthn-register-options');

        $this->assertResponseCode(409);
        $this->assertSame(0, $this->Passkeys->find()->count());
    }

    public function testRegistrationOptionsAreOfferedOnceTheSecondFactorIsOn(): void
    {
        $this->enrol();
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->get('/users/webauthn-register-options');

        $this->assertResponseOk();
        $options = $this->json();
        $this->assertNotEmpty($options['challenge']);
        // User verification is required, not preferred: without it this is
        // "something you have" and not a factor anybody was checked for.
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        // And the challenge is kept server-side, or it is not a challenge.
        $this->assertNotEmpty($_SESSION['Saito']['webauthnRegister'] ?? null);
    }

    /**
     * The allow-list goes out on every attempt, so it cannot be unbounded.
     *
     * @return void
     */
    public function testTheNumberOfDevicesIsCapped(): void
    {
        $this->enrol();
        for ($i = 0; $i < WebauthnCredentialsTable::MAX_CREDENTIALS; $i++) {
            $this->seedCredential(self::USER_ID, 'credential-' . $i);
        }

        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->get('/users/webauthn-register-options');

        $this->assertResponseCode(409);
    }

    /**
     * The challenge action is unauthenticated by necessity. What stands in for
     * an identity is the pending marker — so without one there is nothing to
     * confirm, and the ceremony must not even start.
     *
     * @return void
     */
    public function testTheLoginCeremonyRefusesWithoutAPendingLogin(): void
    {
        $this->enrol();
        $this->seedCredential(self::USER_ID);

        $this->get('/users/webauthn-login-options');
        $this->assertResponseCode(400);

        $this->mockSecurity();
        $this->post('/users/webauthn-login', ['credentialId' => 'x', 'credential' => '{}']);
        $this->assertResponseCode(400);
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }

    public function testTheLoginCeremonyOffersOnlyThisAccountsCredentials(): void
    {
        $this->enrol();
        $mine = $this->seedCredential(self::USER_ID, 'mine');
        $this->seedCredential(self::OTHER_USER_ID, 'theirs');

        $this->startPendingLogin();
        $this->get('/users/webauthn-login-options');

        $this->assertResponseOk();
        $options = $this->json();
        $ids = array_column($options['allowCredentials'] ?? [], 'id');
        $this->assertCount(1, $ids);
        $this->assertSame($mine->get('credential_id'), $ids[0]);
    }

    /**
     * Holding somebody else's credential id must get nowhere, even with a valid
     * pending login of one's own.
     *
     * The lookup is asserted directly, not only through the endpoint. Going by
     * the status code alone proved nothing: an unparseable credential answers
     * 400 as well, so the test stayed green with the account scoping removed
     * entirely — found by deleting the scoping and watching it pass.
     *
     * @return void
     */
    public function testAnotherAccountsCredentialIsNotAcceptedForThisLogin(): void
    {
        $this->enrol();
        $theirs = $this->seedCredential(self::OTHER_USER_ID, 'theirs');
        $id = (string)$theirs->get('credential_id');

        // The discriminating assertion: this id exists, and is invisible from
        // the other account.
        $this->assertNotNull($this->Passkeys->findForUser(self::OTHER_USER_ID, $id));
        $this->assertNull(
            $this->Passkeys->findForUser(self::USER_ID, $id),
            'one member must not be able to reach another member\'s credential',
        );

        $this->startPendingLogin();
        $this->get('/users/webauthn-login-options');
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);

        $this->mockSecurity();
        $this->post('/users/webauthn-login', ['credentialId' => $id, 'credential' => '{}']);

        $this->assertResponseCode(400);
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }

    /**
     * A challenge is a one-shot token. Spent — or simply never issued — it must
     * not be usable, or a captured ceremony could be replayed.
     *
     * @return void
     */
    public function testAnAssertionWithoutAnIssuedChallengeIsRefused(): void
    {
        $this->enrol();
        $mine = $this->seedCredential(self::USER_ID);

        $this->startPendingLogin();
        // Note: no call to webauthn-login-options, so no challenge was issued.
        $this->mockSecurity();
        $this->post('/users/webauthn-login', [
            'credentialId' => $mine->get('credential_id'),
            'credential' => '{}',
        ]);

        $this->assertResponseCode(400);
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }

    /**
     * A challenge older than its life is no longer redeemable.
     *
     * @return void
     */
    public function testAStaleChallengeIsRefused(): void
    {
        $this->enrol();
        $mine = $this->seedCredential(self::USER_ID);

        $this->startPendingLogin();
        $this->get('/users/webauthn-login-options');

        // Wind the stored challenge back past its life, leaving everything else
        // exactly as the server wrote it.
        $stored = $_SESSION['Saito']['webauthnLogin'];
        $stored['at'] = time() - WebauthnService::CHALLENGE_TTL - 1;
        $session = $_SESSION['Saito'];
        $session['webauthnLogin'] = $stored;
        $this->session(['Saito' => $session]);

        $this->mockSecurity();
        $this->post('/users/webauthn-login', [
            'credentialId' => $mine->get('credential_id'),
            'credential' => '{}',
        ]);

        $this->assertResponseCode(400);
        $this->assertArrayNotHasKey('Auth', $_SESSION);
    }

    /**
     * The same budget the code field has. The password is already spent by this
     * point, so without a throttle this is an endpoint somebody can sit and
     * hammer.
     *
     * @return void
     */
    public function testTheCeremonyIsThrottled(): void
    {
        $this->enrol();
        $mine = $this->seedCredential(self::USER_ID);
        $this->startPendingLogin();

        // A fresh challenge each round, which is the shape a real attempt has:
        // posting without one is refused before the attempt is even counted, so
        // a loop that skipped this would never reach the throttle — and an
        // earlier version of this test did exactly that and proved nothing.
        for ($i = 0; $i < 12; $i++) {
            $this->get('/users/webauthn-login-options');
            $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
            $this->mockSecurity();
            $this->post('/users/webauthn-login', [
                'credentialId' => $mine->get('credential_id'),
                'credential' => '{}',
            ]);
        }

        $this->get('/users/webauthn-login-options');
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
        $this->mockSecurity();
        $this->post('/users/webauthn-login', [
            'credentialId' => $mine->get('credential_id'),
            'credential' => '{}',
        ]);
        $this->assertResponseCode(429);
    }

    /**
     * Passkeys are registrations *of* the second factor. Switching it off has
     * to take them with it, or the account keeps credentials that still
     * complete a second step that no longer exists.
     *
     * @return void
     */
    public function testSwitchingTheSecondFactorOffRemovesEveryPasskey(): void
    {
        $this->enrol();
        $this->seedCredential(self::USER_ID);
        $this->assertSame(1, $this->Passkeys->find()->where(['user_id' => self::USER_ID])->count());

        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'disable', 'password' => self::PASSWORD]);

        $this->assertSame(0, $this->Passkeys->find()->where(['user_id' => self::USER_ID])->count());
    }

    /**
     * Removing one device leaves the others alone — and cannot reach into
     * another account's list.
     *
     * @return void
     */
    public function testRemovingADeviceIsScopedToTheAccount(): void
    {
        $this->enrol();
        $mine = $this->seedCredential(self::USER_ID, 'mine');
        $theirs = $this->seedCredential(self::OTHER_USER_ID, 'theirs');

        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', [
            'do' => 'removePasskey',
            'credentialId' => $theirs->get('id'),
        ]);

        $this->assertNotNull(
            $this->Passkeys->get($theirs->get('id')),
            'a member must not be able to remove somebody else\'s device by naming its id',
        );
        $this->assertNotNull($this->Passkeys->get($mine->get('id')));
    }

    /**
     * The stored record has to survive the round trip, or every login after the
     * first would fail against a mangled key.
     *
     * @return void
     */
    public function testAStoredCredentialRoundTrips(): void
    {
        $entity = $this->seedCredential(self::USER_ID, 'round-trip');
        $record = $this->Passkeys->toRecord($entity);

        $this->assertSame('round-trip', $record->publicKeyCredentialId);
        $this->assertSame((new WebauthnService())->userHandle(self::USER_ID), $record->userHandle);
        $this->assertSame(0, $record->counter);
    }

    /**
     * The user handle is stored on the authenticator and may be readable from
     * it, so it must not name the member to whoever picks the device up.
     *
     * @return void
     */
    public function testTheUserHandleDoesNotIdentifyTheMember(): void
    {
        $service = new WebauthnService();
        $handle = $service->userHandle(self::USER_ID);

        $this->assertSame(32, strlen($handle), 'a full SHA-256, not a truncation');

        // The account is not recoverable from the handle, asserted the only way
        // that means anything for a hash: it is a keyed derivation nobody can
        // recompute without this installation's salt, it differs per account,
        // and it never changes.
        //
        // Two earlier versions searched the handle for the member's id — first
        // in the hex, then in the raw bytes — and both were theatre. The second
        // was also flaky: a specific byte turns up in 32 random ones about one
        // time in eight, so it failed in CI while passing here. A property of
        // random data is not a property of the design.
        $this->assertNotSame(
            hash('sha256', (string)self::USER_ID, true),
            $handle,
            'a plain hash of the id would be recomputable by anyone',
        );
        $this->assertNotSame(
            hash_hmac('sha256', 'saito.webauthn.handle|' . self::USER_ID, 'not-the-salt', true),
            $handle,
            'the installation salt has to be the key, or the handle is portable between forums',
        );

        $this->assertNotSame($handle, $service->userHandle(self::OTHER_USER_ID));
        // Stable, or every login after the first would fail.
        $this->assertSame($handle, $service->userHandle(self::USER_ID));
    }
}
