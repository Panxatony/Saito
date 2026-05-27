<?php
/**
 * Test runner bootstrap.
 *
 * Add additional configuration/setup your application needs when running
 * unit tests in this file.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

require dirname(__DIR__) . '/config/bootstrap.php';

$_SERVER['PHP_SELF'] = '/';

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Migrations\Migrations;

// Run every cache configuration through Cake's in-memory ArrayEngine
// during tests: no leftover files in tmp/cache/, no flaky interactions
// between test runs, and a meaningful speed-up on cache-heavy paths.
foreach (Cache::configured() as $cacheKey) {
    $config = Cache::getConfigOrFail($cacheKey);
    $config['className'] = ArrayEngine::class;
    Cache::drop($cacheKey);
    Cache::setConfig($cacheKey, $config);
}

// Cake 5 no longer auto-creates table schemas from fixture `$fields`
// definitions. Run the migrations against the `test` connection so the
// fixture truncate/insert cycle has real tables to operate on. The call
// is idempotent — Phinx skips migrations that are already in phinxlog,
// so per-run cost after the first invocation is sub-second.
(new Migrations())->migrate(['connection' => 'test']);

// otherwise Security mock fails with debug info
Configure::write('debug', true);

// Cake Session isn't isolated and clashes with PHPUnit
// @see https://github.com/sebastianbergmann/phpunit/issues/1416
session_id('cli');

// test userupload in tmp directory
Configure::write('Saito.Settings.uploadDirectory', TMP . 'tests' . DS);

// disable <asset-url>?<timestamp> for tests
Configure::write('Asset.timestamp', false);
