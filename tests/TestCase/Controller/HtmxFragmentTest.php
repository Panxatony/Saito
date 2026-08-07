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

use PHPUnit\Framework\Attributes\DataProvider;
use Saito\Test\IntegrationTestCase;

/**
 * What every endpoint owes htmx (#82).
 *
 * Twenty-one actions answer differently depending on `HX-Request`, and the
 * suite only ever exercised the direct visit — the path almost nobody takes.
 * The fragment path is what every click in the forum actually triggers, and it
 * has shipped broken twice: the terms-of-service gate in 8.4.1 and the
 * second-factor confirmation in 8.4.2, both "the button does nothing", both
 * invisible in the log, both green in the tests.
 *
 * Two properties are checked here, and they are the two that failed:
 *
 * A fragment must be a fragment. htmx swaps the response into an element, so a
 * whole document lands a second `<html>` inside the page — it renders, it looks
 * nearly right, and everything that searches the DOM afterwards quietly stops
 * working.
 *
 * And a request must survive without a form-tampering token. Forms rendered by
 * an action where FormProtection is unloaded carry no `_Token`; posting one into
 * an action where FormProtection is active is blackholed into a 403 that htmx
 * does not swap. `configureHtmxRequest()` reproduces exactly that, which is why
 * the POST cases below use it rather than `mockSecurity()`.
 */
class HtmxFragmentTest extends IntegrationTestCase
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

    /** Ulysses. */
    private const USER_ID = 3;

    /** Alice, an administrator — edits are not time-limited for her. */
    private const ADMIN_ID = 1;

    /**
     * The endpoints an anonymous visitor can reach, and must get a fragment
     * from.
     *
     * @return array<string, array{0: string}>
     */
    public static function anonymousFragmentProvider(): array
    {
        return [
            'forgot password' => ['/users/htmx-forgot-password'],
            'reset password' => ['/users/htmx-reset-password'],
            'register' => ['/users/htmx-register'],
        ];
    }

    /**
     * The endpoints that need a member behind them.
     *
     * @return array<string, array{0: string}>
     */
    public static function memberFragmentProvider(): array
    {
        return [
            'thread index' => ['/entries/htmx-index'],
            'new posting' => ['/entries/htmx-add'],
            'member list' => ['/users/htmx-users'],
            'own bookmarks' => ['/users/bookmarks'],
            'change password' => ['/users/htmx-change-password'],
            'advanced search' => ['/searches/htmx-advanced'],
            'contact the owner' => ['/contacts/htmx-contact-owner'],
        ];
    }

    /**
     * @param string $url the endpoint
     * @return void
     */
    #[DataProvider('anonymousFragmentProvider')]
    public function testAnonymousEndpointsAnswerWithAFragment(string $url): void
    {
        $this->configureHtmxRequest();
        $this->get($url);

        $this->assertResponseOk();
        $this->assertHtmxFragment();
    }

    /**
     * @param string $url the endpoint
     * @return void
     */
    #[DataProvider('memberFragmentProvider')]
    public function testMemberEndpointsAnswerWithAFragment(string $url): void
    {
        $this->_loginUser(self::USER_ID);
        $this->configureHtmxRequest();
        $this->get($url);

        $this->assertResponseOk();
        $this->assertHtmxFragment();
    }

    /**
     * Saving an edit answers with `HX-Redirect`, not a `302`.
     *
     * Not a fragment test: `htmxEdit` renders a whole page on purpose — the
     * editor is somewhere you navigate to, and only the *result* of saving goes
     * back through htmx. Asserting a fragment here, as this first did, tests a
     * promise the action never made.
     *
     * What it does owe is this header. htmx follows a `302` and swaps the
     * redirect target into the element it was told to fill, so a plain redirect
     * lands a whole thread page inside the editor — it renders, and everything
     * afterwards is subtly wrong.
     *
     * @return void
     */
    public function testSavingAnEditAnswersWithAnHtmxRedirect(): void
    {
        $this->_loginUser(self::ADMIN_ID);
        $this->configureHtmxRequestWithoutFormToken();
        $this->post('/entries/htmx-edit/1', [
            'subject' => 'Edited by the fragment test',
            'text' => 'Body rewritten by the fragment test.',
            'category_id' => 2,
        ]);

        $this->assertNotBlackholed();
        $this->assertNotEmpty(
            $this->_response->getHeaderLine('HX-Redirect'),
            'a 302 would be followed and swapped into the editor instead of navigating',
        );
    }

    /**
     * The same endpoints without the header still answer with a whole page.
     *
     * The direct visit is what somebody lands on from a bookmark or with
     * JavaScript switched off, and it is the half the suite already covered —
     * asserted here so a future "always return a fragment" cannot quietly take
     * it away.
     *
     * @return void
     */
    public function testWithoutTheHeaderTheSameEndpointsAnswerWithAPage(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->get('/entries/htmx-index');

        $this->assertResponseOk();
        $this->assertResponseContains('<html');
    }

    /**
     * A form rendered without a `_Token` must still post.
     *
     * This is the shape that shipped broken twice. `configureHtmxRequest()`
     * withholds the FormProtection token the browser never had; if the action
     * blackholes, the response is a 403 and htmx swaps nothing — the button
     * simply does nothing, with no log entry to find it by.
     *
     * @return void
     */
    public function testTheChangePasswordFormPostsWithoutAFormProtectionToken(): void
    {
        $this->_loginUser(self::USER_ID);
        $this->configureHtmxRequestWithoutFormToken();
        $this->post('/users/htmx-change-password', [
            'password_old' => 'test',
            'password' => 'a-brand-new-secret',
            'password_confirm' => 'a-brand-new-secret',
        ]);

        $this->assertNotBlackholed();
        $this->assertResponseSuccess();
    }

    /**
     * The password-reset request form, same reasoning. It is rendered inside the
     * login overlay, where FormProtection is unloaded.
     *
     * @return void
     */
    public function testTheForgotPasswordFormPostsWithoutAFormProtectionToken(): void
    {
        $this->configureHtmxRequest();
        $this->post('/users/htmx-forgot-password', ['email' => 'ulysses@example.com']);

        $this->assertNotBlackholed();
        $this->assertResponseSuccess();
    }
}
