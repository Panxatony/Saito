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

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\TableRegistry;
use Saito\Test\IntegrationTestCase;

/**
 * The self-service password-reset flow (#63).
 *
 * @covers \App\Controller\UsersController::htmxForgotPassword
 * @covers \App\Controller\UsersController::htmxResetPassword
 */
class UsersControllerPasswordResetTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Draft',
        'app.Entry',
        'app.Setting',
        'app.Smiley',
        'app.SmileyCode',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
        'app.PasswordResetToken',
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    protected \App\Model\Table\UsersTable $Users;
    protected \App\Model\Table\PasswordResetTokensTable $Tokens;

    public function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\UsersTable $users */
        $users = TableRegistry::getTableLocator()->get('Users');
        $this->Users = $users;
        /** @var \App\Model\Table\PasswordResetTokensTable $tokens */
        $tokens = TableRegistry::getTableLocator()->get('PasswordResetTokens');
        $this->Tokens = $tokens;

        // A known, activated account to request a reset for.
        $this->Users->updateAll(['activate_code' => 0], ['id' => 1]);
    }

    /**
     * An unknown address is answered exactly like a known one, and nothing is
     * issued — the flow never reveals who is a member.
     */
    public function testUnknownAddressSaysSentAndIssuesNothing(): void
    {
        $this->post('/users/htmx-forgot-password', ['user_email' => 'nobody@example.com']);

        $this->assertResponseOk();
        // The form is gone (the "check your mail" status replaced it).
        $this->assertResponseNotContains('name="user_email"');
        $this->assertSame(0, $this->Tokens->find()->count(), 'no token for an unknown address');
    }

    /**
     * A known, activated address gets a token issued (and a mail sent).
     */
    public function testKnownAddressIssuesAToken(): void
    {
        $this->post('/users/htmx-forgot-password', ['user_email' => 'alice@example.com']);

        $this->assertResponseOk();
        $this->assertSame(1, $this->Tokens->find()->where(['user_id' => 1])->count());
    }

    /**
     * The heart of it: the reply for a known and an unknown address is
     * byte-for-byte the same, so it cannot be used to probe membership.
     */
    public function testResponseIsIdenticalForKnownAndUnknownAddress(): void
    {
        $this->post('/users/htmx-forgot-password', ['user_email' => 'alice@example.com']);
        $this->assertResponseNotContains('name="user_email"', 'a known address gets the sent status, not the form');
        $known = $this->normaliseChrome((string)$this->_response->getBody());

        $this->post('/users/htmx-forgot-password', ['user_email' => 'nobody@example.com']);
        $this->assertResponseNotContains('name="user_email"', 'an unknown address gets the same sent status');
        $unknown = $this->normaliseChrome((string)$this->_response->getBody());

        // Identical once the two per-request bits of page chrome are levelled:
        // the CSRF token and the render-time footer vary on every call and are
        // not part of the reply.
        $this->assertSame($known, $unknown);
    }

    /**
     * Blank out the page chrome that changes on every request, so the rest of
     * the response can be compared byte-for-byte.
     *
     * @param string $html the rendered page
     * @return string
     */
    private function normaliseChrome(string $html): string
    {
        $html = (string)preg_replace('/(name="csrf-token" content=)"[^"]*"/', '$1"_"', $html);

        return (string)preg_replace('/Generated in [0-9.]+ s/', 'Generated in _ s', $html);
    }

    /**
     * An account that never finished activating cannot be reset into.
     */
    public function testUnactivatedAccountIssuesNothing(): void
    {
        $this->Users->updateAll(['activate_code' => 12345], ['id' => 1]);

        $this->post('/users/htmx-forgot-password', ['user_email' => 'alice@example.com']);

        $this->assertResponseOk();
        $this->assertSame(0, $this->Tokens->find()->count());
    }

    /**
     * A bad or expired token lands on the "no good" message, never the form.
     */
    public function testInvalidTokenShowsNoForm(): void
    {
        $this->get('/users/htmx-reset-password?token=deadbeefdeadbeef');

        $this->assertResponseOk();
        $this->assertResponseNotContains('name="password"');
    }

    /**
     * A valid token shows the new-password form.
     */
    public function testValidTokenShowsTheForm(): void
    {
        $token = $this->Tokens->issueFor(1);

        $this->get('/users/htmx-reset-password?token=' . $token);

        $this->assertResponseOk();
        $this->assertResponseContains('name="password"');
    }

    /**
     * A matching new password is set, the token is burned, and the member is
     * sent to the login page.
     */
    public function testResetSetsPasswordAndBurnsToken(): void
    {
        $token = $this->Tokens->issueFor(1);
        $before = $this->Users->get(1)->get('password');

        $this->post('/users/htmx-reset-password', [
            'token' => $token,
            'password' => 'a-brand-new-secret',
            'password_confirm' => 'a-brand-new-secret',
        ]);

        $this->assertRedirectContains('/login');

        $after = $this->Users->get(1)->get('password');
        $this->assertNotSame($before, $after, 'the stored hash changed');
        $this->assertTrue(
            (new DefaultPasswordHasher())->check('a-brand-new-secret', $after),
            'the new password verifies',
        );
        $this->assertSame(0, $this->Tokens->find()->where(['user_id' => 1])->count(), 'token burned');
    }

    /**
     * A mismatched confirmation changes nothing and leaves the token usable.
     */
    public function testMismatchedConfirmationChangesNothing(): void
    {
        $token = $this->Tokens->issueFor(1);
        $before = $this->Users->get(1)->get('password');

        $this->post('/users/htmx-reset-password', [
            'token' => $token,
            'password' => 'a-brand-new-secret',
            'password_confirm' => 'does-not-match',
        ]);

        $this->assertResponseOk();
        $this->assertSame($before, $this->Users->get(1)->get('password'));
        $this->assertSame(1, $this->Tokens->find()->where(['user_id' => 1])->count(), 'token not burned');
    }
}
