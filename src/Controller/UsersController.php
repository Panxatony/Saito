<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller;

use App\Form\BlockForm;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use App\Model\Entity\User;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Routing\Router;
use Exception;
use Laminas\Diactoros\Stream;
use RuntimeException;
use Saito\Exception\Logger\ExceptionLogger;
use Saito\Exception\Logger\ForbiddenLogger;
use Saito\Exception\SaitoForbiddenException;
use Saito\Posting\Posting;
use Saito\User\Blocker\ManualBlocker;
use Saito\User\Auth\LoginResult;
use Saito\User\DataExport;
use Saito\User\Permission\ResourceAI;
use Saito\User\WidgetPreferences;
use Stopwatch\Lib\Stopwatch;
use Throwable;

/**
 * User controller
 */
class UsersController extends AppController
{
    /**
     * Bounds for a manual block, in seconds: 6 hours to 5 days, in 6-hour steps.
     *
     * Expressed as a rule rather than a list because two controls produce this
     * value — the SPA profile's range slider (min 21600, max 432000, step
     * 21600) and the island profile's select. A fixed list would accept the
     * select's five values and reject fifteen of the slider's twenty.
     */
    public const LOCK_MIN = 21600;
    public const LOCK_MAX = 432000;
    public const LOCK_STEP = 21600;

