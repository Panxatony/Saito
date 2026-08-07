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

use App\Controller\Component\AuthUserComponent;
use App\Controller\Component\RefererComponent;
use App\Controller\Component\SaitoEmailComponent;
use App\Controller\Component\ThemesComponent;
use App\Controller\Component\TitleComponent;
use App\Model\Table\UsersTable;
use Authentication\Controller\Component\AuthenticationComponent;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\Event;
use Cake\Http\Response;
use Cake\I18n\I18n;
use Cake\ORM\TableRegistry;
use Closure;
use Saito\App\Registry;
use Saito\App\SettingsImmutable;
use Saito\Event\SaitoEventManager;
use Saito\User\CurrentUser\CurrentUserInterface;
use Stopwatch\Lib\Stopwatch;

/**
 * Class AppController
 *
 * @property AuthUserComponent $AuthUser
 * @property AuthenticationComponent $Authentication
 * @property RefererComponent $Referer
 * @property SaitoEmailComponent $SaitoEmail
 * @property ThemesComponent $Themes
 * @property TitleComponent $Title
 * @property UsersTable $Users
 * @property \Cake\Controller\Component\FormProtectionComponent $FormProtection
 */
#[\AllowDynamicProperties]
class AppController extends Controller
{
    use InstanceConfigTrait;

    /**
     * View helpers.
     *
     * In Cake 4 the legacy `public $helpers` auto-loading was removed;
     * helpers are applied via the ViewBuilder. The keys here are loaded
     * by initialize() at the end of the controller bootstrap.
     */
    protected $viewHelpers = [
        'Form' => [
            'templates' => [
                // Bootstrap 4 CSS-class for invalid input elements
                'errorClass' => 'is-invalid',
                // Bootstrap 4 CSS-class for input validation message
                'error' => '<div class="invalid-feedback">{{content}}</div>',
            ],
        ],
        'Html',
        'JsData',
        'Layout',
        'Permissions',
        'SaitoHelp.SaitoHelp',
        'TimeH',
        'Url',
        'User',
    ];

    /**
     * Default config used by InstanceConfigTrait
     *
     * @var array default configuration
     */
    protected array $_defaultConfig = [];

    /**
     * The current user, set by the AuthUserComponent
     *
     * @var CurrentUserInterface
     */
    public $CurrentUser;

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        Stopwatch::start('------------------- Controller -------------------');

        parent::initialize();

        // Cake 4 dropped requestAction sub-requests, so the previous
        // is('requested') guard is no longer needed.
        if (!$this->request->getSession()->started()) {
            $this->request->getSession()->start();
        }

        Registry::get('Permissions')->buildCategories(TableRegistry::getTableLocator()->get('Categories'));

        // "Forum closed" gates the whole request, so it gets its own listener
        // rather than a branch in beforeFilter().
        //
        // Dispatch stops only when the `Controller.initialize` event *result* is
        // a response. beforeFilter() is that event's listener — but eleven
        // controllers override it and call `parent::beforeFilter($event)`
        // without returning what it gives back, so a response returned from
        // there was silently dropped and the action ran regardless. A listener
        // of its own owns its return value, and the priority puts it ahead of
        // every beforeFilter() so nothing else runs first.
        $this->getEventManager()->on(
            'Controller.initialize',
            ['priority' => 1],
            function (\Cake\Event\EventInterface $event): ?Response {
                return $this->denyWhileForumIsClosed($event);
            }
        );

        // Changed terms of service: same shape, one step behind the closed-forum
        // gate, because a closed forum outranks everything.
        $this->getEventManager()->on(
            'Controller.initialize',
            ['priority' => 2],
            function (\Cake\Event\EventInterface $event): ?Response {
                return $this->requireTermsAcceptance($event);
            }
        );

        // A required second factor, one step behind the terms gate. Order
        // matters and is not arbitrary: somebody who has not agreed to the
        // terms should meet the terms first, because agreeing is the smaller
        // ask and refusing it makes the second factor moot.
        $this->getEventManager()->on(
            'Controller.initialize',
            ['priority' => 3],
            function (\Cake\Event\EventInterface $event): ?Response {
                return $this->requireSecondFactor($event);
            }
        );

