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
use App\Model\Entity\User;
use Cake\Cache\Cache;
use Saito\Posting\Posting;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Routing\Router;
use Saito\App\Registry;
use Saito\Exception\Logger\ExceptionLogger;
use Saito\Exception\Logger\ForbiddenLogger;
use Saito\Exception\SaitoForbiddenException;
use Saito\User\Blocker\ManualBlocker;
use Saito\User\Permission\Permissions;
use Saito\User\Permission\ResourceAI;
use Saito\User\WidgetPreferences;
use Stopwatch\Lib\Stopwatch;

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
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->addHelpers([
            'SpectrumColorpicker.SpectrumColorpicker',
            'Posting',
            'Text',
        ]);
        $this->loadComponent('Referer');
    }

    /**
     * Login user.
     *
     * @return void|Response
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
                    ['element' => 'warning', 'params' => ['title' => __('user.authe.required.t')]]
                );
            };

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

        if ($this->AuthUser->login()) {
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
        /** @var User|null $User */
        $User = $this->Users->find()
            ->where(['username' => $username])
            ->first();

        $message = $this->_failedLoginMessage($User, $username);

        // don't autofill password
        $this->setRequest($this->getRequest()->withData('password', ''));

        $Logger = new ForbiddenLogger();
        $Logger->write(
            "Unsuccessful login for user: $username",
            ['msgs' => [$message]]
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
     * @param User|null $User the account matching the submitted username, if any
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
     * @return void|Response
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
            $this->redirect($redirect);
        } else {
            $this->redirect('/');
        }
    }

    /**
     * register success (user clicked link in confirm mail)
     *
     * @param string $id user-ID
     * @return void
     * @throws BadRequestException
     */
    public function rs($id = null)
    {
        if (!$id) {
            throw new BadRequestException();
        }
        // Cast so a missing `?c=` is an empty string (a failed activation), not
        // a TypeError that the Exception catch below would not catch.
        $code = (string)$this->request->getQuery('c');
        try {
            $activated = $this->Users->activate((int)$id, $code);
        } catch (\Exception $e) {
            $activated = false;
        }
        if (!$activated) {
            $activated = ['status' => 'fail'];
        }
        $this->set('status', $activated['status']);
        // Activation landing (reached from the email link) — island-styled.
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_rs');
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
    public function htmxUsers()
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
    public function htmxProfile($id = null)
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
            (new ResourceAI())->onRole($user->getRole())
        );
        $this->set('mayLock', $mayLock);
        $this->set('blockForm', $mayLock ? new BlockForm() : null);
        $this->set('lockDurations', self::LOCK_DURATIONS);

        $entriesShownOnPage = 20;
        $this->set(
            'lastEntries',
            $this->Users->Entries->getRecentPostings(
                $this->CurrentUser,
                ['user_id' => $id, 'limit' => $entriesShownOnPage]
            )
        );
        $this->set(
            'hasMoreEntriesThanShownOnPage',
            ($user->numberOfPostings() - $entriesShownOnPage) > 0
        );
        // What ignoring looks like from here. Two different things, deliberately:
        // your own list is private and only ever shown on your own profile,
        // while the number of members ignoring somebody is public — the help has
        // described both for years, but neither survived the move to the island
        // profile. Both come from data that is already kept.
        $UserIgnores = $this->Users->UserIgnores;
        $this->set(
            'ignoredByMe',
            $this->CurrentUser->getId() === $id ? $UserIgnores->getAllIgnoredBy($id) : null
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
    public function htmxRegister()
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
        } catch (\Exception $e) {
            (new ExceptionLogger())->write('Registering email confirmation failed', ['e' => $e]);
            $this->set('status', 'fail: email');

            return;
        }

        $this->set('status', 'success');
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
                (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId())
            )
        ) {
            throw new \Saito\Exception\SaitoForbiddenException(
                sprintf('Attempt to edit user "%s".', $id),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if ($this->request->is(['post', 'put'])) {
            $allowedFields = [
                'user_email', 'user_real_name', 'user_hp', 'user_place',
                'profile', 'signature', 'user_theme', 'inline_view_on_click',
                'user_automaticaly_mark_as_read', 'personal_messages',
                'user_signatures_hide', 'user_signatures_images_hide',
                'user_sort_last_answer', 'user_show_thread_collapsed',
                'user_category_override', 'user_forum_refresh_time',
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
                ['element' => 'error']
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
    public function ignore()
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
    public function unignore()
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
    protected function _ignore($blockedId, $set)
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
     * @param string $name username
     * @return void
     */
    public function name($name = null)
    {
        if (!empty($name)) {
            $viewedUser = $this->Users->find()
                ->select(['id'])
                ->where(['username' => $name])
                ->first();
            if (!empty($viewedUser)) {
                // Follows the active frontend: on an island install the SPA
                // profile page is the wrong destination for an @name mention.
                $this->redirect(
                    [
                        'controller' => 'users',
                        'action' => 'htmxProfile',
                        $viewedUser->get('id'),
                    ]
                );

                return;
            }
        }
        $this->Flash->set(__('Invalid user'), ['element' => 'error']);
        $this->redirect('/');
    }

    /**
     * A user's recent postings, delivered server-rendered for htmx.
     *
     * PoC for the strangler-fig migration away from the Backbone/Marionette
     * SPA: the same data source ({@see \Saito\Posting\Behavior\PostingBehavior::getRecentPostings})
     * and thread rendering as {@see view()}, but served as an HTML fragment
     * instead of a client-side template.
     *
     * - A normal request renders a small standalone shell page (htmx + Alpine).
     * - An htmx request (`HX-Request` header) renders only the thread-list
     *   fragment, which htmx swaps into the shell.
     *
     * @param string|null $id user-ID
     * @return \Cake\Http\Response|void
     */
    public function recentPosts($id = null)
    {
        $id = (int)$id;

        /** @var \App\Model\Entity\User|null $user */
        $user = $this->Users->find()
            ->where(['Users.id' => $id])
            ->first();

        if (empty($user)) {
            $this->Flash->set(__('Invalid user'), ['element' => 'error']);

            return $this->redirect('/');
        }

        $entriesShownOnPage = 20;
        $this->set(
            'lastEntries',
            $this->Users->Entries->getRecentPostings(
                $this->CurrentUser,
                ['user_id' => $id, 'limit' => $entriesShownOnPage]
            )
        );
        $this->set(
            'hasMoreEntriesThanShownOnPage',
            ($user->numberOfPostings() - $entriesShownOnPage) > 0
        );
        $this->set('user', $user);
        $this->set('titleForLayout', $user->get('username'));

        // htmx swaps only the fragment; a direct visit gets the shell page,
        // served standalone (no SPA) via the htmx_island layout so the SPA and
        // the island don't fight over the same thread markup.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()
                ->disableAutoLayout()
                ->setTemplate('recent_posts_fragment');
        } else {
            $this->viewBuilder()->setLayout('htmx_island');
        }
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
    public function bookmarks()
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
     * Avatar upload/delete for the htmx island settings — same logic as
     * {@see avatar()} but redirects back to the island settings (htmxEdit).
     * CSRF-only (FormProtection-unlocked); permission is owner/edit-scoped.
     *
     * @param string|null $id user id
     * @return \Cake\Http\Response
     */
    public function htmxAvatar($id = null)
    {
        $id = (int)$id;
        // get() raises RecordNotFoundException (404) for an unknown id.
        /** @var User $user */
        $user = $this->Users->get($id);

        $permission = $this->CurrentUser->permission(
            'saito.core.user.edit',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId())
        );
        if (!$permission) {
            throw new \Saito\Exception\SaitoForbiddenException(
                "Attempt to edit avatar for user $id.",
                ['CurrentUser' => $this->CurrentUser]
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
                    ['element' => 'error']
                );
            }
        }

        return $this->redirect(['action' => 'htmxEdit']);
    }

    /**
     * Lock user.
     *
     * @return \Cake\Http\Response|void
     * @throws BadRequestException
     */
    public function lock()
    {
        $form = new BlockForm();
        if (!$form->validate($this->request->getData())) {
            throw new BadRequestException();
        }

        $id = (int)$this->request->getData('lockUserId');

        /** @var User */
        $readUser = $this->Users->get($id);

        $permission = $this->CurrentUser->permission(
            'saito.core.user.lock.set',
            (new ResourceAI())->onRole($readUser->getRole())
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                null,
                ['CurrentUser' => $this->CurrentUser]
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
                sprintf('Lock duration "%d" is outside the allowed range.', $duration)
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
                    throw new \Exception();
                }
                $message = __('User {0} is locked.', $readUser->get('username'));
                $this->Flash->set($message, ['element' => 'success']);
            } catch (\Exception $e) {
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
     * @return void
     */
    public function unlock(string $id)
    {
        $id = (int)$id;

        /** @var User */
        $user = $this->Users
            ->find()
            ->matching('UserBlocks', function ($q) use ($id) {
                return $q->where(['UserBlocks.id' => $id]);
            })
            ->first();

        $permission = $this->CurrentUser->permission(
            'saito.core.user.lock.set',
            (new ResourceAI())->onRole($user->getRole())
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                null,
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if (!$this->Users->UserBlocks->unblock($id)) {
            $this->Flash->set(
                __('Error while unlocking.'),
                ['element' => 'error']
            );
        }

        $message = __('User {0} is unlocked.', $user->get('username'));
        $this->Flash->set($message, ['element' => 'success']);
        $this->redirect($this->referer());
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

        $submitted = (array)$this->getRequest()->getData('widgets');
        $value = WidgetPreferences::write($submitted, EntriesController::WIDGETS);

        $userId = (int)$this->CurrentUser->getId();
        $user = $this->Users->get($userId);
        $this->Users->patchEntity($user, ['slidetab_order' => $value]);
        $this->Users->save($user);
        // Keep the session copy in step, or the next render would still show the
        // old arrangement until the member logs in again.
        $this->CurrentUser->set('slidetab_order', $value);

        return $this->getResponse()->withStringBody('');
    }

    /**
     * {@inheritdoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        Stopwatch::start('Users->beforeFilter()');

        $unlocked = [
            'slidetabToggle', 'slidetabOrder', 'htmxEdit', 'htmxChangePassword', 'htmxAvatar',
            // Posted by the island with a CSRF token in the header, like the
            // other island write endpoints.
            'htmxWidgetState',
        ];
        $this->FormProtection->setConfig('unlockedActions', $unlocked);

        $this->Authentication->allowUnauthenticated(['login', 'logout', 'rs', 'htmxLogin', 'htmxRegister']);
        $this->AuthUser->authorizeAction('htmxRegister', 'saito.core.user.register');
        $this->AuthUser->authorizeAction('rs', 'saito.core.user.register');

        // Login form times-out and degrades user experience.
        // See https://github.com/Schlaefer/Saito/issues/339
        if (
            ($this->getRequest()->getParam('action') === 'login')
            && $this->components()->has('FormProtection')
        ) {
            $this->components()->unload('FormProtection');
        }

        Stopwatch::stop('Users->beforeFilter()');
    }

    /**
     * Logout user if logged in and create response to revisit logged out
     *
     * @return Response|null
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
