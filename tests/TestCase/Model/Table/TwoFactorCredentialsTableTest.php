<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TwoFactorCredentialsTable;
use App\Model\Table\TwoFactorRecoveryCodesTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use OTPHP\TOTP;

/**
 * The second factor's storage and rules.
 *
 * Weighted towards the properties that make it a second *factor* rather than a
 * formality: the secret is not readable from the database, an unfinished
 * enrolment cannot gate a login, and a recovery code works exactly once.
 */
class TwoFactorCredentialsTableTest extends TestCase
{
    public array $fixtures = [
        'app.Setting',
        'app.User',
        'app.TwoFactorCredential',
        'app.TwoFactorRecoveryCode',
    ];

    private TwoFactorCredentialsTable $Credentials;
    private TwoFactorRecoveryCodesTable $Codes;

    public function setUp(): void
    {
        parent::setUp();
        /** @var TwoFactorCredentialsTable $credentials */
        $credentials = TableRegistry::getTableLocator()->get('TwoFactorCredentials');
        $this->Credentials = $credentials;
        /** @var TwoFactorRecoveryCodesTable $codes */
        $codes = TableRegistry::getTableLocator()->get('TwoFactorRecoveryCodes');
        $this->Codes = $codes;
    }

    /**
     * @param string $secret the enrolment secret
     * @return string a code valid right now
     */
    private function codeFor(string $secret): string
    {
        return TOTP::createFromSecret($secret)->now();
    }

    public function testEnrolmentIsNotLiveUntilItIsConfirmed(): void
    {
        $secret = $this->Credentials->beginEnrolment(1);

        // The row exists…
        $this->assertNotNull($this->Credentials->pendingFor(1));
        // …but a half-finished enrolment must not lock anybody out.
        $this->assertFalse($this->Credentials->isEnabledFor(1));
        $this->assertFalse($this->Credentials->verifyCode(1, $this->codeFor($secret)));

        $this->assertTrue($this->Credentials->confirmEnrolment(1, $this->codeFor($secret)));
        $this->assertTrue($this->Credentials->isEnabledFor(1));
    }

    public function testAWrongCodeNeitherConfirmsNorVerifies(): void
    {
        $secret = $this->Credentials->beginEnrolment(1);

        $this->assertFalse($this->Credentials->confirmEnrolment(1, '000000'));
        $this->assertFalse($this->Credentials->isEnabledFor(1));

        $this->Credentials->confirmEnrolment(1, $this->codeFor($secret));
        $this->assertFalse($this->Credentials->verifyCode(1, '000000'));
    }

    /**
     * The reason the column is encrypted: a database read must not yield
     * something an attacker can turn into codes.
     *
     * @return void
     */
    public function testTheSecretIsNotStoredInPlaintext(): void
    {
        $secret = $this->Credentials->beginEnrolment(1);

        $stored = (string)$this->Credentials->find()
            ->where(['user_id' => 1])->first()->get('secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, $stored);
        // …and it still round-trips, so the encryption is real rather than a
        // one-way mangling that happens to differ.
        $this->Credentials->confirmEnrolment(1, $this->codeFor($secret));
        $this->assertTrue($this->Credentials->verifyCode(1, $this->codeFor($secret)));
    }

    public function testRestartingEnrolmentInvalidatesTheEarlierSecret(): void
    {
        $first = $this->Credentials->beginEnrolment(1);
        $second = $this->Credentials->beginEnrolment(1);

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $this->Credentials->find()->where(['user_id' => 1])->count());

        // A QR code scanned from the abandoned attempt must not work.
        $this->assertFalse($this->Credentials->confirmEnrolment(1, $this->codeFor($first)));
        $this->assertTrue($this->Credentials->confirmEnrolment(1, $this->codeFor($second)));
    }

    public function testDisablingRemovesTheCredential(): void
    {
        $secret = $this->Credentials->beginEnrolment(1);
        $this->Credentials->confirmEnrolment(1, $this->codeFor($secret));

        $this->Credentials->disableFor(1);

        $this->assertFalse($this->Credentials->isEnabledFor(1));
        $this->assertSame(0, $this->Credentials->find()->where(['user_id' => 1])->count());
    }

    public function testTheProvisioningUriCarriesTheForumAndTheAccount(): void
    {
        $secret = $this->Credentials->beginEnrolment(1);
        $uri = $this->Credentials->provisioningUri($secret, 'alice');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('alice', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
    }

    public function testARecoveryCodeWorksExactlyOnce(): void
    {
        $codes = $this->Codes->issueFor(1);
        $this->assertCount(TwoFactorRecoveryCodesTable::CODE_COUNT, $codes);
        $this->assertSame(TwoFactorRecoveryCodesTable::CODE_COUNT, $this->Codes->remainingFor(1));

        $this->assertTrue($this->Codes->consume(1, $codes[0]));
        $this->assertFalse($this->Codes->consume(1, $codes[0]), 'a spent code must not work twice');
        $this->assertSame(TwoFactorRecoveryCodesTable::CODE_COUNT - 1, $this->Codes->remainingFor(1));

        // A different one still does.
        $this->assertTrue($this->Codes->consume(1, $codes[1]));
    }

    public function testRecoveryCodesAreNotStoredInPlaintextAndAreScopedToTheAccount(): void
    {
        $codes = $this->Codes->issueFor(1);

        $stored = $this->Codes->find()->where(['user_id' => 1])->all()->extract('code_hash')->toList();
        foreach ($stored as $hash) {
            $this->assertNotContains($codes[0], [$hash]);
            $this->assertStringNotContainsString($codes[0], (string)$hash);
        }

        // Another member's code is not a key to this account.
        $this->assertFalse($this->Codes->consume(2, $codes[0]));
        $this->assertTrue($this->Codes->consume(1, $codes[0]));
    }
}
