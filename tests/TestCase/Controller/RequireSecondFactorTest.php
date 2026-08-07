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

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use OTPHP\TOTP;
use Saito\Test\IntegrationTestCase;

/**
 * Requiring a second factor of moderators and administrators (#87).
 *
 * Most of what is asserted here is not "the gate closes" but "the gate leaves a
 * way out". A gate on a login is a lock, and a lock without a key is how an
 * operator loses their own forum: switch the setting on with the authenticator
 * app on a phone in another room, and every request — including the one that
 * would set the second factor up — bounces to a page telling you to set the
 * second factor up.
 *
 * So the exemptions are tested one by one, and each of them is load-bearing.
 */
class RequireSecondFactorTest extends IntegrationTestCase
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

    /** Alice, an administrator. */
    private const ADMIN_ID = 1;

    /** Mitch, a moderator. */
    private const MOD_ID = 2;

    /** Ulysses, an ordinary member. */
    private const MEMBER_ID = 3;

    /**
     * @param string $from off|mod|admin
     * @return void
     */
    private function requireFrom(string $from): void
    {
        Configure::write('Saito.Settings.2fa_required_from_role', $from);
    }

    /**
     * @param int $userId account
     * @return void
     */
    private function enrol(int $userId): void
    {
        $credentials = TableRegistry::getTableLocator()->get('TwoFactorCredentials');
        $secret = $credentials->beginEnrolment($userId);
        $credentials->confirmEnrolment($userId, TOTP::createFromSecret($secret)->now());
    }

    /**
     * @return void
     */
    private function assertGated(): void
    {
        // The rendered text, not the key: the suite renders the English
        // catalogue, so asserting on `user.2fa.required.t` looks for something
        // that is never in the output — which is how this first reported a
        // working gate as broken.
        $this->assertResponseContains(
            'Two-factor authentication is required',
            'the gate page should have been served instead of the request',
        );
    }

    /**
     * @return void
     */
    private function assertNotGated(): void
    {
        $this->assertResponseNotContains('Two-factor authentication is required');
    }

    /**
     * Off by default, so upgrading changes nothing for anybody.
     *
     * @return void
     */
    public function testNothingHappensWhileTheSettingIsOff(): void
    {
        $this->requireFrom('off');
        $this->_loginUser(self::ADMIN_ID);
        $this->get('/entries/htmx-index');

        $this->assertResponseOk();
        $this->assertNotGated();
    }

    public function testAnAdministratorWithoutASecondFactorIsSentToSetOneUp(): void
    {
        $this->requireFrom('admin');
        $this->_loginUser(self::ADMIN_ID);
        $this->get('/entries/htmx-index');

        $this->assertGated();
    }

    public function testAnAdministratorWhoHasOneIsLetThrough(): void
    {
        $this->requireFrom('admin');
        $this->enrol(self::ADMIN_ID);
        $this->_loginUser(self::ADMIN_ID);
        $this->get('/entries/htmx-index');

        $this->assertResponseOk();
        $this->assertNotGated();
    }

    /**
     * The asymmetry is the whole point: the cost of a compromised member
     * account is one member, the cost of a compromised administrator account is
     * the forum.
     *
     * @return void
     */
    public function testAnOrdinaryMemberIsNeverGated(): void
    {
        $this->requireFrom('mod');
        $this->_loginUser(self::MEMBER_ID);
        $this->get('/entries/htmx-index');

        $this->assertResponseOk();
        $this->assertNotGated();
    }

    /**
     * `admin` must not catch moderators, or the setting would mean something
     * other than it says.
     *
     * @return void
     */
    public function testAdminOnlyDoesNotCatchModerators(): void
    {
        $this->requireFrom('admin');
        $this->_loginUser(self::MOD_ID);
        $this->get('/entries/htmx-index');

        $this->assertResponseOk();
        $this->assertNotGated();
    }

    /**
     * …while `mod` means "moderator and above", so it does catch an
     * administrator. Requiring it of moderators while exempting the people who
     * can reset them would be the wrong way round.
     *
     * @return void
     */
    public function testModIncludesAdministrators(): void
    {
        $this->requireFrom('mod');
        $this->_loginUser(self::MOD_ID);
        $this->get('/entries/htmx-index');
        $this->assertGated();

        $this->_logoutUser();
        $this->_loginUser(self::ADMIN_ID);
        $this->get('/entries/htmx-index');
        $this->assertGated();
    }

    public function testAnonymousVisitorsAreUnaffected(): void
    {
        $this->requireFrom('mod');
        $this->get('/entries/htmx-index');

        $this->assertResponseOk();
        $this->assertNotGated();
    }

    /**
     * The way out. If the enrolment page were behind the gate, the gate would
     * send people to a page that sends them back — and an operator who turned
     * the setting on would have locked themselves out of their own forum with
     * no interface left to undo it.
     *
     * @return void
     */
    public function testTheEnrolmentPageStaysReachable(): void
    {
        $this->requireFrom('admin');
        $this->_loginUser(self::ADMIN_ID);
        $this->get('/users/htmx-two-factor');

        $this->assertResponseOk();
        $this->assertNotGated();
    }

    /**
     * Enrolment posts back to itself — start, confirm, recovery codes — so the
     * POST has to survive the gate as well as the GET.
     *
     * @return void
     */
    public function testEnrolmentCanActuallyBeCompletedThroughTheGate(): void
    {
        $this->requireFrom('admin');
        $this->_loginUser(self::ADMIN_ID);

        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', ['do' => 'start']);
        $this->assertNotGated();

        $secret = (string)($_SESSION['Saito']['2faEnrolSecret'] ?? '');
        $this->assertNotEmpty($secret, 'enrolment must be able to start');

        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
        $this->mockSecurity();
        $this->post('/users/htmx-two-factor', [
            'do' => 'confirm',
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);

        $this->assertTrue(
            TableRegistry::getTableLocator()->get('TwoFactorCredentials')
                ->isEnabledFor(self::ADMIN_ID),
            'it must be possible to finish enrolling from behind the gate',
        );
    }

    /**
     * The other way out: somebody who cannot enrol on this device must not be
     * trapped in a forum they cannot leave.
     *
     * @return void
     */
    public function testLoggingOutStaysReachable(): void
    {
        $this->requireFrom('admin');
        $this->_loginUser(self::ADMIN_ID);
        $this->get('/logout');

        $this->assertNotGated();
        $this->assertRedirect();
    }

    /**
     * The admin area is gated like everything else. An administrator who has
     * not enrolled has no business in the backend until they have — that is the
     * account the setting exists to protect.
     *
     * @return void
     */
    public function testTheAdminBackendIsGatedToo(): void
    {
        $this->requireFrom('admin');
        $this->_loginUser(self::ADMIN_ID);
        $this->get('/admin/settings');

        $this->assertGated();
    }
}