        // Leave in front to have it available in all Components
        $this->loadComponent('Detectors.Detectors');
        // CookieComponent was removed in Cake 4; cookies go through
        // EncryptedCookieMiddleware (see Application::middleware()).
        $this->loadComponent('Authentication.Authentication');
        // SecurityComponent was removed in Cake 4; FormProtectionComponent
        // covers form-tampering protection (CSRF lives in middleware).
        $this->loadComponent('FormProtection', [
            'validationFailureCallback' => Closure::fromCallable([$this, 'blackhole']),
        ]);
        $this->loadComponent('Cron.Cron');
        $this->loadComponent('CacheSupport');
        $this->loadComponent('AuthUser');
        $this->loadComponent('Parser');
        $this->loadComponent('SaitoEmail');
        $this->loadComponent('Themes', Configure::read('Saito.themes'));
        $this->loadComponent('Flash');
        $this->loadComponent('Title');

        // Cake 4: ViewBuilder is the canonical place for helpers; the old
        // $controller->helpers auto-loading was removed in 4.4.
        $this->viewBuilder()->addHelpers($this->viewHelpers);
    }

    /**
     * Serves the "forum is closed" page instead of the request, for everyone
     * but an admin.
     *
     * Returning the rendered response — and stopping the event — is what ends
     * the request. Rendering alone left the action running behind the page.
     *
     * @param \Cake\Event\EventInterface $event the initialize event
     * @return Response|null the 503 page, or null to let the request proceed
     */
    protected function denyWhileForumIsClosed(\Cake\Event\EventInterface $event): ?Response
    {
        $isClosed = Configure::read('Saito.Settings.forum_disabled')
            // Without this an admin could not sign in to reopen the forum.
            && $this->request->getParam('action') !== 'login'
            && !$this->CurrentUser->permission('saito.core.admin.backend');

        if (!$isClosed) {
            return null;
        }

        $this->Themes->setDefault();
        $this->viewBuilder()->enableAutoLayout(false);
        $body = $this->render('/Pages/forum_disabled');
        $event->stopPropagation();

        return $body->withStatus(503);
    }

    /**
     * Asks a member to agree to the terms again after the operator raised
     * `tos_version` — see issue #80 and § 7 of the shipped terms.
     *
     * Only where the forum requires terms at all (`tos_enabled`), only for a
     * logged-in member, and only while their `tos_accepted_version` is behind
     * the setting. An installation that never touches `tos_version` never sees
     * this: an absent setting reads as 0 and nothing is ever behind 0.
     *
     * Sessions are deliberately *not* invalidated. The check runs on every
     * request, so the next thing a member does already lands here — forcing a
     * re-login as well would buy nothing and cost everyone their session.
     *
     * @param \Cake\Event\EventInterface $event the initialize event
     * @return Response|null the interstitial, or null to let the request proceed
     */
    protected function requireTermsAcceptance(\Cake\Event\EventInterface $event): ?Response
    {
        if (!Configure::read('Saito.Settings.tos_enabled')) {
            return null;
        }
        if (!$this->CurrentUser->isLoggedIn()) {
            return null;
        }

        $required = (int)Configure::read('Saito.Settings.tos_version');
        if ($required <= (int)$this->CurrentUser->get('tos_accepted_version')) {
            return null;
        }
        if ($this->isExemptFromTermsGate()) {
            return null;
        }

        $this->viewBuilder()->setLayout('htmx_island');
        $body = $this->render('/Pages/tos_reconsent');
        $event->stopPropagation();

        return $body;
    }

    /**
     * Requests that must survive the terms gate, or a member who does not want
     * to agree would have nowhere to go.
     *
     * Four things stay open: reading the static legal pages (the terms one is
     * asked to agree to among them), accepting, logging out, and taking one's
     * own data out — the last because GDPR Art. 15/20 is not something a forum
     * gets to withhold pending a signature.
     *
     * @return bool
     */
    private function isExemptFromTermsGate(): bool
    {
        $request = $this->getRequest();
        if ($request->getParam('plugin') !== null) {
            // Admin and plugin routes are gated like everything else; an
            // administrator agrees to the terms too, and can then change them.
            return false;
        }

        $controller = (string)$request->getParam('controller');
        $action = (string)$request->getParam('action');

        // The imprint, the privacy policy and the terms themselves.
        if ($controller === 'Pages' && $action === 'display') {
            return true;
        }

        return $controller === 'Users'
            && in_array($action, ['tosAccept', 'logout', 'login', 'export'], true);
    }

    /**
     * Sends staff without a second factor to set one up (#87).
     *
     * The account worth protecting most is the one where the protection was
     * optional: an administrator can reset anybody's second factor, read the
     * backend and change every setting. Ordinary members keep the choice — the
     * asymmetry is the point, because the cost of a compromised member account
     * is one member and the cost of a compromised administrator account is the
     * forum.
     *
     * Off by default, so nothing changes on upgrade. When it is on, this runs
     * per request like the terms gate rather than invalidating sessions: the
     * next thing anybody does already lands here, and dropping everyone's login
     * would cost the whole forum something to achieve nothing.
     *
     * A promotion therefore takes effect on the promoted member's next request.
     * That is correct and it will surprise somebody, so the admin screen says so
     * next to the setting.
     *
     * @param \Cake\Event\EventInterface $event the initialize event
     * @return Response|null the enrolment page, or null to let the request through
     */
    protected function requireSecondFactor(\Cake\Event\EventInterface $event): ?Response
    {
        $from = (string)Configure::read('Saito.Settings.2fa_required_from_role');
        if ($from === '' || $from === 'off') {
            return null;
        }
        if (!$this->CurrentUser->isLoggedIn()) {
            return null;
        }

        // `mod` means "moderator and above", not "moderators only" — an
        // administrator holds the moderator permissions too, so requiring it of
        // moderators while exempting administrators would be the wrong way
        // round and is not offered.
        $resource = $from === 'admin'
            ? 'saito.core.admin.backend'
            : 'saito.core.posting.pinAndLock';
        if (!$this->CurrentUser->permission($resource)) {
            return null;
        }

        $userId = (int)$this->CurrentUser->getId();
        if ($this->fetchTable('TwoFactorCredentials')->isEnabledFor($userId)) {
            return null;
        }
        if ($this->isExemptFromSecondFactorGate()) {
            return null;
        }

        $this->viewBuilder()->setLayout('htmx_island');
        $body = $this->render('/Pages/two_factor_required');
        $event->stopPropagation();

        return $body;
    }

    /**
     * What has to stay reachable, or the gate becomes a lock with no key.
     *
     * This is the part that decides whether the feature is safe to turn on. The
     * enrolment page is the way out, so it cannot be behind the gate that sends
     * people to it; the recovery codes are handed out during enrolment on the
     * same screen; and logging out has to work, or somebody who cannot enrol on
     * this device is stuck in a forum they cannot leave.
     *
     * Enrolment needs its own POST target too — the settings page posts back to
     * itself to start, confirm and finish — which is why the whole
     * `htmxTwoFactor` action is exempt rather than only its GET.
     *
     * @return bool
     */
    private function isExemptFromSecondFactorGate(): bool
    {
        $request = $this->getRequest();
        if ($request->getParam('plugin') !== null) {
            // Including the admin area: an administrator who has not enrolled
            // has no business in the backend until they have.
            return false;
        }

        $controller = (string)$request->getParam('controller');
        $action = (string)$request->getParam('action');

        return $controller === 'Users'
            && in_array($action, ['htmxTwoFactor', 'logout', 'login', 'twoFactor'], true);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        Stopwatch::start('App->beforeFilter()');


        // allow sql explain for DebugKit toolbar
        if ($this->request->getParam('plugin') === 'debug_kit') {
            $this->Authentication->allowUnauthenticated(['sql_explain']);
        }

        Stopwatch::stop('App->beforeFilter()');
    }

    /**
     * {@inheritDoc}
     */
    public function beforeRender(\Cake\Event\EventInterface $event)
    {
        Stopwatch::start('App->beforeRender()');

        // Route to extension/content-type-specific template subdirectory (replaces
        // Cake 4's RequestHandlerComponent behaviour of selecting json/, xml/, rss/ subdirs).
        $ext = $this->request->getParam('_ext');
        if ($ext && in_array($ext, ['xml', 'rss', 'json'], true)) {
            $path = $this->viewBuilder()->getTemplatePath();
            $this->viewBuilder()->setTemplatePath($path . DS . $ext);
        } elseif ($this->request->is('json')) {
            $path = $this->viewBuilder()->getTemplatePath();
            $this->viewBuilder()->setTemplatePath($path . DS . 'json');
        }

        // Cake 4's RequestHandlerComponent disabled the layout for XHR requests
        // so AJAX endpoints return bare HTML fragments. Cake 5 removed the
        // component; replicate it here. The Saito SPA injects these fragments
        // directly into the DOM (e.g. PostingModel.fetchHtml() → entries/view),
        // so wrapping them in the full page layout corrupts the markup.
        if ($this->request->is('ajax')) {
            $this->viewBuilder()->disableAutoLayout();
        }

        // One frontend, one shell. Actions that need something else — the admin
        // backend, the installer — set their own; this only fills in for the ones
        // that never did and used to land on the theme's SPA layout.
        //
        // XML and RSS get no layout at all: those templates emit a complete
        // document, declaration included. They used to be wrapped in the theme's
        // HTML layout, which was wrong and only harmless because nothing
        // validated the output.
        if ($ext !== null && in_array($ext, ['xml', 'rss'], true)) {
            $this->viewBuilder()->disableAutoLayout();
        } elseif ($this->viewBuilder()->getLayout() === null) {
            $this->viewBuilder()->setLayout('htmx_island');
        }

        $this->Themes->set($this->CurrentUser);
        $this->_setConfigurationFromGetParams();
        $this->_l10nRenderFile();

        $this->set('SaitoSettings', new SettingsImmutable(Configure::read('Saito.Settings')));
        $this->set('SaitoEventManager', SaitoEventManager::getInstance());

        Stopwatch::stop('App->beforeRender()');
        Stopwatch::start('------------------- Rendering --------------------');
    }

    /**
     * Sets forum configuration from GET parameter in url
     *
     * - theme=<foo>
     * - stopwatch:true
     * - lang:<lang_id>
     *
     * @return void
     */
    protected function _setConfigurationFromGetParams()
    {
        if (!$this->CurrentUser->isLoggedIn()) {
            return;
        }

        //= change theme on the fly with ?theme=<name>
        $theme = $this->request->getQuery('theme');
        if ($theme) {
            $this->Themes->set($this->CurrentUser, $theme);
        }


        //= change language
        $lang = $this->request->getQuery('lang');
        if (!empty($lang)) {
            Configure::write('Saito.language', $lang);
        };
    }

    /**
     * Handle request-blackhole.
     *
     * @param \Exception $exception PHP exception
     * @return void
     * @throws \Saito\Exception\SaitoBlackholeException
     */
    public function blackhole(\Exception $exception): void
    {
        throw new \Saito\Exception\SaitoBlackholeException(
            $exception->getMessage(),
            ['CurrentUser' => $this->CurrentUser]
        );
    }

    /**
     * manually require auth and redirect cycle
     *
     * @return Response
     */
    protected function _requireAuth()
    {
        $this->Flash->set(__('authorization.autherror'), ['element' => 'error']);
        $here = $this->request->getRequestTarget();

        return $this->redirect([
            '_name' => 'login',
            '?' => ['redirect' => $here],
            'plugin' => false,
        ]);
    }

    /**
     * sets l10n .ctp file if available
     *
     * @return void
     */
    protected function _l10nRenderFile()
    {
        $locale = Configure::read('Saito.language');
        I18n::useFallback(false); // @see <https://github.com/cakephp/cakephp/pull/6812>
        I18n::setLocale($locale);
        if (!$locale) {
            return;
        }

        $check = function ($locale) {
            $l10nViewPath = $this->viewBuilder()->getTemplatePath() . DS . $locale;
            $l10nViewFile = $l10nViewPath . DS . $this->viewBuilder()->getName() . '.ctp';
            if (!file_exists(APP . 'Template' . DS . $l10nViewFile)) {
                return false;
            }

            return $l10nViewPath;
        };

        $path = $check($locale);
        if ($path) {
            $this->viewBuilder()->setTemplatePath($path);

            return;
        }

        if (strpos($locale, '_')) {
            list($locale) = explode('_', $locale);
            $path = $check($locale);
            if ($path) {
                $this->viewBuilder()->setTemplatePath($path);
            }
        }
    }
}
