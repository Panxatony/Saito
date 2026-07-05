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
use Stopwatch\Lib\Stopwatch;

/**
 * User controller
 */
class UsersController extends AppController
{
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
            // Redirect query-param in URL.
            $target = $this->getRequest()->getQuery('redirect');
            // AuthenticationService puts the full local path into the redirect
            // parameter, so we have to strip the base-path off again.
            $target = $target ? Router::normalize($target) : '';
            // Prevent open-redirects: only accept local paths. Reject absolute
            // (https://evil.com) and protocol-relative (//evil.com, /\evil.com)
            // URLs that would otherwise send the user off-site after login.
            if ($target !== '' && !preg_match('#^/(?![/\\\\])#', $target)) {
                $target = '';
            }
            // Referer from Request
            // Referer fallback only if it points somewhere other than the
            // login form itself; otherwise send the user to the front-page.
            if (empty($target)) {
                $referer = $this->referer('/', true);
                if ($referer && strpos($referer, '/login') === false) {
                    $target = $referer;
                }
            }

            if (empty($target)) {
                $target = '/';
            }

            return $this->redirect($target);
        }

        /// error on login
        $this->_registerFailedLogin();
        $username = $this->request->getData('username');
        /** @var User */
        $User = $this->Users->find()
            ->where(['username' => $username])
            ->first();

        $message = __('user.authe.e.generic');

        if (!empty($User)) {
            if (!$User->isActivated()) {
                $message = __('user.actv.ny');
            } elseif ($User->isLocked()) {
                $ends = $this->Users->UserBlocks
                    ->getBlockEndsForUser($User->getId());
                if ($ends) {
                    $time = new DateTime($ends);
                    $data = [
                        'name' => $username,
                        'end' => $time->timeAgoInWords(['accuracy' => 'hour']),
                    ];
                    $message = __('user.block.pubExpEnds', $data);
                } else {
                    $message = __('user.block.pubExp', $username);
                }
            }
        }

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
        $this->redirect('/');
    }

    /**
     * Register new user.
     *
     * @return void|Response
     */
    public function register()
    {
        $this->set('status', 'view');

        $this->AuthUser->logout();

        $tosRequired = Configure::read('Saito.Settings.tos_enabled');
        $this->set(compact('tosRequired'));

        $user = $this->Users->newEmptyEntity();
        $this->set('user', $user);

        $session = $this->request->getSession();

        if (!$this->request->is('post')) {
            $session->write('Register.formLoadTime', time());
            $logout = $this->_logoutAndComeHereAgain();
            if ($logout) {
                return $logout;
            }

            return;
        }

        $data = $this->request->getData();

        // Bot protection: honeypot field must be empty, form must have been
        // open for at least 5 seconds (bots submit instantly).
        $formLoadTime = (int)$session->read('Register.formLoadTime');
        if (!empty($data['url']) || $formLoadTime === 0 || (time() - $formLoadTime) < 5) {
            $this->set('user', $this->Users->newEmptyEntity());

            return;
        }
        $session->delete('Register.formLoadTime');

        if (!$tosRequired) {
            $data['tos_confirm'] = true;
        }
        $tosConfirmed = $data['tos_confirm'];
        if (!$tosConfirmed) {
            return;
        }

        $user = $this->Users->register($data);

        $errors = $user->getErrors();
        if (!empty($errors)) {
            // registering failed, show form again
            if (isset($errors['password'])) {
                $user->setErrors($errors);
            }
            $user->set('tos_confirm', false);
            $this->set('user', $user);

            return;
        }

        // registered successfully
        try {
            $forumName = Configure::read('Saito.Settings.forum_name');
            $subject = __('register_email_subject', $forumName);
            $this->SaitoEmail->email(
                [
                    'recipient' => $user,
                    'subject' => $subject,
                    'sender' => 'register',
                    'template' => 'user_register',
                    'viewVars' => ['user' => $user],
                ]
            );
        } catch (\Exception $e) {
            $Logger = new ExceptionLogger();
            $Logger->write(
                'Registering email confirmation failed',
                ['e' => $e]
            );
            $this->set('status', 'fail: email');

            return;
        }

        $this->set('status', 'success');
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
        $code = $this->request->getQuery('c');
        try {
            $activated = $this->Users->activate((int)$id, $code);
        } catch (\Exception $e) {
            $activated = false;
        }
        if (!$activated) {
            $activated = ['status' => 'fail'];
        }
        $this->set('status', $activated['status']);
    }

    /**
     * Show list of all users.
     *
     * @return void
     */
    public function index()
    {
        $menuItems = [
            'username' => [__('username_marking'), []],
            'user_type' => [__('user_type'), []],
            'UserOnline.logged_in' => [
                __('userlist_online'),
                ['direction' => 'desc'],
            ],
            'registered' => [__('registered'), ['direction' => 'desc']],
        ];
        $showBlocked = $this->CurrentUser->permission('saito.core.user.lock.view');
        if ($showBlocked) {
            $menuItems['user_lock'] = [
                __('user.set.lock.t'),
                ['direction' => 'desc'],
            ];
        }

        $this->paginate = $options = [
            'contain' => ['UserOnline'],
            // `sortableFields` (renamed from the removed `sortWhitelist` in
            // CakePHP 5) restricts sorting to these columns — otherwise a user
            // could sort the list by any column, incl. sensitive ones like
            // `password`/`activate_code`, as an ordering oracle.
            'sortableFields' => array_keys($menuItems),
            'finder' => 'paginated',
            'limit' => 400,
            // Default order when no column is selected. Kept as username ASC —
            // what the list has always shown (the paginated finder used to force
            // this order, which is also what broke sorting by other columns).
            'order' => [
                'Users.username' => 'asc',
            ],
        ];
        $users = $this->paginate($this->Users);

        $showBottomNavigation = true;

        $this->set(compact('menuItems', 'showBottomNavigation', 'users'));
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
     * Show user with profile $name
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
                $this->redirect(
                    [
                        'controller' => 'users',
                        'action' => 'view',
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
     * View user profile.
     *
     * @param null $id user-ID
     * @return \Cake\Http\Response|void
     */
    public function view($id = null)
    {
        // redirect view/<username> to name/<username>
        if (!empty($id) && !is_numeric($id)) {
            $this->redirect(
                ['controller' => 'users', 'action' => 'name', $id]
            );

            return;
        }

        $id = (int)$id;

        /** @var User */
        $user = $this->Users->find()
            ->contain(
                [
                    'UserBlocks' => function ($q) {
                        return $q->find('assocUsers');
                    },
                    'UserOnline',
                ]
            )
            ->where(['Users.id' => (int)$id])
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

        if ($this->CurrentUser->getId() === $id) {
            $ignores = $this->Users->UserIgnores->getAllIgnoredBy($id);
            $user->set('ignores', $ignores);
        }

        $blockForm = new BlockForm();
        $solved = $this->Users->countSolved($id);
        $this->set(compact('blockForm', 'solved', 'user'));
        $this->set('titleForLayout', $user->get('username'));
    }

    /**
     * Set user avatar.
     *
     * @param string $userId user-ID
     * @return void|\Cake\Http\Response
     */
    public function avatar($userId)
    {
        if (!$this->Users->exists($userId)) {
            throw new BadRequestException();
        }

        /** @var User */
        $user = $this->Users->get($userId);

        $permissionEditing = $this->CurrentUser->permission(
            'saito.core.user.edit',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId())
        );
        if (!$permissionEditing) {
            throw new \Saito\Exception\SaitoForbiddenException(
                "Attempt to edit user $userId.",
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if ($this->request->is('post') || $this->request->is('put')) {
            $data = [
                'avatar' => $this->request->getData('avatar'),
                'avatarDelete' => $this->request->getData('avatarDelete'),
            ];
            if (!empty($data['avatarDelete'])) {
                $data = [
                    'avatar' => null,
                    'avatar_dir' => null,
                ];
            }
            $patched = $this->Users->patchEntity($user, $data);
            $errors = $patched->getErrors();
            if (empty($errors) && $this->Users->save($patched)) {
                return $this->redirect(['action' => 'edit', $userId]);
            } else {
                $this->Flash->set(
                    __('The user could not be saved. Please, try again.'),
                    ['element' => 'error']
                );
            }
        }

        $this->set('user', $user);

        $this->set(
            'titleForPage',
            __('user.avatar.edit.t', [$user->get('username')])
        );
    }

    /**
     * Edit user.
     *
     * @param null $id user-ID
     *
     * @return \Cake\Http\Response|void
     */
    public function edit($id = null)
    {
        /** @var User */
        $user = $this->Users->get($id);

        $permissionEditing = $this->CurrentUser->permission(
            'saito.core.user.edit',
            (new ResourceAI())->onRole($user->getRole())->onOwner($user->getId())
        );
        if (!$permissionEditing) {
            throw new \Saito\Exception\SaitoForbiddenException(
                sprintf('Attempt to edit user "%s".', $user->get('id')),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if ($this->request->is('post') || $this->request->is('put')) {
            $data = $this->request->getData();
            $allowedFields = [
                'username',
                'user_email',
                'user_real_name',
                'user_hp',
                'user_place',
                'profile',
                'signature',
                'user_sort_last_answer',
                'user_automaticaly_mark_as_read',
                'user_signatures_hide',
                'user_signatures_images_hide',
                'user_forum_refresh_time',
                'user_theme',
                'user_color_new_postings',
                'user_color_old_postings',
                'user_color_actual_posting',
                'inline_view_on_click',
                'user_show_thread_collapsed',
                'personal_messages',
                'user_category_override',
            ];
            $patched = $this->Users->patchEntity($user, $data, ['fields' => $allowedFields]);
            $errors = $patched->getErrors();
            if (empty($errors) && $this->Users->save($patched)) {
                return $this->redirect(['action' => 'view', $id]);
            }

            $this->Flash->set(
                __('The user could not be saved. Please, try again.'),
                ['element' => 'error']
            );
        }
        $this->set('user', $user);

        $this->set(
            'titleForPage',
            __('user.edit.t', [$user->get('username')])
        );

        $availableThemes = $this->Themes->getAvailable($this->CurrentUser);
        $availableThemes = array_combine($availableThemes, $availableThemes);
        $currentTheme = $this->Themes->getThemeForUser($this->CurrentUser);
        $this->set(compact('availableThemes', 'currentTheme'));
    }

    /**
     * delete user
     *
     * @param string $id user-ID
     * @return \Cake\Http\Response|void
     */
    public function delete($id)
    {
        $id = (int)$id;
        /** @var User */
        $readUser = $this->Users->get($id);

        /// Check permission
        $permission = $this->CurrentUser->permission(
            'saito.core.user.delete',
            (new ResourceAI())->onRole($readUser->getRole())
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                'Not allowed to delete a user.',
                ['CurrentUser' => $this->CurrentUser, 'user_id' => $readUser->get('username')]
            );
        }

        $this->set('user', $readUser);

        $failure = false;
        if (!$this->request->getData('userdeleteconfirm')) {
            $failure = true;
            $this->Flash->set(__('user.del.fail.3'), ['element' => 'error']);
        } elseif ($this->CurrentUser->isUser($readUser)) {
            $failure = true;
            $this->Flash->set(__('user.del.fail.1'), ['element' => 'error']);
        }

        if (!$failure) {
            $result = $this->Users->deleteAllExceptEntries($id);
            if (empty($result)) {
                $failure = true;
                $this->Flash->set(__('user.del.fail.2'), ['element' => 'error']);
            }
        }

        if ($failure) {
            return $this->redirect(
                [
                    'prefix' => false,
                    'controller' => 'users',
                    'action' => 'view',
                    $id,
                ]
            );
        }

        $this->Flash->set(
            __('user.del.ok.m', $readUser->get('username')),
            ['element' => 'success']
        );

        return $this->redirect('/');
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

        if ($this->CurrentUser->isUser($readUser)) {
            $message = __('You can\'t lock yourself.');
            $this->Flash->set($message, ['element' => 'error']);
        } else {
            try {
                $duration = (int)$this->request->getData('lockPeriod');
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
     * changes user password
     *
     * @param null $id user-ID
     * @return void
     * @throws \Saito\Exception\SaitoForbiddenException
     * @throws BadRequestException
     */
    public function changepassword($id = null)
    {
        if (empty($id)) {
            throw new BadRequestException();
        }

        /** @var User */
        $user = $this->Users->get($id);
        $allowed = $this->CurrentUser->isUser($user);
        if (empty($user) || !$allowed) {
            throw new SaitoForbiddenException(
                "Attempt to change password for user $id.",
                ['CurrentUser' => $this->CurrentUser]
            );
        }
        $this->set('userId', $id);
        $this->set('username', $user->get('username'));

        //= just show empty form
        if (empty($this->request->getData())) {
            return;
        }

        $formFields = ['password', 'password_old', 'password_confirm'];

        //= process submitted form
        $data = [];
        foreach ($formFields as $field) {
            $data[$field] = $this->request->getData($field);
        }
        $this->Users->patchEntity($user, $data);
        $success = $this->Users->save($user);

        if ($success) {
            $this->Flash->set(
                __('change_password_success'),
                ['element' => 'success']
            );
            $this->redirect(['controller' => 'users', 'action' => 'edit', $id]);

            return;
        }

        $errors = $user->getErrors();
        if (!empty($errors)) {
            $this->Flash->set(
                __d('nondynamic', current(array_pop($errors))),
                ['element' => 'error']
            );
        }

        //= unset all autofill form data
        foreach ($formFields as $field) {
            $this->request = $this->request->withoutData($field);
        }
    }

    /**
     * Directly set password for user
     *
     * @param string $id user-ID
     * @return Response|null
     */
    public function setpassword($id)
    {
        /** @var User */
        $user = $this->Users->get($id);

        if (!$this->CurrentUser->permission('saito.core.user.password.set', (new ResourceAI())->onRole($user->getRole()))) {
            throw new SaitoForbiddenException(
                "Attempt to set password for user $id.",
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if ($this->getRequest()->is('post')) {
            $this->Users->patchEntity($user, $this->getRequest()->getData(), ['fields' => 'password']);

            if ($this->Users->save($user)) {
                $this->Flash->set(
                    __('user.pw.set.s'),
                    ['element' => 'success']
                );

                return $this->redirect(['controller' => 'users', 'action' => 'edit', $id]);
            }
            $errors = $user->getErrors();
            if (!empty($errors)) {
                $this->Flash->set(
                    __d('nondynamic', current(array_pop($errors))),
                    ['element' => 'error']
                );
            }
        }

        $this->set(compact('user'));
    }

    /**
     * View and set user role
     *
     * @param string $id User-ID
     * @return void|Response
     */
    public function role($id)
    {
        /** @var User */
        $user = $this->Users->get($id);
        $identifier = (new ResourceAI())->onRole($user->getRole());
        $unrestricted = $this->CurrentUser->permission('saito.core.user.role.set.unrestricted', $identifier);
        $restricted = $this->CurrentUser->permission('saito.core.user.role.set.restricted', $identifier);
        if (!$restricted && !$unrestricted) {
            throw new SaitoForbiddenException(
                null,
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        /** @var Permissions */
        $Permissions = Registry::get('Permissions');

        $roles = $Permissions->getRoles()->get($this->CurrentUser->getRole(), false, $unrestricted);

        if ($this->getRequest()->is('put')) {
            $type = $this->getRequest()->getData('user_type');
            if (!in_array($type, $roles)) {
                throw new \InvalidArgumentException(
                    sprintf('User type "%s" is not available.', $type),
                    1573376871
                );
            }
            $patched = $this->Users->patchEntity($user, ['user_type' => $type]);

            $errors = $patched->getErrors();
            if (empty($errors)) {
                $this->Users->save($patched);

                return $this->redirect(['action' => 'edit', $user->get('id')]);
            }

            $msg = current(current($errors));
            $this->Flash->set($msg, ['element' => 'error']);
        }

        $this->set(compact('roles', 'user'));
    }

    /**
     * Set slidetab-order.
     *
     * @return \Cake\Http\Response
     * @throws BadRequestException
     */
    public function slidetabOrder()
    {
        if (!$this->request->is('ajax')) {
            throw new BadRequestException();
        }

        $order = $this->request->getData('slidetabOrder');
        if (!$order) {
            throw new BadRequestException();
        }

        $allowed = $this->Slidetabs->getAvailable();
        $order = array_filter(
            $order,
            function ($item) use ($allowed) {
                return in_array($item, $allowed);
            }
        );
        $order = serialize($order);

        $userId = $this->CurrentUser->getId();
        $user = $this->Users->get($userId);
        $this->Users->patchEntity($user, ['slidetab_order' => $order]);
        $this->Users->save($user);

        $this->CurrentUser->set('slidetab_order', $order);

        return $this->getResponse()->withStringBody('1');
    }

    /**
     * Shows user's uploads
     *
     * @return void
     */
    public function uploads()
    {
    }

    /**
     * Set category for user.
     *
     * @param string|null $id category-ID
     * @return \Cake\Http\Response
     */
    public function setcategory(?string $id = null)
    {
        $userId = $this->CurrentUser->getId();
        if ($id === 'all') {
            $this->Users->setCategory($userId, 'all');
        } elseif (!$id && $this->request->getData()) {
            $data = $this->request->getData('CatChooser');
            $this->Users->setCategory($userId, $data);
        } else {
            $this->Users->setCategory($userId, $id);
        }

        return $this->redirect($this->referer());
    }

    /**
     * {@inheritdoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        Stopwatch::start('Users->beforeFilter()');

        $unlocked = ['slidetabToggle', 'slidetabOrder'];
        $this->FormProtection->setConfig('unlockedActions', $unlocked);

        $this->Authentication->allowUnauthenticated(['login', 'logout', 'register', 'rs']);
        $this->AuthUser->authorizeAction('register', 'saito.core.user.register');
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