    /**
     * Durations offered by the island profile's select, in seconds.
     *
     * A readable subset of what {@see LOCK_MIN}…{@see LOCK_MAX} allows — the
     * validation bounds the value, this only decides what is offered.
     *
     * @var list<int>
     */
    public const LOCK_DURATIONS = [21600, 43200, 86400, 259200, 432000];

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->addHelpers([
            'Posting',
            'Text',
        ]);
        $this->loadComponent('Referer');
    }

    /**
     * Login user.
     *
     * @return \Cake\Http\Response|void
     */
    public function login()
    {
        // Island login modal: an HX-Request renders just the form fragment
        // (+ flash) instead of the full page, and a successful login returns an
        // HX-Redirect header so htmx does a full navigation. All the auth logic
        // below (throttle, logging, AuthUser->login) is shared and untouched.
        $isHx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';
        if ($isHx) {
            // In the overlay: just the form fragment (plus flash).
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_login_form');
        } else {
            // Standalone: the same page /users/htmx-login serves. There used to
            // be a second, SPA-era template here whose inline script called
            // SaitoApp and jQuery — it threw on every visit once those were
            // gone, and its "back" link relied on a header subnav the island
            // layout does not render.
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_login');
        }

        $data = $this->request->getData();
        if (empty($data['username'])) {
            $logout = $this->_logoutAndComeHereAgain();
            if ($logout) {
                return $logout;
            }

            /// Show form to user.
            if ($this->getRequest()->getQuery('redirect', null)) {
                $this->Flash->set(
                    __('user.authe.required.exp'),
                    ['element' => 'warning', 'params' => ['title' => __('user.authe.required.t')]],
                );
            }

            return;
        }

        // Brute-force / credential-stuffing throttle: block a client that has
        // burned through its failed-attempt budget for the current window,
        // before even trying to authenticate.
        if ($this->_isLoginThrottled()) {
            (new ForbiddenLogger())->write(
                "Throttled login for user: {$data['username']}",
                ['msgs' => [__('user.authe.throttled')]],
            );
            $this->setRequest($this->getRequest()->withData('password', ''));
            $this->Flash->set(__('user.authe.throttled'), [
                'element' => 'error',
                'params' => ['title' => __('user.authe.e.t')],
            ]);

            return;
        }

        $result = $this->AuthUser->login();

        if ($result === LoginResult::SecondFactorRequired) {
            // The password was right, so it does not count against the throttle
            // — the second factor has a budget of its own.
            $this->_clearLoginThrottle();
            $this->getRequest()->getSession()->write(
                'Saito.pending2faTarget',
                $this->_loginRedirectTarget(),
            );

            if ($isHx) {
                // Swap the code form into the overlay the member already has
                // open, rather than navigating away from it: this is a second
                // *step*, not a second destination. The fragment posts back to
                // the same modal body.
                $this->set('errorMessage', null);
                $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_two_factor_form');

                return null;
            }

            return $this->redirect(['controller' => 'Users', 'action' => 'twoFactor']);
        }

        if ($result === LoginResult::LoggedIn) {
            $this->_clearLoginThrottle();
            $target = $this->_loginRedirectTarget();
            if ($isHx) {
                return $this->response->withHeader('HX-Redirect', $target);
            }

            return $this->redirect($target);
        }

        /// error on login
        $this->_registerFailedLogin();
        $username = (string)$this->request->getData('username');
        /** @var \App\Model\Entity\User|null $User */
        $User = $this->Users->find()
            ->where(['username' => $username])
            ->first();

        $message = $this->_failedLoginMessage($User, $username);

        // don't autofill password
        $this->setRequest($this->getRequest()->withData('password', ''));

        $Logger = new ForbiddenLogger();
        $Logger->write(
            "Unsuccessful login for user: $username",
            ['msgs' => [$message]],
        );

        $this->Flash->set($message, [
            'element' => 'error', 'params' => ['title' => __('user.authe.e.t')],
        ]);
    }

    /**
     * Resolve the post-login redirect target to a safe, local path.
     *
     * Prefers the `?redirect=` query-param, falls back to the referer, then to
     * the front-page. Open-redirects are rejected: only paths that start with a
     * single `/` (not `//` or `/\`, which browsers treat as protocol-relative
     * off-site URLs) are accepted; anything else is dropped.
     *
     * @return string local path, never empty
     */
    private function _loginRedirectTarget(): string
    {
        // AuthenticationService puts the full local path into the redirect
        // parameter, so strip the base-path off again.
        $target = $this->getRequest()->getQuery('redirect');
        $target = $target ? Router::normalize($target) : '';

        // Only accept local paths; reject absolute (https://evil.com) and
        // protocol-relative (//evil.com, /\evil.com) URLs.
        if ($target !== '' && !preg_match('#^/(?![/\\\\])#', $target)) {
            $target = '';
        }

        // Referer fallback only if it points somewhere other than the login
        // form itself; otherwise send the user to the front-page.
        if (empty($target)) {
            $referer = $this->referer('/', true);
            if ($referer && strpos($referer, '/login') === false) {
                $target = $referer;
            }
        }

        return empty($target) ? '/' : $target;
    }

    /**
     * Build the user-facing message for a failed login attempt.
     *
     * Deliberately generic when the account is unknown or the credentials are
     * simply wrong; only for a known account that is unactivated or blocked is
     * a specific reason shown (a block additionally states when it ends).
     *
     * @param \App\Model\Entity\User|null $User the account matching the submitted username, if any
     * @param string $username the submitted username
     * @return string translated message
     */
    private function _failedLoginMessage(?User $User, string $username): string
    {
        if (empty($User)) {
            return __('user.authe.e.generic');
        }
        if (!$User->isActivated()) {
            return __('user.actv.ny');
        }
        if (!$User->isLocked()) {
            return __('user.authe.e.generic');
        }

        $ends = $this->Users->UserBlocks->getBlockEndsForUser($User->getId());
        if (!$ends) {
            return __('user.block.pubExp', $username);
        }

        $time = new DateTime($ends);

        return __('user.block.pubExpEnds', [
            'name' => $username,
            'end' => $time->timeAgoInWords(['accuracy' => 'hour']),
        ]);
    }

    /** @var int max registration attempts per client and window */
    private const REGISTER_MAX_ATTEMPTS = 5;

    /** @var int registration throttle window in seconds */
    private const REGISTER_THROTTLE_WINDOW = 3600;

    /**
     * Whether the client has used up its registration budget for this window.
     *
     * Registering sends a mail to whatever address was typed into the form, so
     * an unthrottled form is a way to have the forum send mail to third parties
     * on demand -- from its own domain, with its SPF and DKIM behind it. The
     * honeypot and the five-second minimum the form already had are bot
     * defences, not a budget: both are satisfied by waiting, and the wait is per
     * session rather than per client.
     *
     * The same shape as the contact form's throttle, and for the same reason.
     * Counts on the way in, so an attempt that fails validation still costs --
     * an attempt is an attempt, and a failed one still tells the sender whether
     * the address is already registered.
     *
     * @return bool true when the client should be turned away
     */
    private function isRegisterThrottled(): bool
    {
        $key = 'register-throttle-' . $this->getRequest()->clientIp();
        $record = Cache::read($key);

        if (!is_array($record) || (time() - $record['first']) >= self::REGISTER_THROTTLE_WINDOW) {
            $record = ['count' => 0, 'first' => time()];
        }
        $record['count']++;
        Cache::write($key, $record);

        return $record['count'] > self::REGISTER_MAX_ATTEMPTS;
    }

    /** @var int max failed login attempts per client and window */
    private const LOGIN_MAX_ATTEMPTS = 10;

    /** @var int throttle window in seconds */
    private const LOGIN_THROTTLE_WINDOW = 900;

    /**
     * Cache key for the per-client failed-login counter.
     *
     * @return string
     */
    private function _loginThrottleKey(): string
    {
        return 'login-throttle-' . $this->getRequest()->clientIp();
    }

    /**
     * Whether the client has exhausted its failed-login budget for the current
     * window.
     *
     * @return bool
     */
    private function _isLoginThrottled(): bool
    {
        $record = Cache::read($this->_loginThrottleKey());
        if (!is_array($record)) {
            return false;
        }
        if (time() - $record['first'] >= self::LOGIN_THROTTLE_WINDOW) {
            return false;
        }

        return $record['count'] >= self::LOGIN_MAX_ATTEMPTS;
    }

    /**
     * Records a failed login attempt for the client (starts a fresh window
     * once the previous one has elapsed).
     *
     * @return void
     */
    private function _registerFailedLogin(): void
    {
        $key = $this->_loginThrottleKey();
        $record = Cache::read($key);
        if (!is_array($record) || (time() - $record['first']) >= self::LOGIN_THROTTLE_WINDOW) {
            $record = ['count' => 0, 'first' => time()];
        }
        $record['count']++;
        Cache::write($key, $record);
    }

    /**
     * Clears the client's failed-login counter after a successful login.
     *
     * @return void
     */
    private function _clearLoginThrottle(): void
    {
        Cache::delete($this->_loginThrottleKey());
    }

    /**
     * Logout user.
     *
     * @return \Cake\Http\Response|void
     */
    public function logout()
    {
        $request = $this->getRequest();
        $cookies = $request->getCookieCollection();
        foreach ($cookies as $cookie) {
            $cookie = $cookie->withPath($request->getAttribute('webroot'));
            $this->setResponse($this->getResponse()->withExpiredCookie($cookie));
        }

        $this->AuthUser->logout();

        // Honour a local ?redirect= target (e.g. the island header sends the
        // user back to the island front page instead of the SPA root). Only
        // same-site absolute paths are allowed — never a scheme or `//host`,
        // so this can't be turned into an open redirect.
        $redirect = $this->getRequest()->getQuery('redirect');
        if (
            is_string($redirect)
            && str_starts_with($redirect, '/')
            && !str_starts_with($redirect, '//')
        ) {
            return $this->redirect($redirect);
        }

        return $this->redirect('/');
    }

    /**
     * register success (user clicked link in confirm mail)
     *
     * @param string $id user-ID
     * @return void
     * @throws \Cake\Http\Exception\BadRequestException
     */
    public function rs(?string $id = null): void
    {
        if (!$id) {
            throw new BadRequestException();
        }
        // Cast so a missing `?c=` is an empty string (a failed activation), not
        // a TypeError that the Exception catch below would not catch.
        $code = (string)$this->request->getQuery('c');
        try {
            $activated = $this->Users->activate((int)$id, $code);
        } catch (Exception $e) {
            $activated = false;
        }
        if (!$activated) {
            $activated = ['status' => 'fail'];
        }
        $this->set('status', $activated['status']);
        // Activation landing (reached from the email link) — island-styled.
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_rs');
    }

    /** @var int max reset requests/attempts per client and window */
    private const PASSWORD_RESET_MAX_ATTEMPTS = 5;

    /** @var int reset throttle window in seconds */
    private const PASSWORD_RESET_THROTTLE_WINDOW = 900;

    /**
     * Whether the client has spent its password-reset budget for the window.
     *
     * Guards both the request form and the reset-attempt form, keyed on the
     * client IP: it caps how often someone can ask the "is this address a
     * member" question the flow is careful never to answer, and how often a
     * token can be guessed at.
     *
     * @return bool
     */
    private function isPasswordResetThrottled(): bool
    {
        $key = 'password-reset-throttle-' . $this->getRequest()->clientIp();
        $record = Cache::read($key);

        if (!is_array($record) || (time() - $record['first']) >= self::PASSWORD_RESET_THROTTLE_WINDOW) {
            $record = ['count' => 0, 'first' => time()];
        }
        $record['count']++;
        Cache::write($key, $record);

        return $record['count'] > self::PASSWORD_RESET_MAX_ATTEMPTS;
    }

    /**
     * Step 1 of the forgotten-password flow: take an address and email a link.
     *
     * The reply is the same whether or not the address belongs to a member —
     * always "if that address is one of ours, a link is on the way". Saying
     * more would answer, to anyone who asks, whether a given person is
     * registered here, which a forum's membership is not public enough to allow
     * (the registration flow makes the same choice, for the same reason). A link
     * is only really sent for an address that matches an activated account.
     *
     * @return void
     */
    public function htmxForgotPassword(): void
    {
        $this->AuthUser->logout();
        $this->set('status', 'view');

        $isHtmx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';
        if ($isHtmx) {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_forgot_password_form');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_forgot_password');
        }

        if (!$this->request->is('post')) {
            return;
        }

        if ($this->isPasswordResetThrottled()) {
            $this->Flash->set(__('user.authe.throttled'), ['element' => 'error']);

            return;
        }

        // From here on the member is told the same thing no matter what.
        $this->set('status', 'sent');

        $email = trim((string)$this->request->getData('user_email'));
        if ($email === '') {
            return;
        }

        /** @var \App\Model\Entity\User|null $user */
        $user = $this->Users->find()
            ->where(['user_email' => $email, 'activate_code' => 0])
            ->first();
        if ($user === null) {
            // No such account, or one that never finished activating: say
            // nothing, do nothing — the "sent" status above still shows.
            return;
        }

        $token = $this->fetchTable('PasswordResetTokens')->issueFor((int)$user->get('id'));
        $resetUrl = Router::url(
            ['controller' => 'Users', 'action' => 'htmxResetPassword', '?' => ['token' => $token]],
            true,
        );

        try {
            $this->SaitoEmail->email([
                'recipient' => $user,
                'subject' => __('user.pwreset.email.subject', Configure::read('Saito.Settings.forum_name')),
                'sender' => 'register',
                'template' => 'user_password_reset',
                'viewVars' => ['user' => $user, 'resetUrl' => $resetUrl],
            ]);
        } catch (Exception $e) {
            // A mail hiccup must not become an oracle: still show "sent".
            (new ExceptionLogger())->write('Password-reset email failed', ['e' => $e]);
        }
    }

    /**
     * Step 2: the link's landing — set a new password when the token is valid.
     *
     * The token, not a session, is the authority here: it resolves to the
     * member and is single-use. The new password is set without the old one
     * (a locked-out member does not have it) via the same field-whitelisted
     * patch registration uses, and every token for that member is burned once
     * it succeeds so the link cannot be replayed.
     *
     * @return \Cake\Http\Response|null
     */
    public function htmxResetPassword(): ?Response
    {
        $this->AuthUser->logout();

        $isHtmx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';
        $render = function () use ($isHtmx): void {
            if ($isHtmx) {
                $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_reset_password_form');
            } else {
                $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_reset_password');
            }
        };

        $Tokens = $this->fetchTable('PasswordResetTokens');
        $isPost = $this->request->is('post');
        $token = $isPost
            ? (string)$this->request->getData('token')
            : (string)$this->request->getQuery('token');
        $this->set('token', $token);
        $this->set('errorMessage', null);

        $userId = $Tokens->userIdForToken($token);
        if ($userId === null) {
            $this->set('status', 'invalid');
            $render();

            return null;
        }

        if (!$isPost) {
            $this->set('status', 'form');
            $render();

            return null;
        }

        if ($this->isPasswordResetThrottled()) {
            $this->set('status', 'form');
            $this->set('errorMessage', __('user.authe.throttled'));
            $render();

            return null;
        }

        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($userId);
        $data = [
            'password' => $this->request->getData('password'),
            'password_confirm' => $this->request->getData('password_confirm'),
        ];
        // Field whitelist keeps this to the password (as register() does), so
        // the `password_old` rule — absent from the data — never fires, while
        // strength and the confirm match still do.
        $this->Users->patchEntity($user, $data, ['fields' => ['password']]);

        if (!$user->getErrors() && $this->Users->save($user)) {
            $Tokens->clearFor($userId);
            $this->Flash->set(__('user.pwreset.done'), ['element' => 'success']);
            $loginUrl = Router::url(['_name' => 'login']);

            // htmx follows a 302 and swaps it in; HX-Redirect makes it a real
            // navigation to the login page, where the flash then shows.
            return $isHtmx
                ? $this->response->withHeader('HX-Redirect', $loginUrl)
                : $this->redirect($loginUrl);
        }

        $errors = $user->getErrors();
        $this->set('status', 'form');
        $this->set('errorMessage', $errors ? __d('nondynamic', (string)current(array_pop($errors))) : null);
        $render();

        return null;
    }

    /**
     * Member list as an htmx island (strangler-fig migration).
     *
     * Same paginated, sortable user list as {@see index()}, rendered standalone
     * (no SPA). Demonstrates the island approach on non-thread, tabular content:
     * clicking a column header htmx-swaps just the table body (`HX-Request` →
     * rows fragment), so sorting happens in place; a direct visit / no-JS click
     * gets the full shell page. index() is untouched.
     *
     * @return void
     */
    public function htmxUsers(): void
    {
        $menuItems = [
            'username' => [__('username_marking'), []],
            'user_type' => [__('user_type'), []],
            'UserOnline.logged_in' => [__('userlist_online'), ['direction' => 'desc']],
            'registered' => [__('registered'), ['direction' => 'desc']],
        ];

        // 100, not the 400 that used to stand here: Cake's maxLimit defaults to
        // 100 and silently capped it, so the list showed a hundred members and
        // pretended that was all of them. It is now an honest page size with a
        // "load more" control underneath.
        $this->paginate = [
            'sortableFields' => array_keys($menuItems),
            'finder' => 'paginated',
            'limit' => 100,
            'order' => ['Users.username' => 'asc'],
        ];
        $users = $this->paginate($this->Users);
        $this->set(compact('menuItems', 'users'));

        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            // A sort click re-renders the whole list; "load more" asks for just
            // the next page's rows to append.
            $template = $this->request->getQuery('more') ? 'htmx_users_more' : 'htmx_users_rows';
            $this->viewBuilder()->disableAutoLayout()->setTemplate($template);
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_users');
        }
    }

    /**
     * A user's profile as an htmx island (strangler-fig migration).
     *
     * A slim, standalone version of {@see view()}: the profile summary plus the
     * user's recent postings (reusing the recent_posts_list element inside a
     * .js-thread-island, so the island enhances them). Login required.
     *
     * @param string|null $id user-ID
     * @return \Cake\Http\Response|void
     */
    public function htmxProfile(?string $id = null)
    {
        $id = (int)$id;
        /** @var \App\Model\Entity\User|null $user */
        $user = $this->Users->find()
            ->where(['Users.id' => $id])
            ->contain(['UserOnline', 'UserBlocks'])
            ->first();

        if (empty($user)) {
            $this->Flash->set(__('Invalid user'), ['element' => 'error']);

            return $this->redirect('/');
        }

        // Blocking lives on the profile page, not in the admin backend: the
        // permission `saito.core.user.lock.set` grants it to moderators, and the
        // backend is admin-only. This is also where a moderator already is when
        // they decide somebody needs a break.
        $mayLock = (bool)$this->CurrentUser->permission(
            'saito.core.user.lock.set',
            (new ResourceAI())->onRole($user->getRole()),
        );
        $this->set('mayLock', $mayLock);
        $this->set('blockForm', $mayLock ? new BlockForm() : null);
        $this->set('lockDurations', self::LOCK_DURATIONS);

        $entriesShownOnPage = 20;
        $this->set(
            'lastEntries',
            $this->Users->Entries->getRecentPostings(
                $this->CurrentUser,
                ['user_id' => $id, 'limit' => $entriesShownOnPage],
            ),
        );
        $this->set(
            'hasMoreEntriesThanShownOnPage',
            $user->numberOfPostings() - $entriesShownOnPage > 0,
        );
        // What ignoring looks like from here. Two different things, deliberately:
        // your own list is private and only ever shown on your own profile,
        // while the number of members ignoring somebody is public — the help has
        // described both for years, but neither survived the move to the island
        // profile. Both come from data that is already kept.
        $UserIgnores = $this->Users->UserIgnores;
        $this->set(
            'ignoredByMe',
            $this->CurrentUser->getId() === $id ? $UserIgnores->getAllIgnoredBy($id) : null,
        );
        $this->set('ignoredByOthers', $UserIgnores->countIgnored($id));

        $this->set('user', $user);
        $this->set('solved', $this->Users->countSolved($id));
        $this->set('titleForLayout', $user->get('username'));
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_profile');
    }

    /**
     * Island-styled login page (strangler-fig). Renders the form standalone in
     * the htmx_island layout; it posts to the real {@see login()} action, so the
     * authentication flow itself is untouched.
     *
     * @return \Cake\Http\Response|void
     */
    public function htmxLogin()
    {
        if ($this->CurrentUser->isLoggedIn()) {
            // Land on the island front page after login, not the profile.
            return $this->redirect(['controller' => 'Entries', 'action' => 'htmxIndex']);
        }
        $this->set('titleForLayout', __('login_btn'));
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_login');
    }

    /**
     * Island-styled registration page (strangler-fig). Mirrors {@see register()}
     * — same honeypot/TOS flow and the same security-critical
     * {@see \App\Model\Table\UsersTable::register()} + activation email — but
     * renders standalone in the htmx_island layout and posts to itself, so
     * FormProtection stays consistent. Alpine enables the submit once TOS is
     * accepted (the SPA does this in register()).
     *
     * @return void
     */
    public function htmxRegister(): void
    {
        $this->AuthUser->logout();
        $tosRequired = Configure::read('Saito.Settings.tos_enabled');
        $this->set(compact('tosRequired'));
        $this->set('user', $this->Users->newEmptyEntity());
        $this->set('status', 'view');

        // Island register modal: an HX-Request renders just the form/status
        // fragment (loaded into the shared auth overlay); a direct visit gets the
        // standalone island page.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_register_form');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_register');
        }

        $session = $this->request->getSession();
        if (!$this->request->is('post')) {
            $session->write('Register.formLoadTime', time());

            return;
        }

        if ($this->isRegisterThrottled()) {
            $this->Flash->set(__('user.authe.throttled'), ['element' => 'error']);

            return;
        }

        $data = $this->request->getData();

        // Bot protection: honeypot empty + form open ≥ 5s (same as register()).
        $formLoadTime = (int)$session->read('Register.formLoadTime');
        if (!empty($data['url']) || $formLoadTime === 0 || (time() - $formLoadTime) < 5) {
            return;
        }
        $session->delete('Register.formLoadTime');

        if (!$tosRequired) {
            $data['tos_confirm'] = true;
        }
        if (empty($data['tos_confirm'])) {
            return;
        }

        $user = $this->Users->register($data);
        if ($user->getErrors()) {
            // A duplicate address is the one error the form must not report.
            // Saying "this address is taken" answers, to anybody who asks,
            // whether a given person is a member here — and a forum's
            // membership is not public information. The throttle added in 8.3.2
            // caps how often the question can be asked; this stops it being
            // answered at all.
            //
            // The reply is instead sent to the address itself, which is the one
            // place where only its owner can read it.
            //
            // The cost, and it is a real one: somebody who mistypes their
            // address into one that belongs to another member sees "check your
            // mail" and never gets a mail, while that other member gets one
            // they did not ask for. The mail is written for exactly that
            // reader. Reporting the collision instead would mean telling every
            // passer-by who is a member here, which is the worse trade.
            if ($this->isOnlyDuplicateEmail($user->getErrors())) {
                $this->notifyExistingAccount((string)$data['user_email']);
                $this->set('status', 'success');

                return;
            }

            $user->set('tos_confirm', false);
            $this->set('user', $user);

            return;
        }

        try {
            $this->SaitoEmail->email([
                'recipient' => $user,
                'subject' => __('register_email_subject', Configure::read('Saito.Settings.forum_name')),
                'sender' => 'register',
                'template' => 'user_register',
                'viewVars' => ['user' => $user],
            ]);
        } catch (Exception $e) {
            (new ExceptionLogger())->write('Registering email confirmation failed', ['e' => $e]);
            $this->set('status', 'fail: email');

            return;
        }

        $this->set('status', 'success');
    }

    /**
     * Whether the only thing wrong with the registration is a known address.
     *
     * Deliberately narrow. If the form also failed on the username, the
     * password or the terms, the person has something to correct and must be
     * told — silently accepting *that* would leave them waiting for a mail that
     * never comes, with nothing to act on.
     *
     * @param array<string, mixed> $errors validation errors from register()
     * @return bool
     */
    private function isOnlyDuplicateEmail(array $errors): bool
    {
        if (array_keys($errors) !== ['user_email']) {
            return false;
        }

        return array_keys((array)$errors['user_email']) === ['isUnique'];
    }

    /**
     * Tell the address that somebody tried to register with it.
     *
     * Failures are swallowed on purpose: whether the mail went out must not
     * change what the form shows, or the timing and the outcome would answer
     * the same question the silence exists to avoid. It is logged instead.
     *
     * @param string $email the address somebody tried to register
     * @return void
     */
    private function notifyExistingAccount(string $email): void
    {
        try {
            /** @var \App\Model\Entity\User|null $existing */
            $existing = $this->Users->find()->where(['user_email' => $email])->first();
            if ($existing === null) {
                return;
            }

            $this->SaitoEmail->email([
                'recipient' => $existing,
                'subject' => __('register_email_existing_subject', Configure::read('Saito.Settings.forum_name')),
                'sender' => 'register',
                'template' => 'user_register_existing',
                'viewVars' => ['user' => $existing],
            ]);
        } catch (Throwable $e) {
            (new ExceptionLogger())->write('Notifying an existing account failed', ['e' => $e]);
        }
    }

    /**
     * Change one's own password as an htmx island page (strangler-fig). Mirrors
     * changepassword() but for the current user, in the htmx_island layout.
     *
     * @return \Cake\Http\Response|void
     */
    public function htmxChangePassword()
    {
        $id = $this->CurrentUser->getId();
        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($id);
        $this->set('username', $user->get('username'));

        // The settings page opens this in an overlay, so htmx gets the bare form
        // fragment; a direct visit (or a browser without JS) gets the page.
        $isHtmx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';
        $this->set('errorMessage', null);

        if ($this->request->is('post')) {
            $data = [];
            foreach (['password', 'password_old', 'password_confirm'] as $field) {
                $data[$field] = $this->request->getData($field);
            }
            $this->Users->patchEntity($user, $data);
            if ($this->Users->save($user)) {
                // Keep *this* session logged in while the changed password kicks
                // the account's other sessions on their next request.
                $this->AuthUser->refreshPasswordFingerprint((int)$id);
                $this->Flash->set(__('change_password_success'), ['element' => 'success']);
                if ($isHtmx) {
                    // A 302 would be followed by htmx and swapped into the modal
                    // body; HX-Redirect makes it a real navigation instead, so the
                    // flash is shown on the settings page as usual.
                    return $this->response->withHeader('HX-Redirect', Router::url(['action' => 'htmxEdit']));
                }

                return $this->redirect(['action' => 'htmxEdit']);
            }
            $errors = $user->getErrors();
            if (!empty($errors)) {
                $message = __d('nondynamic', current(array_pop($errors)));
                if ($isHtmx) {
                    // The fragment has no layout, so it renders the error itself.
                    $this->set('errorMessage', $message);
                } else {
                    $this->Flash->set($message, ['element' => 'error']);
                }
            }
        }

        $this->set('titleForLayout', __('change_password_link'));
        if ($isHtmx) {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_changepassword_fragment');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_changepassword');
        }
    }

    /**
     * The current user's settings as an htmx island page (strangler-fig).
     *
     * A standalone, island-styled version of {@see edit()} for one's own
     * account, using the same allowed-field patch + save. Login required.
     *
     * @return \Cake\Http\Response|void
     */
    public function htmxEdit()
    {
        $id = $this->CurrentUser->getId();
        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($id);

        if (
            !$this->CurrentUser->permission(
                'saito.core.user.edit',
                (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId()),
            )
        ) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to edit user "%s".', $id),
                ['CurrentUser' => $this->CurrentUser],
            );
        }

        if ($this->request->is(['post', 'put'])) {
            $allowedFields = [
                'user_email', 'user_real_name', 'user_hp', 'user_place',
                'profile', 'signature', 'user_theme', 'inline_view_on_click',
                'user_automaticaly_mark_as_read', 'personal_messages',
                'user_signatures_hide', 'user_signatures_images_hide',
                'user_sort_last_answer', 'user_show_thread_collapsed',
                'user_category_override',
                'user_category_custom', 'user_category_active',
                'user_color_new_postings', 'user_color_old_postings',
                'user_color_actual_posting',
            ];
            $data = $this->request->getData();
            // The thread-line colours are a tri-state: a colour, or unset so the
            // theme decides. <input type="color"> cannot say "unset" — it always
            // posts a colour — so the form pairs each picker with a "theme
            // colour" checkbox. Honour it here, otherwise saving the form would
            // silently write #000000 and dye the thread lines black.
            foreach (['user_color_new_postings', 'user_color_old_postings', 'user_color_actual_posting'] as $colourField) {
                if (!empty($data[$colourField . '_default'])) {
                    $data[$colourField] = '';
                }
                unset($data[$colourField . '_default']);
            }

            // Which categories the member wants on the front page. Saito stores
            // this as [categoryId => truthy|falsy] in user_category_custom;
            // Categories::getCustom() merges in anything new as enabled, so
            // unchecked entries have to be written as an explicit falsy value
            // rather than left out.
            if (isset($data['categories'])) {
                $readable = $this->CurrentUser->getCategories()->getAll('read', 'select');
                $picked = (array)$data['categories'];
                $custom = [];
                foreach (array_keys($readable) as $categoryId) {
                    $custom[$categoryId] = !empty($picked[$categoryId]) ? (string)$categoryId : '0';
                }
                // Unchecking everything would leave the front page with no
                // readable category at all. Treat that as "no restriction"
                // instead of an empty forum.
                $data['user_category_custom'] = array_filter($custom) === [] ? [] : $custom;
                // The stored selection only applies in 'custom' mode, which
                // means no single active category. Clear it, otherwise saving the
                // list would appear to do nothing.
                $data['user_category_active'] = 0;
                unset($data['categories']);
            }

            $patched = $this->Users->patchEntity($user, $data, ['fields' => $allowedFields]);
            if (!$patched->getErrors() && $this->Users->save($patched)) {
                $this->Flash->set(__('The user has been saved.'), ['element' => 'success']);

                return $this->redirect(['action' => 'htmxProfile', $id]);
            }
            $this->Flash->set(
                __('The user could not be saved. Please, try again.'),
                ['element' => 'error'],
            );
        }

        $availableThemes = $this->Themes->getAvailable($this->CurrentUser);
        $this->set('availableThemes', array_combine($availableThemes, $availableThemes));
        // Category picker: every readable category, and which of them are
        // currently enabled (getCustom() already merges new ones in as enabled).
        $this->set('readableCategories', $this->CurrentUser->getCategories()->getAll('read', 'select'));
        $this->set('selectedCategories', $this->CurrentUser->getCategories()->getCustom('read'));
        $this->set('user', $user);
        $this->set('titleForLayout', __('user.edit.t', [$user->get('username')]));
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_edit');
    }

    /**
     * Ignore user.
     *
     * @return void
     */
    public function ignore(): void
    {
        $this->request->allowMethod('POST');
        $blockedId = (int)$this->request->getData('id');
        $this->_ignore($blockedId, true);
    }

    /**
     * Unignore user.
     *
     * @return void
     */
    public function unignore(): void
    {
        $this->request->allowMethod('POST');
        $blockedId = (int)$this->request->getData('id');
        $this->_ignore($blockedId, false);
    }

    /**
     * Mark user as un-/ignored
     *
     * @param int $blockedId user to ignore
     * @param bool $set block or unblock
     * @return \Cake\Http\Response
     */
    protected function _ignore(int $blockedId, bool $set): Response
    {
        $userId = $this->CurrentUser->getId();
        if ((int)$userId === (int)$blockedId) {
            throw new BadRequestException();
        }
        if ($set) {
            $this->Users->UserIgnores->ignore($userId, $blockedId);
        } else {
            $this->Users->UserIgnores->unignore($userId, $blockedId);
        }

        return $this->redirect($this->referer());
    }

    /**
     * Resolve a username to a profile and redirect there.
     *
     * Kept deliberately: this is the target of every `@name` mention in posting
     * text (`MarkupSettings::atBaseUrl`), so it is written into decades of
     * existing content. It has no view of its own — it only translates a name
     * into an ID, which is why it survives the removal of the SPA.
     *
     * @param string|null $name username
     * @return \Cake\Http\Response
     */
    public function name(?string $name = null): Response
    {
        if (!empty($name)) {
            $viewedUser = $this->Users->find()
                ->select(['id'])
                ->where(['username' => $name])
                ->first();
            if (!empty($viewedUser)) {
                // Follows the active frontend: on an island install the SPA
                // profile page is the wrong destination for an @name mention.
                return $this->redirect(
                    [
                        'controller' => 'users',
                        'action' => 'htmxProfile',
                        $viewedUser->get('id'),
                    ],
                );
            }
        }
        $this->Flash->set(__('Invalid user'), ['element' => 'error']);

        return $this->redirect('/');
    }

    /**
     * Hand a member everything the forum holds about them.
     *
     * GDPR Art. 15 and 20. Until this existed the only way to answer such a
     * request was by hand, out of the database.
     *
     * **It takes no parameter, and that is the security design.** The account
     * comes from the session and nowhere else, so there is no id to substitute,
     * nothing to increment, and no permission check to get wrong — the action
     * cannot be pointed at another member because it cannot be told which member
     * to look at.
     *
     * That also means an administrator cannot pull this for somebody else, which
     * reads like a limitation and is not one: Art. 15 is a right of the person
     * the data is about, exercised by them. An admin who needs to answer a
     * request forwards it; they do not answer it on the member's behalf.
     *
     * @return \Cake\Http\Response
     */
    public function export(): Response
    {
        $this->autoRender = false;

        $userId = (int)$this->CurrentUser->getId();
        // A session whose account no longer exists. `getId()` returns 0 then,
        // and 0 is not "nobody" to a database query — `WHERE user_id = 0` is a
        // perfectly good condition. Refused explicitly rather than left to fail
        // somewhere further in, because "what does it do with 0" is exactly the
        // question nobody wants to answer after the fact.
        if ($userId < 1) {
            throw new BadRequestException();
        }
        $export = new DataExport($userId);

        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$this->CurrentUser->get('username'));
        $filename = sprintf('saito-export-%s-%s.json', $name, date('Y-m-d'));

        // Streamed, not assembled. Building the whole document as an array and
        // encoding it peaked at 174 MB for the busiest account on the reference
        // forum, against a production `memory_limit` of 128M — so the export
        // would have died precisely for the members with the most to export.
        // Written out in pieces, the peak is a batch of 500 postings.
        // Written into a buffer that spills to disk, not assembled in memory.
        // Building the whole document as an array and encoding it peaked at
        // 174 MB for the busiest account on the reference forum, against a
        // production `memory_limit` of 128M — so the export would have died
        // precisely for the members with the most to export.
        //
        // `php://temp` keeps the first 8 MB in RAM and moves to a temporary file
        // beyond that, so the peak is a batch of 500 postings plus that buffer,
        // whatever the account holds. (`CallbackStream` looks like the answer
        // and is not: it collects the callback's return value into one string.)
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $handle = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open a buffer for the export.');
        }

        $head = (string)json_encode($export->collect(), $flags);
        // The last brace only. `rtrim($head, "\n}")` looks like it does this and
        // does not: the second argument is a *set* of characters, so it ate both
        // closing braces and produced invalid JSON.
        fwrite($handle, substr($head, 0, (int)strrpos($head, '}')) . ",\n    \"postings\": [");

        $first = true;
        foreach ($export->eachPosting() as $posting) {
            fwrite($handle, $first ? "\n" : ",\n");
            // Indented to sit inside the pretty-printed envelope around it: a
            // person is meant to be able to open this file and read it.
            fwrite(
                $handle,
                '        ' . str_replace("\n", "\n        ", (string)json_encode($posting, $flags)),
            );
            $first = false;
        }
        fwrite($handle, "\n    ]\n}\n");
        rewind($handle);

        // Said here rather than relied upon. PHP's session handling already
        // emits `no-store` while a session is open, which is why the response
        // was uncacheable when this was first measured — but that is a side
        // effect of `session.cache_limiter`, and a personal-data download must
        // not depend on an ini setting staying where it is. `private` names the
        // reason as well: this belongs to one person, so no shared cache may
        // hold it even briefly.
        return $this->response
            ->withType('application/json')
            ->withHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withBody(new Stream($handle));
    }

    /**
     * Step two of a login: the second factor.
     *
     * Reached only with a pending account in the session, which is set by
     * {@see \App\Controller\Component\AuthUserComponent::login()} once a
     * password has checked out. Unauthenticated by necessity — the whole point
     * is that no identity exists yet — so the session marker, its five-minute
     * life, and the throttle below are what stand in for one.
     *
     * Accepts either the six digits from an authenticator app or one of the
     * account's single-use recovery codes; a member without their phone needs a
     * way back in that does not involve waiting for an administrator.
     *
     * @return \Cake\Http\Response|null
     */
    public function twoFactor(): ?Response
    {
        // In the login overlay this is a fragment that swaps in place; a direct
        // visit (or a browser without JavaScript) gets the standalone page.
        $isHx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';
        if ($isHx) {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_two_factor_form');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_two_factor');
        }
        $this->set('errorMessage', null);

        $userId = $this->AuthUser->pendingSecondFactorUserId();
        if ($userId === null) {
            // Nothing pending, or it went stale. Back to the start rather than
            // a form that cannot lead anywhere. HX-Redirect, not a 302: htmx
            // would follow a redirect and swap a whole login page into the
            // modal body.
            $login = Router::url(['controller' => 'Users', 'action' => 'htmxLogin']);

            return $isHx
                ? $this->response->withHeader('HX-Redirect', $login)
                : $this->redirect($login);
        }

        if (!$this->request->is('post')) {
            return null;
        }

        // A budget of its own, per client: the password is already spent, so
        // without this the second factor would be a six-digit number somebody
        // could sit and guess.
        if ($this->_isLoginThrottled()) {
            $this->set('errorMessage', __('user.authe.throttled'));

            return null;
        }

        $code = (string)$this->request->getData('code');
        $Credentials = $this->fetchTable('TwoFactorCredentials');
        $Codes = $this->fetchTable('TwoFactorRecoveryCodes');

        $ok = $Credentials->verifyCode($userId, $code) || $Codes->consume($userId, $code);
        if (!$ok) {
            $this->_registerFailedLogin();
            // A credential encrypted under a salt this installation no longer
            // has can never match, however right the code is. Saying "wrong
            // code" to that is a dead end the member cannot reason about, so
            // name it and point at the way that still works.
            $unreadable = !$Credentials->isReadableFor($userId);
            $message = $unreadable ? __('user.2fa.unreadable') : __('user.2fa.error');
            (new ForbiddenLogger())->write(
                $unreadable
                    ? "Unreadable second-factor secret for user id: $userId (salt changed?)"
                    : "Failed second factor for user id: $userId",
                ['msgs' => [$message]],
            );
            $this->set('errorMessage', $message);

            return null;
        }

        if (!$this->AuthUser->completeSecondFactor($userId)) {
            $login = Router::url(['controller' => 'Users', 'action' => 'htmxLogin']);

            return $isHx
                ? $this->response->withHeader('HX-Redirect', $login)
                : $this->redirect($login);
        }
        $this->_clearLoginThrottle();

        $session = $this->getRequest()->getSession();
        $target = (string)$session->read('Saito.pending2faTarget');
        $session->delete('Saito.pending2faTarget');
        $target = $target !== '' ? $target : '/';

        // A full navigation once the member is in, the same way an ordinary
        // login finishes — the overlay has nothing left to show.
        return $isHx
            ? $this->response->withHeader('HX-Redirect', $target)
            : $this->redirect($target);
    }

    /**
     * The member's own second-factor settings: enrol, or turn it off.
     *
     * One page with three states — off, half-enrolled, on — because they are
     * three views of one question ("is my account protected?") and splitting
     * them across pages would make the answer harder to find than the setting.
     *
     * @return \Cake\Http\Response|null
     */
    public function htmxTwoFactor(): ?Response
    {
        $userId = (int)$this->CurrentUser->getId();
        $Credentials = $this->fetchTable('TwoFactorCredentials');
        $Codes = $this->fetchTable('TwoFactorRecoveryCodes');

        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_two_factor_settings');
        $this->set('errorMessage', null);
        $this->set('recoveryCodes', null);

        $action = (string)$this->request->getData('do');
        if ($this->request->is('post') && $action === 'start') {
            // A fresh secret, shown once as a QR code. Any earlier half-finished
            // attempt is replaced, so an abandoned QR cannot be confirmed later.
            $secret = $Credentials->beginEnrolment($userId);
            $this->request->getSession()->write('Saito.2faEnrolSecret', $secret);
        }

        if ($this->request->is('post') && $action === 'confirm') {
            $code = (string)$this->request->getData('code');
            if ($Credentials->confirmEnrolment($userId, $code)) {
                // Only now is the account actually protected — and only now are
                // recovery codes worth handing out. Shown once; they exist as
                // hashes afterwards.
                $this->set('recoveryCodes', $Codes->issueFor($userId));
                $this->request->getSession()->delete('Saito.2faEnrolSecret');
            } else {
                $this->set('errorMessage', __('user.2fa.error'));
            }
        }

        if ($this->request->is('post') && $action === 'disable') {
            // The password again, deliberately. Turning the second factor off
            // is the one action in here that makes the account weaker, and a
            // borrowed session should not be able to do it quietly.
            if (!$this->verifyCurrentPassword((string)$this->request->getData('password'))) {
                $this->set('errorMessage', __('user.2fa.password.wrong'));
            } else {
                $Credentials->disableFor($userId);
                $Codes->clearFor($userId);
                $this->Flash->set(__('user.2fa.disabled'), ['element' => 'success']);

                return $this->redirect(['action' => 'htmxTwoFactor']);
            }
        }

        if ($this->request->is('post') && $action === 'newCodes') {
            if (!$this->verifyCurrentPassword((string)$this->request->getData('password'))) {
                $this->set('errorMessage', __('user.2fa.password.wrong'));
            } else {
                $this->set('recoveryCodes', $Codes->issueFor($userId));
            }
        }

        $pending = $Credentials->pendingFor($userId);
        $secret = (string)$this->request->getSession()->read('Saito.2faEnrolSecret');
        $this->set('isEnabled', $Credentials->isEnabledFor($userId));
        $this->set('isEnrolling', $pending !== null && $secret !== '');
        $this->set('secret', $secret);
        $this->set('provisioningUri', $secret !== ''
            ? $Credentials->provisioningUri($secret, (string)$this->CurrentUser->get('username'))
            : null);
        $this->set('remainingCodes', $Codes->remainingFor($userId));
        $this->set('titleForLayout', __('user.2fa.settings.t'));

        return null;
    }

    /**
     * Does the given password belong to the member who is logged in?
     *
     * @param string $password what they typed
     * @return bool
     */
    private function verifyCurrentPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $user = $this->Users->get((int)$this->CurrentUser->getId(), fields: ['id', 'password']);

        return (new DefaultPasswordHasher())->check($password, (string)$user->get('password'));
    }

    /**
     * Records that the current member agrees to the terms as they stand now.
     *
     * The button on the re-consent interstitial
     * ({@see \App\Controller\AppController::requireTermsAcceptance()}) posts
     * here. Self-scoped by construction: the account comes from the session and
     * the version from the setting, so there is nothing a caller can substitute
     * — replaying this POST records agreement to the version already in force,
     * which is what the member just did anyway.
     *
     * @return \Cake\Http\Response
     */
    public function tosAccept(): Response
    {
        $this->request->allowMethod(['post']);

        $version = (int)Configure::read('Saito.Settings.tos_version');
        $userId = (int)$this->CurrentUser->getId();
        $this->Users->updateAll(['tos_accepted_version' => $version], ['id' => $userId]);

        // Keep the session copy in step: the redirect below is still this
        // request, and the gate would otherwise catch it on the way out.
        $this->CurrentUser->set('tos_accepted_version', $version);

        return $this->redirect(['controller' => 'Entries', 'action' => 'htmxIndex']);
    }

    /**
     * The current user's bookmarked postings, as an htmx/Alpine island.
     *
     * Strangler-fig migration of the profile "bookmarks" tab (the live one is a
     * JSON API rendered client-side by the SPA). Loads the user's bookmarks and
     * their postings render-ready via the `entry` finder (same path as
     * getRecentPostings), then renders them as thread lines the shared island
     * enhances. Served standalone in the htmx_island layout. Login required.
     *
     * @return void
     */
    public function bookmarks(): void
    {
        $Bookmarks = $this->fetchTable('Bookmarks.Bookmarks');
        $bookmarks = $Bookmarks->find(
            conditions: ['Bookmarks.user_id' => $this->CurrentUser->getId()],
            order: ['Bookmarks.id' => 'DESC'],
        )->all();

        $comments = [];
        $entryIds = [];
        foreach ($bookmarks as $bookmark) {
            $entryIds[] = $bookmark->get('entry_id');
            $comments[$bookmark->get('entry_id')] = $bookmark->get('comment');
        }

        $postings = [];
        if (!empty($entryIds)) {
            $categories = $this->CurrentUser->getCategories()->getAll('read');
            // Use the Entries table directly, not $this->Users->Entries (that is
            // the Users→Entries association, whose join condition leaks
            // `Users.id` into a standalone query).
            $entries = $this->fetchTable('Entries')->find(
                'entry',
                conditions: [
                    'Entries.id IN' => $entryIds,
                    'Entries.category_id IN' => $categories,
                ],
            )->enableHydration(false)->all();

            // Wrap as postings, then restore the bookmark order (id DESC).
            // Hydration is disabled above, so each row is a plain array.
            $byId = [];
            foreach ($entries as $entry) {
                /** @var array<string, mixed> $entry */
                $byId[$entry['id']] = (new Posting($entry))->withCurrentUser($this->CurrentUser);
            }
            foreach ($entryIds as $entryId) {
                if (isset($byId[$entryId])) {
                    $postings[] = $byId[$entryId];
                }
            }
        }

        $this->set('bookmarkPostings', $postings);
        $this->set('bookmarkComments', $comments);
        $this->set('titleForLayout', __('bkm.title.pl'));

        // htmx (the header "bookmarks" toggle) gets just the card fragment; a
        // direct visit gets the full standalone page.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('bookmarks_fragment');
        } else {
            $this->viewBuilder()->setLayout('htmx_island');
        }
    }

    /**
     * The note a member can attach to one of their own bookmarks.
     *
     * The bookmarks page has always rendered these notes, but the only thing
     * that could write one was the REST endpoint the SPA used
     * (`PUT /api/v2/bookmarks/{id}`). With the SPA gone, notes written back then
     * were still shown and could no longer be changed, and no new one could be
     * made — so this brings the writing half back into the island.
     *
     * Addressed by posting id and scoped to the current user's own bookmark:
     * there is no id here that could point at somebody else's row.
     *
     * GET returns the edit form, POST saves and returns the note as displayed.
     *
     * @param string|null $id posting id
     * @return void
     */
    public function htmxBookmarkComment(?string $id = null): void
    {
        $entryId = (int)$id;
        if ($entryId <= 0) {
            throw new BadRequestException();
        }

        $Bookmarks = $this->fetchTable('Bookmarks.Bookmarks');
        // The plugin ships no entity class of its own, so rows come back as
        // plain Cake entities.
        /** @var \Cake\Datasource\EntityInterface|null $bookmark */
        $bookmark = $Bookmarks->find()
            ->where([
                'Bookmarks.entry_id' => $entryId,
                'Bookmarks.user_id' => $this->CurrentUser->getId(),
            ])
            ->first();
        if ($bookmark === null) {
            throw new NotFoundException();
        }

        if ($this->getRequest()->is(['post', 'put'])) {
            $Bookmarks->patchEntity(
                $bookmark,
                ['comment' => (string)$this->getRequest()->getData('comment')],
                ['fields' => ['comment']],
            );
            if (!$Bookmarks->save($bookmark)) {
                throw new BadRequestException();
            }
        }

        $this->set('entryId', $entryId);
        $this->set('comment', (string)$bookmark->get('comment'));
        $this->set('editing', !$this->getRequest()->is(['post', 'put']));
        $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_bookmark_comment');
    }

    /**
     * Avatar upload/delete for the htmx island settings — same logic as
     * {@see avatar()} but redirects back to the island settings (htmxEdit).
     * CSRF-only (FormProtection-unlocked); permission is owner/edit-scoped.
     *
     * @param string|null $id user id
     * @return \Cake\Http\Response
     */
    public function htmxAvatar(?string $id = null): Response
    {
        $id = (int)$id;
        // get() raises RecordNotFoundException (404) for an unknown id.
        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($id);

        $permission = $this->CurrentUser->permission(
            'saito.core.user.edit',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId()),
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                "Attempt to edit avatar for user $id.",
                ['CurrentUser' => $this->CurrentUser],
            );
        }

        if ($this->request->is(['post', 'put'])) {
            $data = [
                'avatar' => $this->request->getData('avatar'),
                'avatarDelete' => $this->request->getData('avatarDelete'),
            ];
            if (!empty($data['avatarDelete'])) {
                $data = ['avatar' => null, 'avatar_dir' => null];
            }
            $patched = $this->Users->patchEntity($user, $data);
            if (empty($patched->getErrors()) && $this->Users->save($patched)) {
                $this->Flash->set(__('gn.saved'), ['element' => 'success']);
            } else {
                $this->Flash->set(
                    __('The user could not be saved. Please, try again.'),
                    ['element' => 'error'],
                );
            }
        }

        return $this->redirect(['action' => 'htmxEdit']);
    }

    /**
     * Lock user.
     *
     * @return \Cake\Http\Response|void
     * @throws \Cake\Http\Exception\BadRequestException
     */
    public function lock()
    {
        $form = new BlockForm();
        if (!$form->validate($this->request->getData())) {
            throw new BadRequestException();
        }

        $id = (int)$this->request->getData('lockUserId');

        /** @var \App\Model\Entity\User */
        $readUser = $this->Users->get($id);

        $permission = $this->CurrentUser->permission(
            'saito.core.user.lock.set',
            (new ResourceAI())->onRole($readUser->getRole()),
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                null,
                ['CurrentUser' => $this->CurrentUser],
            );
        }

        // Bounded rather than taken as sent: the field is a plain number in the
        // request, so without this a crafted POST could set a block of any
        // length it liked. Checked outside the try below, which swallows every
        // exception into a generic flash message.
        $duration = (int)$this->request->getData('lockPeriod');
        // Zero (or an absent field) means an open-ended block — ManualBlocker
        // writes no end date for a falsy duration. That is a real moderation
        // outcome and long-standing behaviour, so it stays allowed; it is also
        // an honest, visible state rather than a smuggled-in 37-year ban.
        if (
            $duration !== 0
            && ($duration < self::LOCK_MIN
                || $duration > self::LOCK_MAX
                || $duration % self::LOCK_STEP !== 0)
        ) {
            throw new BadRequestException(
                sprintf('Lock duration "%d" is outside the allowed range.', $duration),
            );
        }

        if ($this->CurrentUser->isUser($readUser)) {
            $message = __('You can\'t lock yourself.');
            $this->Flash->set($message, ['element' => 'error']);
        } else {
            try {
                $blocker = new ManualBlocker($this->CurrentUser->getId(), $duration);
                $status = $this->Users->UserBlocks->block($blocker, $id);
                if (!$status) {
                    throw new Exception();
                }
                $message = __('User {0} is locked.', $readUser->get('username'));
                $this->Flash->set($message, ['element' => 'success']);
            } catch (Exception $e) {
                $message = __('Error while locking.');
                $this->Flash->set($message, ['element' => 'error']);
            }
        }

        return $this->redirect($this->referer());
    }

    /**
     * Unblock user.
     *
     * @param string $id user-ID
     * @return \Cake\Http\Response|void
     */
    public function unlock(string $id)
    {
        // The template has always used postLink(); the action simply never
        // insisted, leaving a lured moderator's GET able to lift a block.
        $this->request->allowMethod(['post']);

        $id = (int)$id;

        /** @var \App\Model\Entity\User|null */
        $user = $this->Users
            ->find()
            ->matching('UserBlocks', function ($q) use ($id) {
                return $q->where(['UserBlocks.id' => $id]);
            })
            ->first();

        // No such block: a second click on "unblock", or a click from a page
        // rendered before somebody else lifted it. That used to reach
        // getRole() on null and answer a moderator's click with a 500.
        if ($user === null) {
            throw new RecordNotFoundException('No such user block.');
        }

        $permission = $this->CurrentUser->permission(
            'saito.core.user.lock.set',
            (new ResourceAI())->onRole($user->getRole()),
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                null,
                ['CurrentUser' => $this->CurrentUser],
            );
        }

        if (!$this->Users->UserBlocks->unblock($id)) {
            $this->Flash->set(
                __('Error while unlocking.'),
                ['element' => 'error'],
            );

            // Without the return, the success message below was set as well and
            // the moderator was told both that it failed and that it worked.
            return $this->redirect($this->referer());
        }

        $message = __('User {0} is unlocked.', $user->get('username'));
        $this->Flash->set($message, ['element' => 'success']);

        return $this->redirect($this->referer());
    }

    /**
     * Store which right-rail widgets the member keeps minimised.
     *
     * Written to `users.slidetab_order` — the column the retired slidetabs used
     * for the same purpose, which arrangement of the rail this member prefers.
     * Reusing it keeps this a code change rather than a migration.
     *
     * @return \Cake\Http\Response
     */
    public function htmxWidgetState(): Response
    {
        if (!$this->getRequest()->is('post')) {
            throw new BadRequestException();
        }

        $value = WidgetPreferences::write(
            (array)$this->getRequest()->getData('order'),
            (array)$this->getRequest()->getData('widgets'),
            EntriesController::WIDGETS,
        );

        $userId = (int)$this->CurrentUser->getId();
        $user = $this->Users->get($userId);
        $this->Users->patchEntity($user, ['slidetab_order' => $value]);
        if (!$this->Users->save($user)) {
            // Only mirror what actually reached the database. Updating the
            // session regardless made a failed save look like it worked: the
            // rail kept the new arrangement until the next login quietly put
            // the old one back.
            throw new BadRequestException('Widget arrangement could not be saved.');
        }
        // Keep the session copy in step, or the next render would still show the
        // old arrangement until the member logs in again.
        $this->CurrentUser->set('slidetab_order', $value);

        return $this->getResponse()->withStringBody('');
    }

    /**
     * @inheritDoc
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        Stopwatch::start('Users->beforeFilter()');

        $unlocked = [
            'htmxEdit', 'htmxChangePassword', 'htmxAvatar',
            // Posted by the island with a CSRF token in the header, like the
            // other island write endpoints.
            'htmxWidgetState', 'htmxBookmarkComment', 'htmxTwoFactor',
            // The terms re-consent button. Its form is rendered from
            // `Controller.initialize` (AppController::requireTermsAcceptance),
            // which runs before FormProtection sets its token up in
            // `Controller.startup`, so the emitted token never matches and every
            // click was blackholed. Unlocking costs nothing here: the form
            // carries no data fields at all — a submit button and nothing to
            // tamper with — and CSRF, which is the protection that matters, is
            // middleware and stays on.
            'tosAccept',
        ];
        $this->FormProtection->setConfig('unlockedActions', $unlocked);

        $this->Authentication->allowUnauthenticated(
            ['login', 'logout', 'rs', 'htmxLogin', 'htmxRegister', 'htmxForgotPassword', 'htmxResetPassword', 'twoFactor'],
        );
        $this->AuthUser->authorizeAction('htmxRegister', 'saito.core.user.register');
        $this->AuthUser->authorizeAction('rs', 'saito.core.user.register');

        // Login form times-out and degrades user experience.
        // See https://github.com/Schlaefer/Saito/issues/339
        //
        // `twoFactor` is the same login, one step later, and has to be treated
        // the same way — not as a nicety but because it cannot work otherwise:
        // its form is rendered by `login`, where FormProtection is unloaded and
        // so emits no `_Token`, and posting that form into an action where
        // FormProtection is active blackholes it. The visible symptom was a
        // button that did nothing at all, because htmx does not swap a 403.
        // CSRF still covers the request; that is middleware, not this component.
        if (
            in_array($this->getRequest()->getParam('action'), ['login', 'twoFactor'], true)
            && $this->components()->has('FormProtection')
        ) {
            $this->components()->unload('FormProtection');
        }

        Stopwatch::stop('Users->beforeFilter()');
    }

    /**
     * Logout user if logged in and create response to revisit logged out
     *
     * @return \Cake\Http\Response|null
     */
    protected function _logoutAndComeHereAgain(): ?Response
    {
        if (!$this->CurrentUser->isLoggedIn()) {
            return null;
        }
        $this->AuthUser->logout();

        return $this->redirect($this->getRequest()->getRequestTarget());
    }
}
