<?php

declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.3.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App;

use App\Auth\AuthenticationServiceFactory;
use App\Middleware\SaitoBootstrapMiddleware;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Core\Exception\MissingPluginException;
use Cake\Core\Plugin;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManagerInterface;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Middleware\EncryptedCookieMiddleware;
use Cake\Http\Middleware\SecurityHeadersMiddleware;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Saito\App\Registry;
use Stopwatch\Lib\Stopwatch;

/**
 * Application setup class.
 *
 * This defines the bootstrapping logic and middleware layers you
 * want to use in your application.
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function __construct($configDir, ?EventManagerInterface $eventManager = null)
    {
        Stopwatch::init();
        Stopwatch::enable();
        Stopwatch::start('Application::__construct');
        parent::__construct($configDir, $eventManager);
        Stopwatch::stop('Application::__construct');
    }

    /**
     * {@inheritDoc}
     */
    public function bootstrap(): void
    {
        Stopwatch::start('Application::bootstrap');

        parent::bootstrap();

        if (PHP_SAPI === 'cli') {
            $this->bootstrapCli();
        }
        /*
         * Only try to load DebugKit in development mode
         * Debug Kit should not be installed on a production system
         */
        // DebugKit is a dev-only tool and is not installed here; enable it in a
        // development setup by adding, under a debug check:
        // $this->addPlugin(\DebugKit\Plugin::class);
        // Load more plugins here

        // CakePHP writes the full client IP into every logged exception and has
        // no setting for it, which quietly undoes the forum's own "do not store
        // IP addresses" decision. Wired here rather than in config/app.php so it
        // holds for every installation, including those that never touched their
        // config. middleware() reads Configure::read('Error') after bootstrap(),
        // so setting it at this point takes effect.
        Configure::write('Error.logger', \App\Error\AnonymizingErrorLogger::class);

        Registry::initialize();

        $this->addPlugin('Authentication');
        $this->addPlugin(\Admin\AdminPlugin::class, ['routes' => true]);
        // The `/api/v2` scope is IN USE. It is small and easy to mistake for a
        // leftover, so: `bin/cake routes | grep api` is the authoritative check,
        // and it lists five addresses served by Bookmarks and ImageUploader,
        // which register them from their own `config/routes.php` — not from
        // here, and not from ApiPlugin, which is an empty BasePlugin.
        //
        // One of them is on the critical path of the current frontend: the htmx
        // upload dialog renders every thumbnail through
        // `/api/v2/uploads/thumb/{id}` (templates/Entries/htmx_uploads.php,
        // via ImageUploaderHelper::image()). Removing the scope breaks image
        // previews in the editor.
        //
        // Do not conclude it is dead by probing paths. `resources()` binds each
        // route to specific HTTP methods, so `GET /api/v2/bookmarks/1` — where
        // the route is PUT/PATCH/DELETE — answers 404 exactly as an absent
        // scope would. That reading cost an audit a wrong finding once.
        $this->addPlugin(\Api\ApiPlugin::class, ['bootstrap' => true, 'routes' => true]);
        $this->addPlugin(\Bookmarks\BookmarksPlugin::class, ['routes' => true]);
        $this->addPlugin(\BbcodeParser\BbcodeParserPlugin::class);
        $this->addPlugin(\Feeds\FeedsPlugin::class, ['routes' => true]);
        $this->addPlugin(\Installer\InstallerPlugin::class);
        $this->addPlugin(\SaitoHelp\SaitoHelpPlugin::class, ['routes' => true]);
        $this->addPlugin(\SaitoSearch\SaitoSearchPlugin::class, ['routes' => true]);
        $this->addPlugin(\Sitemap\SitemapPlugin::class, ['bootstrap' => true, 'routes' => true]);
        $this->addPlugin(\ImageUploader\ImageUploaderPlugin::class, ['routes' => true]);
        // Base theme: load it so its webroot assets (e.g. the smilies icon-font
        // referenced by themes extending Bota) are served at /bota/... even when
        // a derived theme like Local is the active one.
        $this->addPlugin(\Bota\BotaPlugin::class);
        // Nova is the modern default theme; it extends Bota and, like Bota,
        // needs to be loaded so its assets are served even when a derived theme
        // is active.
        $this->addPlugin(\Nova\NovaPlugin::class);

        $this->addPlugin(\Cron\CronPlugin::class);
        $this->addPlugin(\Commonmark\CommonmarkPlugin::class);
        $this->addPlugin(\Detectors\DetectorsPlugin::class);
        $this->addPlugin(\MailObfuscator\MailObfuscatorPlugin::class);
        $this->addPlugin(\Stopwatch\StopwatchPlugin::class);
        $this->addPlugin(\Webhooks\WebhooksPlugin::class);

        // The two shipped themes that are nobody's default. A theme only
        // becomes selectable once an installation names it under
        // `Saito.themes.available`, and the component that resolves the choice
        // does not load plugins — so without this an installation could offer
        // Macfix and then render Nova, silently. `loadDefaultThemePlugin()`
        // below covers whichever one an installation made its default.
        $this->addPlugin('Macnemo');
        $this->addPlugin('Macfix');
        $this->loadDefaultThemePlugin();

        Stopwatch::stop('Application::bootstrap');
    }

    /**
     * Setup the middleware queue your application will use.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware($middlewareQueue): \Cake\Http\MiddlewareQueue
    {
        $middlewareQueue
            // Catch any exceptions in the lower layers,
            // and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error')))

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(AssetMiddleware::class)

            // Add routing middleware.
            // CakePHP 5's RoutingMiddleware takes only the application; the old
            // `$cacheConfig` argument (we passed '_cake_routes_') no longer
            // exists, so it was silently ignored — there is no route cache to
            // configure or clear here anymore.
            ->add(new RoutingMiddleware($this))

            // Parse JSON / form-urlencoded request bodies (Cake 3's
            // RequestHandlerComponent did this implicitly; in Cake 4 it
            // has to be wired up explicitly). The Saito frontend posts
            // its API payloads as application/json — without this the
            // controllers receive an empty $this->request->getData().
            ->add(new BodyParserMiddleware())

            ->insertAfter(RoutingMiddleware::class, new SaitoBootstrapMiddleware())

            // Behind a trusted reverse proxy (e.g. the beta edge) honour the
            // X-Forwarded-* headers so the app sees the real client IP and the
            // https scheme. Runs before routing so scheme/host detection and CSRF
            // are correct. Gated by Saito.trustProxy: a direct-access install
            // must NOT trust these headers (they'd be spoofable).
            ->insertBefore(RoutingMiddleware::class, function ($request, $handler) {
                if (Configure::read('Saito.trustProxy')) {
                    $request->trustProxy = true;
                }

                return $handler->handle($request);
            })

            ->add(new EncryptedCookieMiddleware(
                // Names of cookies to protect
                [Configure::read('Security.cookieAuthName')],
                Configure::read('Security.cookieSalt')
            ))

            // CSRF protection (replaces the Cake-3 CsrfComponent).
            ->add(
                (new CsrfProtectionMiddleware([
                    // A session cookie, which is Cake's own default and what
                    // this had drifted away from. The middleware re-issues the
                    // cookie on every response, so an explicit lifetime only
                    // ever bites a page that sits idle longer than it — and at
                    // three hours (set during the Cake-4 middleware work in
                    // May, with no reason recorded) that was an ordinary
                    // afternoon. The remember-me cookie lasts ten days, so a
                    // member stayed logged in while the form they were looking
                    // at had quietly stopped working.
                    //
                    // It cost little to notice and a lot to diagnose: pressing
                    // "send" on the reply form did nothing at all, and an
                    // upload answered "failed". Reported from macnemo.de on
                    // 2026-08-22.
                    //
                    // Expiring the token buys close to nothing here. It proves
                    // a request came from a page this forum rendered; anyone
                    // able to read it already holds the session it is paired
                    // with, and that is the credential worth protecting.
                    'expiry' => 0,
                    'cookieName' => Configure::read('Session.cookie', 'CAKEPHP') . '-CSRF',
                ]))
                    ->skipCheckCallback(function ($request) {
                        // `/api/v2` is exempt, and that is safe rather than an
                        // oversight — the reasoning is easy to lose, so it is
                        // written down here where an audit lands.
                        //
                        // getAuthenticationService() answers /api/v2 with
                        // buildJwt(), which loads *only* the JWT authenticator
                        // and reads the bearer token from the Authorization
                        // header (never a cookie, never a query parameter). A
                        // browser therefore carries no credential this scope
                        // accepts. CSRF is an attack on ambient authority, and
                        // under /api/v2 there is none to abuse: a forged
                        // request arrives unauthenticated.
                        //
                        // Do NOT read this exemption as "the API is dead".
                        // `bin/cake routes` lists eight live routes under it
                        // (plugins/Bookmarks and plugins/ImageUploader register
                        // them from their own config/routes.php), and the htmx
                        // upload dialog renders every thumbnail through
                        // /api/v2/uploads/thumb/{id}. Probing a handful of
                        // guessed paths is not a test of that — the ones that
                        // exist answer only to the HTTP methods `resources()`
                        // gave them, so a GET where the route is DELETE returns
                        // a 404 that looks exactly like an absent scope.
                        //
                        // The anchored prefix matters: a substring match on
                        // '/api/' once let an attacker append '/api/' as
                        // trailing pass-args to a session-authed route (the
                        // fallback DashedRoute) and skip CSRF on it.
                        return str_starts_with($request->getUri()->getPath(), '/api/v2/');
                    })
            )

            // CakePHP authentication provider
            ->insertAfter(
                EncryptedCookieMiddleware::class,
                new AuthenticationMiddleware($this)
            );

        $security = (new SecurityHeadersMiddleware())
            ->setXFrameOptions(strtolower(Configure::read('Saito.X-Frame-Options')))
            // Stop content-type sniffing (defence-in-depth for uploads and any
            // reflected content) and keep referrers same-origin on downgrade.
            ->noSniff()
            ->setReferrerPolicy('strict-origin-when-cross-origin');
        $middlewareQueue->add($security);

        return $middlewareQueue;
    }

    /**
     * Get authentication service.
     *
     * Part of AuthenticationServiceProviderInterface.
     *
     * {@inheritDoc}
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        // Anchored, and deliberately the same test the CSRF exemption uses in
        // middleware() — the two decide one question between them ("is this the
        // JWT scope?") and must not be able to disagree.
        //
        // This was an unanchored regex, `#api/v2#`, which matched any path
        // merely *containing* the string: `/entries/view/1/api/v2` would have
        // been answered with the JWT service and the member shown as logged
        // out. Stricter rather than looser, so not an escalation — but the
        // asymmetry was the real hazard. The CSRF check next door carries a
        // comment about a substring match once being exploitable there, and
        // leaving one of the pair loose invites somebody to "harmonise" them in
        // the wrong direction.
        $isApi = str_starts_with($request->getUri()->getPath(), '/api/v2/');
        if ($isApi) {
            return AuthenticationServiceFactory::buildJwt();
        }

        return AuthenticationServiceFactory::buildApp();
    }

    /**
     * Load the plugin for Saito's default theme
     *
     * @return void
     */
    private function loadDefaultThemePlugin()
    {
        $defaultTheme = Configure::read('Saito.themes.default');
        if (empty($defaultTheme)) {
            throw new \RuntimeException(
                'Could not resolve default theme for plugin loading.',
                1556562215
            );
        }
        if (Plugin::isLoaded($defaultTheme) !== true) {
            $this->addPlugin($defaultTheme);
        }
    }

    /**
     * @return void
     */
    protected function bootstrapCli(): void
    {
        try {
            $this->addPlugin('Bake');
        } catch (MissingPluginException $e) {
            // Do not halt if the plugin is missing
        }
        $this->addPlugin('Migrations');
        // Load more plugins here
    }
}
