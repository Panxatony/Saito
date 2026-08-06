<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller\Component;

use App\Controller\AppController;
use App\Model\Entity\User;
use App\Model\Table\TwoFactorCredentialsTable;
use App\Model\Table\TwoFactorTrustedDevicesTable;
use App\Model\Table\UsersTable;
use Authentication\Authenticator\CookieAuthenticator;
use Authentication\Authenticator\StatelessInterface;
use Authentication\Controller\Component\AuthenticationComponent;
use Cake\Controller\Component;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Http\Cookie\Cookie;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;
use Saito\Exception\SaitoForbiddenException;
use Saito\RememberTrait;
use Saito\User\Auth\LoginResult;
use Saito\User\Cookie\Storage;
use Saito\User\CurrentUser\CurrentUser;
use Saito\User\CurrentUser\CurrentUserFactory;
use Saito\User\CurrentUser\CurrentUserInterface;
use Stopwatch\Lib\Stopwatch;

/**
 * Authenticates the current user and bootstraps the CurrentUser information
 *
 * @property AuthenticationComponent $Authentication
 */
#[\AllowDynamicProperties]
class AuthUserComponent extends Component
{
    use RememberTrait;

    /**
     * Component name
     *
     * @var string
     */
    public $name = 'CurrentUser';

    /**
     * Session key holding the fingerprint of the account password this session
     * was established with — see {@see self::sessionMatchesPassword()}.
     *
     * @var string
     */
    public const PW_FINGERPRINT_KEY = 'Saito.pwFingerprint';

    /**
     * Session key holding the account whose password checked out but whose
     * second factor is still owed. See {@see self::login()}.
     *
     * @var string
     */
    public const PENDING_2FA_KEY = 'Saito.pending2fa';

    /**
     * How long a verified password stays redeemable for its second factor, in
     * seconds. Long enough to fetch a phone, short enough that walking away
     * from a shared machine does not leave a login lying around.
     *
     * @var int
     */
    public const PENDING_2FA_TTL = 300;

    /**
     * Cookie carrying the token that says "this device has proved the second
     * factor". Derived from the remember-me cookie's name because it is that
     * cookie's companion: on its own it authenticates nothing.
     *
     * @var string
     */
    public const TRUSTED_DEVICE_COOKIE_SUFFIX = '-2FA';

    /**
     * Component's components
     *
     * No `@var` here — see the note in ParserHelper::$helpers: the native type
     * says `array`, and restating it loosely widens what CakePHP 5.4 declares.
     */
    public array $components = [
        'Authentication.Authentication',
    ];

    /**
     * Current user
     *
     * @var CurrentUserInterface
     */
    protected $CurrentUser;

    /**
     * UsersTableInstance
     *
     * @var UsersTable
     */
    protected $UsersTable = null;

    /**
     * Array of authorized actions 'action' => 'resource'
     *
     * @var array
     */
    private $actionAuthorizationResources = [];

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        Stopwatch::start('CurrentUser::initialize()');

        /** @var UsersTable */
        $UsersTable = TableRegistry::getTableLocator()->get('Users');
        $this->UsersTable = $UsersTable;

        $controller = $this->getController();
        $request = $controller->getRequest();
        // Bots/crawlers and guests are counted per client IP (hashed), not per
        // session: cookieless clients start a new session on every request,
        // which massively inflated the online counts. Salted so the raw IP is
        // not stored.
        $ipHash = md5(Security::getSalt() . $request->clientIp()); // salted online-list id, never stores a raw IP; not password hashing skipcq: PHP-A1004

