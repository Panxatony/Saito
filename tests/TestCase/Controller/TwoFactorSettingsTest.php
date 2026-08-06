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
use App\Model\Table\TwoFactorRecoveryCodesTable;
use Cake\ORM\TableRegistry;
use OTPHP\TOTP;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;

/**
 * Enrolment in the profile, and the administrator's escape hatch (#62).
 *
 * The weight is on the two ways this could hurt somebody: enrolling must not be
 * able to lock a member out half-way, and switching it off — for oneself or for
 * somebody else — must not be possible from a session alone.
 */
class TwoFactorSettingsTest extends IntegrationTestCase
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

    /** Ulysses; 'test' is the password the other login tests use. */
    private const USER_ID = 3;
    private const PASSWORD = 'test';

    public function setUp(): void
    {
        parent::setUp();
        /** @var TwoFactorCredentialsTable $c */
        $c = TableRegistry::getTableLocator()->get('TwoFactorCredentials');
        $this->Credentials = $c;
        /** @var TwoFactorRecoveryCodesTable $r */
        $r = TableRegistry::getTableLocator()->get('TwoFactorRecoveryCodes');
        $this->Codes = $r;
    }

    /**
     * Walk the profile flow the way a member does.
     *
     * @return void
     */
    public function testEnrolmentTakesTwoStepsAndOnlyThenIssuesRecoveryCodes(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();

        // Step one: a secret, shown as a QR code…
        $this->post('/users/htmx-two-factor', ['do' => 'start']);
        $this->assertResponseOk();
        $this->assertResponseContains('<svg', 'the QR code is rendered here, not fetched from anywhere');
        $this->assertNotNull($this->Credentials->pendingFor(self::USER_ID));

        // …and until it is confirmed the account is untouched: a half-finished
        // enrolment must never gate a login.
        $this->assertFalse($this->Credentials->isEnabledFor(self::USER_ID));
        $this->assertSame(0, $this->Codes->remainingFor(self::USER_ID));

        // Step two: prove a code from it.
        $secret = (string)$this->viewVariable('secret');
        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', [
            'do' => 'confirm',
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);

        $this->assertResponseOk();
        $this->assertTrue($this->Credentials->isEnabledFor(self::USER_ID));
        // Recovery codes exist now, and are shown this once.
        $this->assertSame(
            TwoFactorRecoveryCodesTable::CODE_COUNT,
            $this->Codes->remainingFor(self::USER_ID),
        );
        $this->assertNotEmpty($this->viewVariable('recoveryCodes'));
    }

    public function testAWrongCodeDoesNotFinishEnrolment(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'start']);

        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'confirm', 'code' => '000000']);

        $this->assertFalse($this->Credentials->isEnabledFor(self::USER_ID));
        $this->assertSame(0, $this->Codes->remainingFor(self::USER_ID));
    }

    /**
     * Switching it off weakens the account, so a session alone must not do it.
     *
     * @return void
     */
    public function testSwitchingOffNeedsThePassword(): void
    {
        $secret = $this->Credentials->beginEnrolment(self::USER_ID);
        $this->Credentials->confirmEnrolment(self::USER_ID, TOTP::createFromSecret($secret)->now());
        $this->_loginUser(self::USER_ID);

        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'disable', 'password' => 'not-my-password']);
        $this->assertTrue(
            $this->Credentials->isEnabledFor(self::USER_ID),
            'a wrong password must not switch the second factor off',
        );

        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'disable', 'password' => self::PASSWORD]);
        $this->assertFalse($this->Credentials->isEnabledFor(self::USER_ID));
    }

    /**
     * Fresh recovery codes are new credentials, so they cost the password too.
     *
     * @return void
     */
    public function testNewRecoveryCodesNeedThePassword(): void
    {
        $secret = $this->Credentials->beginEnrolment(self::USER_ID);
        $this->Credentials->confirmEnrolment(self::USER_ID, TOTP::createFromSecret($secret)->now());
        $this->Codes->issueFor(self::USER_ID);
        $this->_loginUser(self::USER_ID);

        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'newCodes', 'password' => 'not-my-password']);
        $this->assertNull($this->viewVariable('recoveryCodes'));

        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'newCodes', 'password' => self::PASSWORD]);
        $this->assertNotEmpty($this->viewVariable('recoveryCodes'));
    }

    /**
     * The administrator's escape hatch, for a member who lost both the device
     * and the recovery codes — guarded by the admin's own password.
     *
     * @return void
     */
    public function testAdminResetClearsTheSecondFactorButNeedsTheAdminPassword(): void
    {
        $secret = $this->Credentials->beginEnrolment(self::USER_ID);
        $this->Credentials->confirmEnrolment(self::USER_ID, TOTP::createFromSecret($secret)->now());
        $this->Codes->issueFor(self::USER_ID);

        // User 1 is the fixture's admin.
        $this->_loginUser(1);
        $this->mockSecurity();
        $this->post('/admin/users/twoFactorReset/' . self::USER_ID, ['confirm_password' => 'wrong']);
        $this->assertTrue(
            $this->Credentials->isEnabledFor(self::USER_ID),
            'a wrong admin password must change nothing',
        );

        $this->mockSecurity();
        $this->post('/admin/users/twoFactorReset/' . self::USER_ID, ['confirm_password' => self::PASSWORD]);

        $this->assertFalse($this->Credentials->isEnabledFor(self::USER_ID));
        $this->assertSame(0, $this->Codes->remainingFor(self::USER_ID));
    }

    /**
     * The button in the user list must point at a URL that actually resolves.
     *
     * The admin plugin routes with InflectedRoute, which underscores some parts
     * of a URL and not others, so a camelCase action is exactly where a
     * generated link and the route that has to parse it can disagree. That kind
     * of mismatch passes every test that calls the action directly and gives a
     * 404 to the one person who clicks the button.
     *
     * @return void
     */
    public function testTheAdminButtonLinksSomewhereThatExists(): void
    {
        $this->_loginUser(1);
        $this->get('/admin/users');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $this->assertMatchesRegularExpression(
            '#href="[^"]*users/[^"/]*[Tt]wo[_]?[Ff]actor[_]?[Rr]eset/\d+#',
            $body,
            'the user list must offer the reset',
        );
        preg_match('#href="([^"]*[Tt]wo[_]?[Ff]actor[_]?[Rr]eset/\d+)"#', $body, $m);
        $href = html_entity_decode($m[1]);

        // Follow it the way a browser would.
        $this->get($href);
        $this->assertResponseOk();
        // The page itself, whichever state that member is in — a link that
        // resolves is the point here, not what it finds.
        $this->assertResponseContains('users twoFactorReset');
    }

    /**
     * And an ordinary member cannot reach it for somebody else.
     *
     * @return void
     */
    public function testAnOrdinaryMemberCannotResetSomebodyElsesSecondFactor(): void
    {
        $secret = $this->Credentials->beginEnrolment(1);
        $this->Credentials->confirmEnrolment(1, TOTP::createFromSecret($secret)->now());

        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();

        // Refused outright, which is the guard doing its job.
        $this->expectException(SaitoForbiddenException::class);
        $this->post('/admin/users/twoFactorReset/1', ['confirm_password' => self::PASSWORD]);

        $this->assertTrue($this->Credentials->isEnabledFor(1));
    }
}
