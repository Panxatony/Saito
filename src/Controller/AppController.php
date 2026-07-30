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
