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
use Authentication\UrlChecker\DefaultUrlChecker;
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
        if (Configure::read('debug')) {
            // $this->addPlugin(\DebugKit\Plugin::class);
        }
        // Load more plugins here

        Registry::initialize();

        $this->addPlugin('Authentication');
        $this->addPlugin(\Admin\AdminPlugin::class, ['routes' => true]);
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

        $this->addPlugin(\Cron\CronPlugin::class);
        $this->addPlugin(\Commonmark\CommonmarkPlugin::class);
        $this->addPlugin(\Detectors\DetectorsPlugin::class);
        $this->addPlugin(\MailObfuscator\MailObfuscatorPlugin::class);
        $this->addPlugin(\SpectrumColorpicker\SpectrumColorpickerPlugin::class);
        $this->addPlugin(\Stopwatch\StopwatchPlugin::class);

        $this->addPlugin('Local');
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
            // Routes collection cache enabled by default, to disable route caching
            // pass null as cacheConfig, example: `new RoutingMiddleware($this)`
            // you might want to disable this cache in case your routing is extremely simple
            ->add(new RoutingMiddleware($this, '_cake_routes_'))

            // Parse JSON / form-urlencoded request bodies (Cake 3's
            // RequestHandlerComponent did this implicitly; in Cake 4 it
            // has to be wired up explicitly). The Saito frontend posts
            // its API payloads as application/json — without this the
            // controllers receive an empty $this->request->getData().
            ->add(new BodyParserMiddleware())

            ->insertAfter(RoutingMiddleware::class, new SaitoBootstrapMiddleware())

            ->add(new EncryptedCookieMiddleware(
                // Names of cookies to protect
                [Configure::read('Security.cookieAuthName')],
                Configure::read('Security.cookieSalt')
            ))

            // CSRF protection (replaces the Cake-3 CsrfComponent).
            // API requests are JWT-authenticated and intentionally exempt.
            ->add(
                (new CsrfProtectionMiddleware([
                    'expiry' => time() + 10800,
                    'cookieName' => Configure::read('Session.cookie', 'CAKEPHP') . '-CSRF',
                ]))
                    ->skipCheckCallback(function ($request) {
                        // Only the JWT-authenticated /api/v2 API is CSRF-exempt.
                        // A substring match on '/api/' let an attacker append
                        // '/api/' as trailing pass-args to a session-authed
                        // route (fallback DashedRoute) and skip CSRF, so this
                        // must be an anchored prefix, matching the JWT scope.
                        return str_starts_with($request->getUri()->getPath(), '/api/v2/');
                    })
            )

            // CakePHP authentication provider
            ->insertAfter(
                EncryptedCookieMiddleware::class,
                new AuthenticationMiddleware($this)
            );

        $security = (new SecurityHeadersMiddleware())
            ->setXFrameOptions(strtolower(Configure::read('Saito.X-Frame-Options')));
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
        $isApi = (new \Authentication\UrlChecker\DefaultUrlChecker())
            ->check($request, ['#api/v2#'], ['useRegex' => true]);
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
