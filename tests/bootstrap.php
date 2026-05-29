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
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\ConnectionHelper;
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
// fixture truncate/insert cycle has real tables to operate on.
//
// Guard against a state where tables exist but phinxlog has no records
// (can happen when an Installer test is interrupted mid-teardown). In that
// case phinx would try to re-create existing tables and fail, so we drop
// everything and start fresh.
(function () {
    $migrations = new Migrations();
    // Tables present after all Saito 5 migrations have run.
    $knownTables = [
        'bookmarks', 'categories', 'drafts', 'entries', 'phinxlog', 'settings',
        'smiley_codes', 'smilies', 'uploads', 'user_blocks', 'user_ignores',
        'user_reads', 'useronline', 'users',
    ];
    // Legacy Saito 4 tables dropped by migration 5.1.0 — must be dropped
    // before re-running migrations so the initial migration can recreate them.
    $legacyTables = ['esevents', 'esnotifications', 'shouts'];
    $connection = ConnectionManager::get('test');
    try {
        $migrations->migrate(['connection' => 'test']);
    } catch (\Exception $e) {
        // Inconsistent state — drop all known tables and migrate from scratch.
        foreach (array_merge($knownTables, $legacyTables) as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table);
        }
        $migrations->migrate(['connection' => 'test']);
    }
    // Migration 20180620093430 seeds 3 rows into `settings`. Truncate so that
    // TruncateStrategy::setupTest() (which inserts without truncating first)
    // doesn't get a duplicate-key error on the very first fixture load.
    $connection->execute('TRUNCATE TABLE `settings`');
})();

// Alias 'default' → 'test' so all ORM models (which default to the
// 'default' connection) transparently hit the test database. In Cake 5
// this was done by the PHPUnit extension / FixtureManager, but since we
// use a bootstrap-based setup we do it here instead.
ConnectionHelper::addTestAliases();

// otherwise Security mock fails with debug info
Configure::write('debug', true);

// Cake Session isn't isolated and clashes with PHPUnit
// @see https://github.com/sebastianbergmann/phpunit/issues/1416
session_id('cli');

// test userupload in tmp directory
Configure::write('Saito.Settings.uploadDirectory', TMP . 'tests' . DS);

// disable <asset-url>?<timestamp> for tests
Configure::write('Asset.timestamp', false);
