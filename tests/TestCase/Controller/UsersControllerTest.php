<?php

namespace App\Test\TestCase\Controller;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use App\Controller\UsersController;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Cookie\CookieCollection;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Mailer\Message;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;
use Saito\User\Permission\ResourceAC;

class UsersControllerTest extends IntegrationTestCase
{
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '*') ?: [] as $file) {
            if (is_dir($file)) {
                $this->rrmdir($file . '/');
            } else {
                unlink($file);
            }
        }
        @rmdir($dir);
    }


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
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    public function testAdminAddSuccess()
    {
        $this->mockSecurity();
        $data = [
            'username' => 'foo',
            'user_email' => 'fo3@example.com',
            'password' => 'test',
            'password_confirm' => 'test',
        ];
        $expected = [
            'username' => 'foo',
            'user_email' => 'fo3@example.com',
        ];

        $Users = TableRegistry::getTableLocator()->get('Users');
        $before = $Users->find()->count();

        $this->_loginUser(1);
        $this->post('/admin/users/add', $data);

        $this->assertEquals($before + 1, $Users->find()->count());

        $user = $Users->find()->orderBy(['id' => 'DESC'])->first();
        foreach (array_keys($expected) as $field) {
            $this->assertEquals($expected[$field], $user->get($field));
        }

        $auth = new DefaultPasswordHasher();
        $this->assertTrue($auth->check('test', $user->get('password')));

        $this->assertRedirect('/users/view/' . $user->get('id'));
    }

    public function testAdminAddNoAccess()
    {
        $url = '/admin/users/add';
        $this->post($url);
        $this->assertRedirectLogin($url);
    }

    public function testLogin()
    {
        $data = ['username' => 'Ulysses', 'password' => 'test'];

        $this->get('/');
        $this->assertFalse($this->_controller->CurrentUser->isLoggedIn());
        // Cake 4: Server::run() calls $session->close() at end of dispatch,
        // which resets _started=false. Reading via the Session API afterwards
        // in CLI re-calls start(), wiping $_SESSION. Use the $_SESSION
        // superglobal directly to introspect post-request session state.
        $this->assertArrayNotHasKey('Auth', $_SESSION);

        $this->mockSecurity();
        $this->post('/login', $data);

        $this->assertFalse($this->_controller->components()->has('FormProtection'));

        $this->assertTrue($this->_controller->CurrentUser->isLoggedIn());
        $this->assertArrayHasKey('Auth', $_SESSION);
        $this->assertNotNull($_SESSION['Auth']);

        //# successful login redirects
        $this->assertRedirect('/');

        //# last login time should be set
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(3, fields: 'last_login');
        $this->assertWithinRange($user->get('last_login')->toUnixString(), time(), 2);
    }

    /**
     * Brute-force throttle: after too many failed logins from a client, the
     * next attempt is blocked (before authentication) for the throttle window.
     *
     * @return void
     */
    public function testLoginThrottledAfterTooManyFailures()
    {
        Cache::clear('default');
        try {
            $this->mockSecurity();
            $bad = ['username' => 'Ulysses', 'password' => 'wrong'];

            // Exhaust the failed-attempt budget (LOGIN_MAX_ATTEMPTS = 10).
            for ($i = 0; $i < 10; $i++) {
                $this->post('/login', $bad);
                $this->assertFalse($this->_controller->CurrentUser->isLoggedIn());
            }

            // The next attempt is throttled: blocked before authentication.
            $this->post('/login', $bad);
            $this->assertResponseContains('Too many failed login attempts');
            $this->assertFalse($this->_controller->CurrentUser->isLoggedIn());
        } finally {
            // Isolate from other login tests (ArrayEngine persists in-process).
            Cache::clear('default');
        }
    }

    /**
     * Registration sends a mail to whatever address was typed in, so the form
     * needs a budget of its own — otherwise it is a way to make the forum send
     * mail to third parties on demand, from its own domain and with its SPF and
     * DKIM behind it.
     *
     * The honeypot and the five-second minimum the form already had are bot
     * defences and no help here: both are satisfied by waiting, and the wait is
     * per session rather than per client.
     *
     * @return void
     */
    public function testRegistrationIsThrottledPerClient()
    {
        Cache::clear('default');
        try {
            $this->mockSecurity();

            // Exhaust the budget (REGISTER_MAX_ATTEMPTS = 5). The attempts
            // themselves need not succeed — a rejected one still costs, and
            // still would have told the sender whether an address is taken.
            for ($i = 0; $i < 5; $i++) {
                $this->post('/users/htmx-register', ['username' => 'x', 'user_email' => 'x@example.com']);
                $this->assertResponseNotContains('user.authe.throttled');
            }

            $this->post('/users/htmx-register', ['username' => 'x', 'user_email' => 'x@example.com']);
            $this->assertResponseContains('Too many');
        } finally {
            Cache::clear('default');
        }
    }

    /**
     * Logging in with "remember me" must set a *persistent* auth cookie.
     *
     * Regression: the Cookie authenticator was configured with the Cake 3
     * keys 'expire'/'httpOnly', which Cake 5's Cookie::create() silently
     * drops. The remember-me cookie then had no expiry (= session cookie) and
     * no HttpOnly flag, so users were logged out as soon as they closed the
     * browser (effectively a daily re-login).
     *
     * @return void
     */
    public function testLoginRememberMeSetsPersistentCookie()
    {
        $this->mockSecurity();
        $this->post('/login', [
            'username' => 'Ulysses',
            'password' => 'test',
            'remember_me' => 1,
        ]);

        $this->assertTrue($this->_controller->CurrentUser->isLoggedIn());
        $this->assertRedirect('/');

        $cookieName = Configure::read('Security.cookieAuthName');
        // The CookieAuthenticator emits the cookie as a raw Set-Cookie header
        // (not via the response CookieCollection), so parse the headers back.
        $cookies = CookieCollection::createFromHeader($this->_response->getHeader('Set-Cookie'));
        $this->assertTrue($cookies->has($cookieName), 'remember-me cookie was not set');

        $cookie = $cookies->get($cookieName);
        $this->assertNotEmpty($cookie->getValue());

        //# must be persistent, not a session cookie
        $this->assertNotNull(
            $cookie->getExpiresTimestamp(),
            'remember-me cookie must have an expiry (must not be a session cookie)'
        );
        $this->assertGreaterThan(time(), $cookie->getExpiresTimestamp());

        //# and carry its security flags
        $this->assertTrue($cookie->isHttpOnly(), 'remember-me cookie must be HttpOnly');
    }

    public function testLoginShowForm()
    {
        //# show login form
        $this->get('/login');
        $this->assertResponseSuccess();
        $this->assertNoRedirect();

        //## test username field
        $username = [
            'input#tf-login-username' => [
                'attributes' => [
                    'autocomplete' => 'username',
                    'name' => 'username',
                    'required' => 'required',
                    'tabindex' => '100',
                    'type' => 'text',
                ],
            ],
            'input#password' => [
                'attributes' => [
                    'autocomplete' => 'current-password',
                    'name' => 'password',
                    'required' => 'required',
                    'tabindex' => '101',
                    'type' => 'password',
                ],
            ],
        ];
        $this->assertContainsTag($username, (string)$this->_response->getBody());

        //# test logout on form show
        $this->assertFalse($this->_controller->CurrentUser->isLoggedIn());
        $user = $this->_loginUser(3);
        $this->_controller->CurrentUser->setSettings($user);
        $this->assertTrue($this->_controller->CurrentUser->isLoggedIn());

        $this->get('/login');

        $this->assertFalse($this->_controller->CurrentUser->isLoggedIn());
    }

    public function testLoginUserNotActivated()
    {
        $this->mockSecurity();
        $data = ['username' => 'Diane', 'password' => 'test'];
        $this->post('/login', $data);
        $this->assertResponseContains('is not activated yet.');
    }

    public function testLoginUserLocked()
    {
        $this->mockSecurity();
        $Users = TableRegistry::getTableLocator()->get('Users');
        $UserBlocks = $this->getMockForTable(
            'UserBlocks',
            ['getBlockEndsForUser']
        );
        $UserBlocks
            ->expects($this->once())
            ->method('getBlockEndsForUser')
            ->with('8')
            ->willReturn(false);
        $Users->getAssociation('UserBlocks')->setTarget($UserBlocks);
        $data = ['username' => 'Walt', 'password' => 'test'];
        $this->post('/login', $data);
        $this->assertResponseContains('is locked.');
    }

    public function testRegistrationStringsAreTranslated()
    {
        // Regression: the registration + confirmation pages had their text
        // hard-coded in English, so a German (or any non-English) forum showed
        // English. The strings now go through i18n; verify they resolve to the
        // localized text (and never leak the raw message key).
        \Cake\Cache\Cache::clear('_cake_translations_');
        \Cake\I18n\I18n::setLocale('de');
        try {
            $keys = [
                'register_success_title' => 'Danke für deine Registrierung',
                'register_success_login_note' => 'Du kannst dich erst anmelden, nachdem du deine Registrierung bestätigt hast.',
                'register_fail_email_title' => 'Bestätigungs-E-Mail konnte nicht gesendet werden',
                'register_confirm_success_text' => 'Deine Registrierung ist jetzt abgeschlossen.',
                'register_confirm_success_link' => 'Viel Spaß!',
                'register_confirm_already_title' => 'Bereits registriert',
                'register_confirm_failed_title' => 'Registrierung fehlgeschlagen',
            ];
            foreach ($keys as $key => $expected) {
                $this->assertSame($expected, __($key), sprintf('Key "%s" is not translated to German.', $key));
            }
        } finally {
            \Cake\I18n\I18n::setLocale('en_US');
        }
    }

    public function testRsSuccess()
    {
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(10);

        $this->assertEquals(1548, $user->get('activate_code'));
        $this->get('/users/rs/10/?c=1548');

        $user = $Users->get(10);
        $this->assertEquals(0, $user->get('activate_code'));
    }

    public function testRsFailureNoPermission()
    {
        Configure::read('Saito.Permission.Resources')
            ->get('saito.core.user.register')
            ->disallow((new ResourceAC())->asEverybody());
        $this->expectException(SaitoForbiddenException::class);
        $this->get('/users/rs/10/?c=1549');
    }

    public function testRsFailureWrongCode()
    {
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(10);

        $this->assertEquals(1548, $user->get('activate_code'));
        $this->get('/users/rs/10/?c=1549');

        $user = $Users->get(10);
        $this->assertEquals(1548, $user->get('activate_code'));
    }

    public function testSetcategoryNotLoggedIn()
    {
        $url = '/users/setcategory/all';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }

    /**
     * `@name` mentions in posting text point here, so this redirect is written
     * into decades of existing content and has to keep resolving.
     *
     * @return void
     */
    /**
     * Blocking a member sits on the profile page, not in the admin backend:
     * `saito.core.user.lock.set` grants it to moderators, and the backend is
     * admin-only. The SPA profile had it; without it here, a forum on the
     * island frontend cannot block anybody at all.
     *
     * @return void
     */
    public function testProfileOffersBlockingToAModerator()
    {
        $this->_loginUser(2); // Mitch, moderator
        $this->get('/users/htmx-profile/3'); // Ulysses, plain member

        $this->assertResponseOk();
        $this->assertResponseContains('lockUserId');
        $this->assertResponseContains('lockPeriod');
    }

    /**
     * ...and stays invisible to everybody else, so a member does not see
     * controls they cannot use.
     *
     * @return void
     */
    public function testProfileHidesBlockingFromAPlainMember()
    {
        $this->_loginUser(3);
        $this->get('/users/htmx-profile/9');

        $this->assertResponseOk();
        $this->assertResponseNotContains('lockUserId');
    }

    /**
     * The happy path, through the form the profile page renders.
     *
     * @return void
     */
    public function testModeratorCanBlockAMember()
    {
        $this->mockSecurity();
        $this->_loginUser(2);
        $this->post('/users/lock', ['lockUserId' => 3, 'lockPeriod' => 86400]);

        $blocks = TableRegistry::getTableLocator()->get('UserBlocks');
        $this->assertNotEmpty(
            $blocks->find()->where(['user_id' => 3, 'ended IS' => null])->first(),
            'the moderator could not block the member'
        );
    }

    /**
     * The duration is a plain number in the request. Without bounding it against
     * the offered list, a crafted POST could set a block of any length — a
     * moderator handing out a hundred-year ban through a hand-made form.
     *
     * @return void
     */
    public function testBlockDurationIsBoundedToTheOfferedList()
    {
        $this->mockSecurity();
        $this->_loginUser(2);

        $this->expectException(BadRequestException::class);
        $this->post('/users/lock', ['lockUserId' => 3, 'lockPeriod' => 3153600000]);
    }

    /**
     * The duration is a plain number in the request. Without bounding it, a
     * crafted POST could set a block of any length — a moderator handing out a
     * hundred-year ban through a hand-made form.
     *
     * @return void
     */
    public function testBlockDurationIsBounded()
    {
        $this->mockSecurity();
        $this->_loginUser(2);

        $this->expectException(BadRequestException::class);
        $this->post('/users/lock', ['lockUserId' => 3, 'lockPeriod' => 3153600000]);
    }

    /**
     * Both controls that produce this value must pass: the SPA profile's range
     * slider (6h…5d in 6h steps, twenty values) and the island profile's
     * five-entry select. A fixed list would have accepted the select and
     * rejected fifteen of the slider's values.
     *
     * @return void
     */
    public function testBlockDurationAcceptsEverySliderStep()
    {
        $blocks = TableRegistry::getTableLocator()->get('UserBlocks');

        foreach (range(UsersController::LOCK_MIN, UsersController::LOCK_MAX, UsersController::LOCK_STEP) as $seconds) {
            $blocks->deleteAll(['user_id' => 3]);
            $this->mockSecurity();
            $this->_loginUser(2);
            $this->post('/users/lock', ['lockUserId' => 3, 'lockPeriod' => $seconds]);

            $this->assertNotEmpty(
                $blocks->find()->where(['user_id' => 3, 'ended IS' => null])->first(),
                sprintf('slider step %d was refused', $seconds)
            );
        }
    }

    /**
     * Read back what the endpoint stored for member 3.
     *
     * @return array{order: list<string>, minimised: list<string>}
     */
    protected function storedArrangement(): array
    {
        return \Saito\User\WidgetPreferences::read(
            TableRegistry::getTableLocator()->get('Users')->get(3)->get('slidetab_order'),
            \App\Controller\EntriesController::WIDGETS
        );
    }

    /**
     * The rail arrangement belongs to the member, not the browser, so it
     * survives a different device. Stored in `users.slidetab_order` — the
     * column the retired slidetabs kept their drag-and-drop order in — which
     * keeps it a code change rather than a migration.
     *
     * @return void
     */
    public function testWidgetStateIsStoredOnTheAccount()
    {
        $this->mockSecurity();
        $this->_loginUser(3);
        $this->post('/users/htmx-widget-state', ['widgets' => ['online', 'mine']]);

        $this->assertResponseOk();
        $this->assertSame(['online', 'mine'], $this->storedArrangement()['minimised']);
    }

    /**
     * The order a member drags the rail into is the other half of the same
     * column.
     *
     * @return void
     */
    public function testWidgetOrderIsStoredOnTheAccount()
    {
        $this->mockSecurity();
        $this->_loginUser(3);
        $this->post('/users/htmx-widget-state', ['order' => ['mine', 'recent', 'online']]);

        $this->assertResponseOk();
        $this->assertSame(['mine', 'recent', 'online'], $this->storedArrangement()['order']);
    }

    /**
     * Both halves share one column, so a request carrying both must not lose
     * either — this is the request the island actually sends.
     *
     * @return void
     */
    public function testWidgetOrderAndMinimisedStateSurviveTogether()
    {
        $this->mockSecurity();
        $this->_loginUser(3);
        $this->post('/users/htmx-widget-state', [
            'order' => ['mine', 'online', 'recent'],
            'widgets' => ['online'],
        ]);

        $this->assertResponseOk();
        $this->assertSame(
            ['order' => ['mine', 'online', 'recent'], 'minimised' => ['online']],
            $this->storedArrangement()
        );
    }

    /**
     * A widget the interface does not offer must not reach the column — the
     * request is the one place a name could be anything at all.
     *
     * @return void
     */
    public function testWidgetStateRejectsUnknownWidgets()
    {
        $this->mockSecurity();
        $this->_loginUser(3);
        $this->post('/users/htmx-widget-state', [
            'widgets' => ['online', 'not-a-widget'],
            'order' => ['not-a-widget', 'mine'],
        ]);

        $arrangement = $this->storedArrangement();
        $this->assertSame(['online'], $arrangement['minimised']);
        $this->assertSame(['mine', 'online', 'recent'], $arrangement['order']);
    }

    /**
     * A GET must not write. Otherwise a prefetching browser could rearrange
     * somebody's rail by following a link.
     *
     * @return void
     */
    public function testWidgetStateRefusesGet()
    {
        $this->_loginUser(3);

        $this->expectException(BadRequestException::class);
        $this->get('/users/htmx-widget-state');
    }

    public function testName()
    {
        $this->_loginUser(3);
        $this->get('/users/name/Mitch');
        $this->assertRedirect('/users/htmx-profile/2');
    }

    /**
     * An unknown name must not 500 or leak whether the account exists — it
     * sends the visitor to the front page with a notice.
     *
     * @return void
     */
    public function testNameUnknownUser()
    {
        $this->_loginUser(3);
        $this->get('/users/name/DoesNotExist');
        $this->assertRedirect('/');
    }

    public function testEditNotLoggedIn()
    {
        $url = '/users/edit/3';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }


    public function testIgnore()
    {
        $this->mockSecurity();
        $this->_loginUser(3);

        $Ignores = TableRegistry::getTableLocator()->get('UserIgnores');
        $this->assertEmpty($Ignores->find()->count());

        $this->post('/users/ignore', ['id' => 1]);

        $this->assertEquals(1, $Ignores->find()->count());
        $this->assertEquals(3, $Ignores->find()->first()->get('user_id'));
        $this->assertEquals(1, $Ignores->find()->first()->get('blocked_user_id'));
        $this->assertRedirect();

        $this->post('/users/ignore', ['id' => 1]);
        $this->assertEquals(1, $Ignores->find()->count());

        $this->post('/users/unignore', ['id' => 1]);
        $this->assertEquals(0, $Ignores->find()->count());
    }

    public function testLockFailureNotLoggedIn()
    {
        $this->mockSecurity();

        /* not logged in should'nt be allowed */
        $this->post('/users/lock', ['lockUserId' => 3]);
        $this->assertRedirectContains('/login');
    }

    public function testLockFailureUserDontLockUsers()
    {
        $this->mockSecurity();
        $this->_loginUser(3);

        $this->expectException(SaitoForbiddenException::class);

        $this->post('/users/lock', ['lockUserId' => 4]);
    }

    public function testLockFailureNoPermission()
    {
        Configure::read('Saito.Permission.Resources')
            ->get('saito.core.user.lock.set')
            ->disallow((new ResourceAC())->asEverybody());
        $this->mockSecurity();
        $this->_loginUser(11);

        $this->expectException(SaitoForbiddenException::class);

        $this->post('/users/lock', ['lockUserId' => 11]);
    }

    public function testLockSetUserDoesNotExistFailure()
    {
        $this->mockSecurity();
        $this->_loginUser(2);

        $this->expectException(RecordNotFoundException::class);

        $this->post('/users/lock', ['lockUserId' => 9999]);
    }

    public function testChangePasswordNotLoggedIn()
    {
        $this->get('/users/changepassword/5');
        $this->assertRedirectContains('/login');
    }

    public function testSetPasswordAnon()
    {
        $this->get('/users/setpassword/4');
        $this->assertRedirectLogin('/users/setpassword/4');
    }

    /**
     * Mod menu is currently empty for mod
     */
    public function testViewBlockButtonEmpty()
    {
        $this->_loginUser(3);
        $this->get('users/view/5');
        $this->assertResponseNotContains('dropdown');
    }

    public function testAvatarGetNotLoggedInFailure()
    {
        $url = '/users/avatar/3';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }

    public function testAvatarPostNotLoggedInFailure()
    {
        $url = '/users/avatar/3';
        $this->post($url);
        $this->assertRedirectLogin($url);
    }

    public function testLogoutSuccess()
    {
        $this->_loginUser(1);
        $this->cookie('my_cookie', 'foo');

        $user = $this->get('/logout');

        $this->assertFalse($this->_controller->CurrentUser->isLoggedIn());

        $cookies = $this->_response->getCookieCollection();
        $cookie = $cookies->get('my_cookie');
        $this->assertTrue($cookie->isExpired());
        $this->assertSame($this->_controller->getRequest()->getAttribute('webroot'), $cookie->getPath());

        $this->assertRedirect('/');
    }

    public function testRoleViewUserUnauthenticated()
    {
        $url = '/users/role/3';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }

    public function testDeleteNotAuthenticatedCantDelete()
    {
        $this->mockSecurity();
        $url = '/users/delete/3';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }


    /**
     * The island login page is public — it has to be, or nobody could reach it.
     *
     * @return void
     */
    public function testHtmxLoginIsPublic(): void
    {
        $this->get('/users/htmx-login');

        $this->assertResponseOk();
    }

    /**
     * Somebody already signed in has no business on the login page, and sending
     * them to the front page rather than their profile is deliberate: they were
     * on their way into the forum, not into their settings.
     *
     * @return void
     */
    public function testHtmxLoginSendsSignedInMembersToTheFrontPage(): void
    {
        $this->_loginUser(3);
        $this->get('/users/htmx-login');

        // '/' rather than '/entries/htmx-index': the root route points at that
        // action, so the router builds the shorter address for it.
        $this->assertRedirect('/');
    }

    /**
     * Bookmark notes were rendered but unwritable after the SPA went: the only
     * thing that could set one was the REST endpoint its client called.
     *
     * @return void
     */
    public function testHtmxBookmarkCommentSavesTheNote(): void
    {
        $this->_loginUser(3);
        $this->post('/users/htmx-bookmark-comment/1', ['comment' => 'read this later']);

        $this->assertResponseOk();
        $stored = TableRegistry::getTableLocator()->get('Bookmarks.Bookmarks')
            ->find()->where(['user_id' => 3, 'entry_id' => 1])->first();
        $this->assertSame('read this later', $stored->get('comment'));
    }

    /**
     * The bookmark is found by posting id *and* current user, so there is no id
     * here that could address somebody else's row. Bookmark 3 belongs to user 1
     * on the same posting; user 3 must not reach it.
     *
     * @return void
     */
    public function testHtmxBookmarkCommentTouchesOnlyTheCurrentUsersBookmark(): void
    {
        $this->_loginUser(3);
        $this->post('/users/htmx-bookmark-comment/1', ['comment' => 'mine']);

        $this->assertResponseOk();
        $other = TableRegistry::getTableLocator()->get('Bookmarks.Bookmarks')->get(3);
        $this->assertSame('Comment 3', $other->get('comment'));
    }

    /**
     * A posting the member has not bookmarked is not a note they may write.
     *
     * @return void
     */
    public function testHtmxBookmarkCommentRefusesAPostingWithoutABookmark(): void
    {
        $this->expectException(NotFoundException::class);

        $this->_loginUser(3);
        $this->post('/users/htmx-bookmark-comment/2', ['comment' => 'nope']);
    }
}
