<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         0.10.8
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/*
 * Configure paths required to find CakePHP + general filepath constants
 */
require __DIR__ . '/paths.php';

// Cake 5 no longer auto-loads the global helper functions (env(), __(),
// __d(), pluginSplit() etc.). They live in *_global.php files that aren't
// in cakephp/cakephp's autoload.files list, so pull them in explicitly —
// our app and templates rely on them being globally available.
$cakeRoot = ROOT . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp' . DS . 'src';
require $cakeRoot . DS . 'Core' . DS . 'functions_global.php';
require $cakeRoot . DS . 'I18n' . DS . 'functions_global.php';
require $cakeRoot . DS . 'Utility' . DS . 'bootstrap.php';

/*
 * Bootstrap CakePHP.
 *
 * Does the various bits of setup that CakePHP needs to do.
 * This includes:
 *
 * - Registering the CakePHP autoloader.
 * - Setting the default application paths.
 */
require CORE_PATH . 'config' . DS . 'bootstrap.php';

use Cake\Cache\Cache;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\Utility\Inflector;
use Cake\Utility\Security;

/**
 * Uncomment block of code below if you want to use `.env` file during development.
 * You should copy `config/.env.default to `config/.env` and set/modify the
 * variables as required.
 */
if (!env('APP_NAME') && file_exists(CONFIG . '.env')) {
    $dotenv = new \josegonzalez\Dotenv\Loader([CONFIG . '.env']);
    $dotenv->parse()
        ->putenv()
        ->toEnv()
        ->toServer();
}

/*
 * Read configuration file and inject configuration into various
 * CakePHP classes.
 *
 * By default there is only one configuration file. It is often a good
 * idea to create multiple configuration files, and separate the configuration
 * that changes from configuration that does not. This makes deployment simpler.
 */
try {
    Configure::config('default', new PhpConfig());
    Configure::load('app', 'default', false);

    /**
     * Load additional config files
     */
    Configure::load('permissions', 'default');
    Configure::load('saito_config', 'default');
    Configure::config('saitoCore', new PhpConfig(APP . '/Lib/'));
    Configure::load('version', 'saitoCore');

    Configure::write(
        'App.defaultLocale',
        Configure::read('Saito.language')
    );

    Configure::load('email', 'default');
} catch (\Exception $e) {
    exit($e->getMessage() . "\n");
}

/*
 * Load an environment local configuration file.
 * You can use a file like app_local.php to provide local overrides to your
 * shared configuration.
 */
//Configure::load('app_local', 'default');

/*
 * When debug = true the metadata cache should only last
 * for a short time.
 */
if (Configure::read('debug')) {
    Configure::write('Cache._cake_model_.duration', '+2 minutes');
    Configure::write('Cache._cake_translations_.duration', '+2 minutes');
    // disable router cache during development
    Configure::write('Cache._cake_routes_.duration', '+2 seconds');
}

/*
 * Set the default server timezone. Using UTC makes time calculations / conversions easier.
 * Check http://php.net/manual/en/timezones.php for list of valid timezone strings.
 */
date_default_timezone_set(Configure::read('App.defaultTimezone'));

/*
 * Configure the mbstring extension to use the correct encoding.
 */
mb_internal_encoding(Configure::read('App.encoding'));

/*
 * Set the default locale. This controls how dates, number and currency is
 * formatted and sets the default language to use for translations.
 */
ini_set('intl.default_locale', Configure::read('App.defaultLocale'));

/*
 * Pull in CLI overrides BEFORE registering the error/exception traps so
 * the CLI-specific exceptionRenderer (ConsoleExceptionRenderer) is in
 * Configure when ExceptionTrap reads it during construction.
 */
$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    require __DIR__ . '/bootstrap_cli.php';
}

/*
 * Register application error and exception handlers via Cake 5's
 * ErrorTrap / ExceptionTrap (the old ErrorHandler classes were removed).
 */
(new \Cake\Error\ErrorTrap(Configure::read('Error')))->register();
(new \Cake\Error\ExceptionTrap(Configure::read('Error')))->register();

/*
 * Set the full base URL.
 * This URL is used as the base of all absolute links.
 *
 * If you define fullBaseUrl in your config file you can remove this.
 */
if (!Configure::read('App.fullBaseUrl')) {
    $s = null;
    if (env('HTTPS')) {
        $s = 's';
    }

    $httpHost = env('HTTP_HOST');
    if (isset($httpHost)) {
        Configure::write('App.fullBaseUrl', 'http' . $s . '://' . $httpHost);
    }
    unset($httpHost, $s);
}

Cache::setConfig(Configure::consume('Cache'));
ConnectionManager::setConfig(Configure::consume('Datasources'));
TransportFactory::setConfig(Configure::consume('EmailTransport'));
Mailer::setConfig(Configure::consume('Email'));
Log::setConfig(Configure::consume('Log'));
Security::setSalt(Configure::consume('Security.salt'));

/*
 * The default crypto extension in 3.0 is OpenSSL.
 * If you are migrating from 2.x uncomment this code to
 * use a more compatible Mcrypt based implementation
 */
//Security::engine(new \Cake\Utility\Crypto\Mcrypt());

/*
 * Setup detectors for mobile and tablet.
 */
ServerRequest::addDetector('mobile', function ($request) {
    $detector = new \Detection\MobileDetect();

    return $detector->isMobile();
});
ServerRequest::addDetector('tablet', function ($request) {
    $detector = new \Detection\MobileDetect();

    return $detector->isTablet();
});

// Cake 5 dropped the mutable Time/Date types — the database types are
// always immutable now, so the four ->useImmutable() calls that lived
// here were no-ops and have been removed.

/*
 * Custom Inflector rules, can be set to correctly pluralize or singularize
 * table, model, controller names or whatever other string is passed to the
 * inflection functions.
     */
//Inflector::rules('plural', ['/^(inflect)or$/i' => '\1ables']);
//Inflector::rules('irregular', ['red' => 'redlings']);
//Inflector::rules('uninflected', ['dontinflectme']);
//Inflector::rules('transliteration', ['/å/' => 'aa']);

/**
 * cake doesn't handle smiley <-> smilies
 */
Inflector::rules('plural', ['/^(smil)ey$/i' => '\1ies']);
Inflector::rules('singular', ['/^(smil)ies$/i' => '\1ey']);

// App::path('Lib') returned [APP . 'Lib' . DS] only because Application
// configured the 'Lib' path. In Cake 5 the App::paths surface is leaner;
// reference the file directly instead.
include APP . 'Lib' . DS . 'BaseFunctions.php';

\Cake\Event\EventManager::instance()->on(\Saito\Event\SaitoEventManager::getInstance());

/**
 * Add custom Database-types
 */
\Cake\Database\TypeFactory::map('serialize', 'App\Database\Type\SerializeType');
\Cake\Database\TypeFactory::map('avatar.file', 'App\Database\Type\AvatarFileType');
