<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use OTPHP\TOTP;

/**
 * The console reset (#87).
 *
 * The way back into a forum whose only administrator locked themselves out.
 * It matters that this works when nothing else does, so the test is about the
 * outcome — every credential gone, the account still there — rather than the
 * wording it prints.
 */
class TwoFactorResetCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public array $fixtures = [
        'app.Setting',
        'app.User',
        'app.TwoFactorCredential',
        'app.TwoFactorRecoveryCode',
        'app.TwoFactorTrustedDevice',
        'app.WebauthnCredential',
    ];

    /** Ulysses. */
    private const USER_ID = 3;
    private const USERNAME = 'Ulysses';

    /**
     * Give the account a full set of credentials.
     *
     * @return void
     */
    private function enrol(): void
    {
        $locator = TableRegistry::getTableLocator();
        $credentials = $locator->get('TwoFactorCredentials');
        $secret = $credentials->beginEnrolment(self::USER_ID);
        $credentials->confirmEnrolment(self::USER_ID, TOTP::createFromSecret($secret)->now());
        $locator->get('TwoFactorRecoveryCodes')->issueFor(self::USER_ID);
        $locator->get('TwoFactorTrustedDevices')->issueFor(self::USER_ID);
        $locator->get('WebauthnCredentials')->getConnection()->insert('webauthn_credentials', [
            'user_id' => self::USER_ID,
            'credential_id' => 'console-test',
            'credential' => '{}',
            'sign_count' => 0,
        ]);
    }

    /**
     * @param string $table table alias
     * @return int rows for the test account
     */
    private function rows(string $table): int
    {
        return TableRegistry::getTableLocator()->get($table)
            ->find()->where(['user_id' => self::USER_ID])->count();
    }

    public function testItClearsEveryCredentialAndLeavesTheAccount(): void
    {
        $this->enrol();
        foreach (['TwoFactorCredentials', 'TwoFactorRecoveryCodes',
                  'TwoFactorTrustedDevices', 'WebauthnCredentials'] as $table) {
            $this->assertGreaterThan(0, $this->rows($table), "$table was not seeded");
        }

        $this->exec('two_factor_reset ' . self::USERNAME);

        $this->assertExitSuccess();
        foreach (['TwoFactorCredentials', 'TwoFactorRecoveryCodes',
                  'TwoFactorTrustedDevices', 'WebauthnCredentials'] as $table) {
            $this->assertSame(0, $this->rows($table), "$table survived the console reset");
        }
        $this->assertNotNull(
            TableRegistry::getTableLocator()->get('Users')->get(self::USER_ID),
            'a reset is not a deletion',
        );
    }

    /**
     * An account that never had a second factor is not an error case.
     *
     * Somebody locked out for a different reason may well try this first, and
     * "there was nothing to reset" is the useful answer — it tells them the
     * second factor is not what is keeping them out. Failing here would send
     * them looking for a problem with the command instead.
     *
     * @return void
     */
    public function testAnAccountWithoutASecondFactorIsNotAnError(): void
    {
        $this->exec('two_factor_reset ' . self::USERNAME);

        $this->assertExitSuccess();
        $this->assertOutputContains('nothing was in the way');
    }

    public function testAnUnknownAccountFails(): void
    {
        $this->exec('two_factor_reset does-not-exist');

        $this->assertExitError();
    }

    /**
     * One account's reset must not touch another's — the command takes a name,
     * and a name is easy to get wrong when you are locked out and in a hurry.
     *
     * @return void
     */
    public function testItResetsOnlyTheNamedAccount(): void
    {
        $this->enrol();
        $other = 1;
        TableRegistry::getTableLocator()->get('TwoFactorTrustedDevices')->issueFor($other);

        $this->exec('two_factor_reset ' . self::USERNAME);

        $this->assertExitSuccess();
        $this->assertSame(
            1,
            TableRegistry::getTableLocator()->get('TwoFactorTrustedDevices')
                ->find()->where(['user_id' => $other])->count(),
            'another account\'s credentials must survive',
        );
    }
}
