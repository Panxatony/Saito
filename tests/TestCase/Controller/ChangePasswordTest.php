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
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\TableRegistry;
use Saito\Test\IntegrationTestCase;

/**
 * A member changing their own password.
 *
 * This had no test at all until the coverage sweep found it, which is an
 * uncomfortable place for a gap: it is the action that decides whether somebody
 * who borrowed a session can lock the owner out, and it grew a second job in
 * 8.4.0 — refreshing the session's password fingerprint, so that the change
 * logs out the account's *other* devices but not the one doing the changing.
 * Nothing was checking either half.
 */
class ChangePasswordTest extends IntegrationTestCase
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
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    /** Ulysses, with the password the login tests use. */
    private const USER_ID = 3;
    private const PASSWORD = 'test';
    private const NEW_PASSWORD = 'a-brand-new-secret';

    /**
     * @return string the stored hash
     */
    private function storedHash(): string
    {
        return (string)TableRegistry::getTableLocator()->get('Users')
            ->get(self::USER_ID)->get('password');
    }

    public function testChangingThePasswordNeedsTheOldOne(): void
    {
        $before = $this->storedHash();
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();

        $this->post('/users/htmx-change-password', [
            'password_old' => 'not-my-password',
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
        ]);

        $this->assertSame(
            $before,
            $this->storedHash(),
            'a borrowed session must not be able to change the password without knowing it',
        );
    }

    public function testAMismatchedConfirmationChangesNothing(): void
    {
        $before = $this->storedHash();
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();

        $this->post('/users/htmx-change-password', [
            'password_old' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirm' => 'something-else',
        ]);

        $this->assertSame($before, $this->storedHash());
    }

    public function testTheRightOldPasswordChangesIt(): void
    {
        $before = $this->storedHash();
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();

        $this->post('/users/htmx-change-password', [
            'password_old' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
        ]);

        $after = $this->storedHash();
        $this->assertNotSame($before, $after);
        $this->assertTrue(
            (new DefaultPasswordHasher())->check(self::NEW_PASSWORD, $after),
            'the new password has to actually verify',
        );
    }

    /**
     * The half that 8.4.0 added: the session doing the changing keeps its
     * fingerprint refreshed, so it stays logged in while every other session on
     * the account falls out of step and is dropped on its next request.
     *
     * Without this the member would log themselves out by changing their own
     * password — the sort of thing that looks like a bug in the login.
     *
     * @return void
     */
    public function testTheChangingSessionKeepsItsFingerprintAndStaysLoggedIn(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();

        $this->post('/users/htmx-change-password', [
            'password_old' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
        ]);

        // The fingerprint the session now carries is the *new* password's.
        $stamped = $_SESSION['Saito']['pwFingerprint'] ?? null;
        $this->assertNotNull($stamped, 'the session must be re-stamped, or it logs itself out');
        $this->assertSame(hash('sha256', $this->storedHash()), $stamped);

        // …and carrying it forward, the next request is still logged in.
        $this->session(['Saito' => $_SESSION['Saito'] ?? []]);
        $this->get('/users/htmx-edit');
        $this->assertResponseOk();
    }

    /**
     * The other side of the same mechanism: a session still holding the old
     * password's fingerprint is dropped. That is what makes a password change
     * end the sessions somebody else may be sitting in.
     *
     * @return void
     */
    public function testASessionWithTheOldFingerprintIsDropped(): void
    {
        $oldFingerprint = hash('sha256', $this->storedHash());

        $this->_loginUser(self::USER_ID);
        $this->mockSecurity();
        $this->post('/users/htmx-change-password', [
            'password_old' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirm' => self::NEW_PASSWORD,
        ]);

        // Another device: same account, fingerprint from before the change.
        $this->_loginUser(self::USER_ID);
        $this->session([AuthUserComponent::PW_FINGERPRINT_KEY => $oldFingerprint]);
        $this->get('/users/htmx-edit');

        $this->assertRedirectContains('/login');
    }
}