        // Authenticate first, even for bots: a feed reader is classified as a
        // bot (for the online count), but it may still present a valid
        // personalized-feed token and must then be served as that logged-in
        // user. Only fall back to bot/guest handling when there is no identity.
        $user = $this->authenticate();
        // Drop a session whose account password has changed since it began — a
        // password reset (or change) then logs out every *other* device the
        // account was signed in on, so a hijacked session cannot outlive the
        // reset. Only for stateful (session/cookie) logins: a stateless token or
        // JWT re-presents its credential each request and holds no fingerprint.
        if (!empty($user) && !$this->isStatelessAuth() && !$this->sessionMatchesPassword($user)) {
            $this->Authentication->logout();
            $controller->getRequest()->getSession()->delete(self::PW_FINGERPRINT_KEY);
            $user = null;
        }
        // A remember-me cookie is not by itself a second factor. It is
        // stateless — it validates against username and password hash — so one
        // minted before the account enrolled cannot be told apart from a later
        // one, cannot be revoked, and would otherwise walk straight past 2FA
        // for as long as it lives. So for an enrolled account the cookie is
        // only honoured alongside a device token issued *after* a second factor
        // was proved. Cookies from before enrolment carry no token and are
        // still refused; see {@see \App\Model\Table\TwoFactorTrustedDevicesTable}.
        if (!empty($user) && $this->isCookieAuth()
            && $this->twoFactorCredentials()->isEnabledFor((int)$user->get('id'))
            && !$this->trustedDevices()->isTrusted((int)$user->get('id'), $this->readTrustedDeviceToken())
        ) {
            $this->Authentication->logout();
            $user = null;
        }
        if (!empty($user)) {
            $CurrentUser = CurrentUserFactory::createLoggedIn($user->toArray());
            $this->UsersTable->UserOnline->setOnline((string)$CurrentUser->getId(), true);
        } elseif ($this->isBot()) {
            $CurrentUser = CurrentUserFactory::createDummy();
            // Track detected bots/crawlers separately (uuid "bot" prefix) so
            // they can be shown apart from human guests instead of ignored.
            $this->UsersTable->UserOnline->setOnline('bot' . substr($ipHash, 0, 29), false);
        } else {
            $CurrentUser = CurrentUserFactory::createVisitor($controller);
            $this->UsersTable->UserOnline->setOnline($ipHash, false);
        }

        $this->setCurrentUser($CurrentUser);

        Stopwatch::stop('CurrentUser::initialize()');
    }

    /**
     * {@inheritDoc}
     */
    public function startup()
    {
        if (!$this->isAuthorized($this->CurrentUser)) {
            throw new SaitoForbiddenException(null, ['CurrentUser' => $this->CurrentUser]);
        }
    }

    /**
     * Detects if the current user is a bot
     *
     * @return bool
     */
    public function isBot()
    {
        // Prefer the Detectors component directly over the request's `is('bot')`
        // detector: at the time this component runs that detector is not
        // reliably registered on the request, so `is('bot')` returned false even
        // for obvious crawlers (bots were then miscounted as guests). Fall back
        // to `is('bot')` where the Detectors component isn't loaded (e.g. tests).
        $controller = $this->getController();
        $registry = $controller->components();
        /** @var \Detectors\Controller\Component\DetectorsComponent|null $detectors */
        $detectors = $registry->has('Detectors') ? $registry->get('Detectors') : null;
        $isBot = $detectors !== null
            ? $detectors->isBot()
            : $controller->getRequest()->is('bot');

        return $this->remember('isBot', $isBot);
    }

    /**
     * Tries to log-in a user
     *
     * Call this from controllers to authenticate manually (from login-form-data).
     *
     * Where the account carries a confirmed second factor this stops half-way
     * on purpose: the password is verified, the identity is **not** set, and
     * the pending account is parked in the session for the challenge to pick
     * up. Nothing downstream of `setIdentity()` runs — which is what keeps the
     * remember-me cookie from being minted before the second factor, the bypass
     * that would otherwise turn 2FA into decoration.
     *
     * @return LoginResult what happened; see the enum
     */
    public function login(): LoginResult
    {
        // Capture the authentication provider that succeeded BEFORE we
        // destroy session/auth data — logout() resets _successfulAuthenticator
        // and refreshAuthenticationProvider() needs to know if a cookie
        // authenticator was used in this request.
        $authenticationProvider = $this->Authentication
            ->getAuthenticationService()
            ->getAuthenticationProvider();

        // destroy any existing session or Authentication-data
        $this->logout();

        // non-logged in session-id is lost after Authentication
        $originalSessionId = session_id();

        $user = $this->authenticate();

        if (!$user) {
            // login failed
            return LoginResult::Failed;
        }

        // Stop here when a second factor is owed. Everything below this line —
        // the identity, the remember-me cookie, the login counter — belongs to
        // a completed login, and this one is not completed yet.
        if ($this->twoFactorCredentials()->isEnabledFor((int)$user->get('id'))) {
            $this->beginSecondFactor((int)$user->get('id'));

            return LoginResult::SecondFactorRequired;
        }

        $this->Authentication->setIdentity($user);
        $this->refreshAuthenticationProvider($authenticationProvider);
        $CurrentUser = CurrentUserFactory::createLoggedIn($user->toArray());
        $this->setCurrentUser($CurrentUser);

        $this->UsersTable->incrementLogins($user);
        $this->UsersTable->UserOnline->setOffline($originalSessionId);

        /// password update
        $password = (string)$this->getController()->getRequest()->getData('password');
        if ($password) {
            $this->UsersTable->autoUpdatePassword($this->CurrentUser->getId(), $password);
        }

        // Stamp this session with the account's current password fingerprint —
        // read after any rehash above — so a later password change elsewhere
        // invalidates it. See {@see self::sessionMatchesPassword()}.
        $this->refreshPasswordFingerprint((int)$user->get('id'));

        return LoginResult::LoggedIn;
    }

    /**
     * Finish a login that stopped for its second factor.
     *
     * The challenge action calls this once the code (or a recovery code) has
     * been verified. It does the half of {@see self::login()} that was skipped:
     * the identity, the remember-me cookie, the login counter, the password
     * fingerprint. There is no password to re-check here — it was checked to
     * get this far, and the pending marker in the session is what says so.
     *
     * @param int $userId the account from the pending marker
     * @return bool whether the account could be loaded and logged in
     */
    public function completeSecondFactor(int $userId): bool
    {
        $user = $this->UsersTable->find('profile')->where(['Users.id' => $userId])->first();
        // `instanceof`, not a null check: the account is named by a session
        // marker, and anything that does not resolve to a real user row must
        // end the attempt rather than be carried further.
        if (!$user instanceof User) {
            $this->clearSecondFactor();

            return false;
        }

        // Read before clearing the marker: it is what carries the member's
        // "stay signed in" from the password form to here.
        $remember = $this->wasRememberMeRequested();
        if ($remember) {
            // The cookie authenticator looks for the checkbox in the request it
            // is persisting, and that request is the code form, which has no
            // such field. Restate the answer the member already gave.
            $controller = $this->getController();
            $controller->setRequest($controller->getRequest()->withData('remember_me', '1'));
        }

        $this->Authentication->setIdentity($user);
        $CurrentUser = CurrentUserFactory::createLoggedIn($user->toArray());
        $this->setCurrentUser($CurrentUser);

        if ($remember) {
            // Only now, with the factor actually proved, does this device earn
            // the right to be let back in by its cookie alone.
            $this->issueTrustedDevice($userId);
        }

        $this->UsersTable->incrementLogins($user);
        $this->refreshPasswordFingerprint($userId);
        $this->clearSecondFactor();

        return true;
    }

    /**
     * Did the member tick "stay signed in" back on the password form?
     *
     * @return bool
     */
    private function wasRememberMeRequested(): bool
    {
        $pending = $this->getController()->getRequest()->getSession()->read(self::PENDING_2FA_KEY);

        return is_array($pending) && !empty($pending['remember']);
    }

    /**
     * Park the account whose password checked out, awaiting its second factor.
     *
     * A user id, a timestamp, and whether "stay signed in" was ticked —
     * deliberately nothing else: this marker is the only thing standing between
     * a correct password and a session, so it carries no capability of its own
     * and expires.
     *
     * The checkbox has to be remembered here because it lives on the password
     * form, while the cookie it asks for can only be minted a step later, once
     * the second factor is in. Forgetting to carry it across is what made
     * "stay signed in" silently stop working for enrolled accounts.
     *
     * @param int $userId account
     * @return void
     */
    private function beginSecondFactor(int $userId): void
    {
        $session = $this->getController()->getRequest()->getSession();
        $session->write(self::PENDING_2FA_KEY, [
            'userId' => $userId,
            'at' => time(),
            'remember' => !empty($this->getController()->getRequest()->getData('remember_me')),
        ]);
    }

    /**
     * The account awaiting its second factor, if one is and the wait has not
     * gone stale.
     *
     * The window is short on purpose. A password that checked out five minutes
     * ago should not still be redeemable on a shared machine somebody walked
     * away from.
     *
     * @return int|null the account id, or null if there is nothing pending
     */
    public function pendingSecondFactorUserId(): ?int
    {
        $pending = $this->getController()->getRequest()->getSession()->read(self::PENDING_2FA_KEY);
        if (!is_array($pending) || empty($pending['userId'])) {
            return null;
        }
        if (time() - (int)($pending['at'] ?? 0) > self::PENDING_2FA_TTL) {
            $this->clearSecondFactor();

            return null;
        }

        return (int)$pending['userId'];
    }

    /**
     * Forget the pending account — completed, expired, or abandoned.
     *
     * @return void
     */
    public function clearSecondFactor(): void
    {
        $this->getController()->getRequest()->getSession()->delete(self::PENDING_2FA_KEY);
    }

    /**
     * Trust this device, and put the proof in a cookie.
     *
     * The cookie carries a token and nothing else — no account, no claim. On
     * its own it authenticates nobody; it only unlocks a remember-me cookie
     * that would otherwise be refused, and only for the account the token was
     * issued to.
     *
     * Its flags mirror the remember-me cookie's, because the two travel
     * together and a weaker flag on either is a weaker flag on both.
     *
     * @param int $userId account that just proved its second factor
     * @return void
     */
    private function issueTrustedDevice(int $userId): void
    {
        $token = $this->trustedDevices()->issueFor($userId);

        $controller = $this->getController();
        $cookie = (new Cookie($this->trustedDeviceCookieName(), $token))
            ->withExpiry(new \DateTimeImmutable('+' . TwoFactorTrustedDevicesTable::TRUST_DAYS . ' days'))
            ->withPath($controller->getRequest()->getAttribute('webroot'))
            ->withHttpOnly(true)
            ->withSecure(str_starts_with((string)Configure::read('App.fullBaseUrl'), 'https'))
            ->withSameSite('Lax');

        $controller->setResponse($controller->getResponse()->withCookie($cookie));
    }

    /**
     * The device token this request brought along, if any.
     *
     * @return string|null
     */
    private function readTrustedDeviceToken(): ?string
    {
        $token = $this->getController()->getRequest()->getCookie($this->trustedDeviceCookieName());

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Stop trusting the device making this request, and take its cookie back.
     *
     * Only this device: signing out on a phone has no business signing out the
     * laptop, which is the whole point of having a record per device.
     *
     * @return void
     */
    private function forgetTrustedDevice(): void
    {
        $token = $this->readTrustedDeviceToken();
        if ($token === null) {
            return;
        }

        $this->trustedDevices()->forgetToken($token);

        $controller = $this->getController();
        $expired = (new Cookie($this->trustedDeviceCookieName(), ''))
            ->withExpired()
            ->withPath($controller->getRequest()->getAttribute('webroot'));
        $controller->setResponse($controller->getResponse()->withCookie($expired));
    }

    /**
     * @return string
     */
    private function trustedDeviceCookieName(): string
    {
        return (string)Configure::read('Security.cookieAuthName') . self::TRUSTED_DEVICE_COOKIE_SUFFIX;
    }

    /**
     * @return \App\Model\Table\TwoFactorTrustedDevicesTable
     */
    private function trustedDevices(): TwoFactorTrustedDevicesTable
    {
        /** @var \App\Model\Table\TwoFactorTrustedDevicesTable $table */
        $table = TableRegistry::getTableLocator()->get('TwoFactorTrustedDevices');

        return $table;
    }

    /**
     * @return \App\Model\Table\TwoFactorCredentialsTable
     */
    private function twoFactorCredentials(): TwoFactorCredentialsTable
    {
        /** @var \App\Model\Table\TwoFactorCredentialsTable $table */
        $table = TableRegistry::getTableLocator()->get('TwoFactorCredentials');

        return $table;
    }

    /**
     * Tries to authenticate and login the user.
     *
     * @return null|User User if is logged-in, null otherwise.
     */
    protected function authenticate(): ?User
    {
        $result = $this->Authentication->getResult();

        $loginFailed = !$result->isValid();
        if ($loginFailed) {
            return null;
        }

        $data = $result->getData();

        // Resolve a user id from whatever Cake Authentication handed us
        // (full User entity from the Session authenticator, JWT payload
        // with 'sub', or a plain identity array) and *always* reload the
        // row from the DB. The Cake-3 era code relied on AuthComponent's
        // identify=true for this; Cake-4's Session authenticator caches
        // the entity in the session, so without a manual reload changes
        // to user settings (e.g. inline_view_on_click) wouldn't take
        // effect until the user logs out and back in.
        $array = [];
        if ($data instanceof User) {
            $userId = $data->get('id');
        } else {
            $array = $data instanceof \ArrayAccess
                ? (array)($data instanceof \ArrayObject ? $data->getArrayCopy() : $data)
                : (array)$data;
            $userId = $array['sub'] ?? $array['id'] ?? null;
        }

        if ($userId !== null) {
            $user = $this->UsersTable
                ->find('profile')
                ->where(['Users.id' => $userId])
                ->first();
            if ($user === null) {
                return null;
            }
        } elseif (!empty($array['username'])) {
            // Fall-back: session/JWT only carries a username — look it up.
            $user = $this->UsersTable
                ->find('profile')
                ->where(['Users.username' => $array['username']])
                ->first();
            if ($user === null) {
                return null;
            }
        } else {
            $user = new User($array, ['markNew' => false, 'markClean' => true]);
        }

        // activate_code/user_lock might be absent for mocked sessions in
        // unit tests; treat missing as "ok" rather than "unactivated/locked".
        $isUnactivated = isset($user['activate_code']) && $user['activate_code'] !== 0;
        $isLocked = isset($user['user_lock']) && $user['user_lock'] == true;

        if ($isUnactivated || $isLocked) {
            /// User isn't allowed to be logged-in
            // Destroy any existing (session) storage information.
            $this->logout();

            return null;
        }

        return $user;
    }

    /**
     * Logs-out user: clears session data and cookies.
     *
     * @return void
     */
    public function logout(): void
    {
        if (!empty($this->CurrentUser)) {
            if ($this->CurrentUser->isLoggedIn()) {
                $this->UsersTable->UserOnline->setOffline($this->CurrentUser->getId());
            }
            $this->setCurrentUser(CurrentUserFactory::createVisitor($this->getController()));
        }
        $this->getController()->getRequest()->getSession()->delete(self::PW_FINGERPRINT_KEY);
        $this->clearSecondFactor();
        $this->forgetTrustedDevice();
        $this->Authentication->logout();
    }

    /**
     * Was this request authenticated by the remember-me cookie?
     *
     * @return bool
     */
    private function isCookieAuth(): bool
    {
        return $this->Authentication
            ->getAuthenticationService()
            ->getAuthenticationProvider() instanceof CookieAuthenticator;
    }

    /**
     * Is the current request authenticated by a stateless provider?
     *
     * Stateless authenticators (feed token, JWT) re-present their credential on
     * every request and keep no server-side session, so the password-fingerprint
     * guard neither applies to them nor has a session to read.
     *
     * @return bool
     */
    private function isStatelessAuth(): bool
    {
        $provider = $this->Authentication
            ->getAuthenticationService()
            ->getAuthenticationProvider();

        return $provider instanceof StatelessInterface;
    }

    /**
     * Does this session still belong to the account's current password?
     *
     * A session is stamped at login with a fingerprint of the account password
     * (see {@see self::refreshPasswordFingerprint()}). When the password later
     * changes — a self-service reset, an admin change, a "change password" from
     * another device — every session carrying the old fingerprint stops
     * matching and is dropped on its next request. That is what turns a password
     * reset into "log out everywhere".
     *
     * A session that predates this mechanism carries no fingerprint; it adopts
     * the current one and is trusted this once, so existing logins are not all
     * kicked the moment the feature ships.
     *
     * @param User $user the freshly loaded account (carries the current hash)
     * @return bool
     */
    private function sessionMatchesPassword(User $user): bool
    {
        $session = $this->getController()->getRequest()->getSession();
        $current = hash('sha256', (string)$user->get('password'));
        $stored = $session->read(self::PW_FINGERPRINT_KEY);
        if ($stored === null) {
            $session->write(self::PW_FINGERPRINT_KEY, $current);

            return true;
        }

        return hash_equals($current, (string)$stored);
    }

    /**
     * Stamp the current session with an account's present password fingerprint.
     *
     * Called at login and after a logged-in password change, so the session
     * that performed the change keeps matching while the account's *other*
     * sessions fall out of sync and are dropped.
     *
     * @param int $userId account whose current password to fingerprint
     * @return void
     */
    public function refreshPasswordFingerprint(int $userId): void
    {
        $user = $this->UsersTable->get($userId, fields: ['id', 'password']);
        $this->getController()->getRequest()->getSession()->write(
            self::PW_FINGERPRINT_KEY,
            hash('sha256', (string)$user->get('password'))
        );
    }

    /**
     * Fires on Controller.shutdown (Cake 5 maps that event to a component's
     * afterFilter(), not shutdown()). Clears the leftover JWT cookie.
     *
     * {@inheritDoc}
     */
    public function afterFilter(\Cake\Event\EventInterface $event)
    {
        $this->clearJwtCookie($event->getSubject());
    }

    /**
     * Update persistent authentication providers for regular visitors.
     *
     * Users who visit somewhat regularly shall not be logged-out.
     *
     * @return void
     */
    private function refreshAuthenticationProvider($authenticationProvider = null)
    {
        // Persistent login provider is cookie based. Every time that cookie is
        // used for a login its expiry is pushed forward.
        if ($authenticationProvider instanceof CookieAuthenticator) {
            $controller = $this->getController();

            $cookieKey = $authenticationProvider->getConfig('cookie.name');
            $cookie = $controller->getRequest()->getCookieCollection()->get($cookieKey);
            if (empty($cookieKey) || empty($cookie)) {
                throw new \RuntimeException(
                    sprintf('Auth-cookie "%s" not found for refresh.', $cookieKey),
                    1569739698
                );
            }

            // Keys mirror the cookie config in AuthenticationServiceFactory
            // (Cake 5 spelling). Re-apply the security flags too: the cookie
            // parsed from the request carries none, so without this the rolling
            // refresh would strip HttpOnly/Secure/SameSite again.
            $cookieConfig = $authenticationProvider->getConfig('cookie');
            $refreshedCookie = $cookie
                ->withExpiry($cookieConfig['expires'])
                // Can't read path from cookies, so the default would be root '/'.
                ->withPath($this->getController()->getRequest()->getAttribute('webroot'))
                ->withHttpOnly(!empty($cookieConfig['httponly']))
                ->withSecure(!empty($cookieConfig['secure']))
                ->withSameSite($cookieConfig['samesite'] ?? null);

            $response = $controller->getResponse()->withCookie($refreshedCookie);
            $controller->setResponse($response);
        }
    }

    /**
     * Removes the JWT cookie Saito used to mint on every logged-in request.
     *
     * It existed so the SPA's JavaScript could read a bearer token and send it
     * to /api/v2 — hence `http => false`, i.e. deliberately readable by script.
     * The SPA is gone, and the server never accepted it anyway: Cake's
     * TokenAuthenticator reads the Authorization header and the query parameter,
     * never a cookie. What was left was a script-readable, daily-refreshed
     * credential for a CSRF-exempt API that no client calls.
     *
     * Kept as a deletion rather than simply dropped, so the cookies already sitting
     * in members' browsers go away on their next visit instead of lingering for
     * another day. Once installs have turned over this can go entirely.
     *
     * @param Controller $controller The controller
     * @return void
     */
    private function clearJwtCookie(Controller $controller): void
    {
        $cookieKey = Configure::read('Session.cookie') . '-JWT';
        $cookie = new Storage($controller, $cookieKey, ['http' => false]);

        if ($cookie->read()) {
            $cookie->delete();
        }
    }

    /**
     * Returns the current-user
     *
     * @return CurrentUserInterface
     */
    public function getUser(): CurrentUserInterface
    {
        return $this->CurrentUser;
    }

    /**
     * Makes the current user available throughout the application
     *
     * @param CurrentUserInterface $CurrentUser current-user to set
     * @return void
     */
    private function setCurrentUser(CurrentUserInterface $CurrentUser): void
    {
        $this->CurrentUser = $CurrentUser;

        /** @var AppController */
        $controller = $this->getController();
        // makes CurrentUser available in Controllers
        $controller->CurrentUser = $this->CurrentUser;
        // makes CurrentUser available as View var in templates
        $controller->set('CurrentUser', $this->CurrentUser);
    }

    /**
     * The controller action will be authorized with a permission resource.
     *
     * @param string $action The controller action to authorize.
     * @param string $resource The permission resource token.
     * @return void
     */
    public function authorizeAction(string $action, string $resource)
    {
        $this->actionAuthorizationResources[$action] = $resource;
    }

    /**
     * Check if user is authorized to access the current action.
     *
     * @param CurrentUser $user The current user.
     * @return bool True if authorized False otherwise.
     */
    private function isAuthorized(CurrentUser $user)
    {
        $request = $this->getController()->getRequest();

        /// Authorize action through resource
        $action = $request->getParam('action');
        if (isset($this->actionAuthorizationResources[$action])) {
            return $user->permission($this->actionAuthorizationResources[$action]);
        }

        /// Authorize admin area
        $prefix = $request->getParam('prefix');
        $plugin = $request->getParam('plugin');
        $isAdminRoute = ($prefix && strtolower($prefix) === 'admin')
            || ($plugin && strtolower($plugin) === 'admin');
        if ($isAdminRoute) {
            return $user->permission('saito.core.admin.backend');
        }

        return true;
    }
}
