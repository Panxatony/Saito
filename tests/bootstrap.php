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
        'bookmarks', 'categories', 'drafts', 'entries', 'password_reset_tokens',
        'phinxlog', 'settings', 'smiley_codes', 'smilies',
        'two_factor_credentials', 'two_factor_recovery_codes',
        'two_factor_trusted_devices', 'uploads', 'webauthn_credentials',
        'user_blocks', 'user_ignores', 'user_reads', 'useronline', 'users',
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

// A key of the suite's own. config/app.php ships `__SALT__`, which the installer
// replaces with real randomness — as a literal it is nine characters and too
// short for the JWT signer, which is exactly the failure a fresh install is
// supposed to be walked through rather than silently pass. Tests must not
// depend on either the placeholder or on whatever a developer's machine has.
Configure::write('Security.salt', str_repeat('saito-test-salt-', 4));
Configure::write('Security.cookieSalt', str_repeat('saito-test-cookie-salt-', 3));

// Cake Session isn't isolated and clashes with PHPUnit
// @see https://github.com/sebastianbergmann/phpunit/issues/1416
session_id('cli');

// test userupload in tmp directory
Configure::write('Saito.Settings.uploadDirectory', TMP . 'tests' . DS);

// disable <asset-url>?<timestamp> for tests
Configure::write('Asset.timestamp', false);

/*
 * Start from a clean test database, whatever the last run did.
 *
 * CakePHP's TruncateStrategy inserts fixtures in setupTest() and truncates them
 * in teardownTest() — and only the fixtures of the test that just ran. Nothing
 * truncates before inserting. So a run that never reaches teardown leaves its
 * rows behind: ctrl-C, a timeout, a crashed process. Killing a run 90 seconds
 * in leaves twelve tables populated, `users` and `categories` among them.
 *
 * The next run then fails on `Duplicate entry '1' for key 'PRIMARY'` in the
 * first tests that declare those fixtures — and then heals itself, because each
 * of those tests truncates its own fixtures on the way out. The result is a
 * handful of errors in early tests, in files the developer never touched, that
 * pass when run on their own and are green on the next attempt.
 *
 * That was diagnosed here three times as something else — concurrent runs, a
 * shared database with the test forum — before the runs were lined up against
 * what preceded them: every red run followed a killed one, every green run did
 * not. Confirmed by killing a run deliberately and predicting the failure.
 *
 * A red result that means nothing is worse than no result: it teaches people to
 * re-run until green, and that is the habit that hides a real failure.
 */
(function (): void {
    $connection = \Cake\Datasource\ConnectionManager::get('test');

    $leftovers = [];
    foreach ($connection->getSchemaCollection()->listTables() as $table) {
        // phinxlog is the migration history and is supposed to have rows.
        if ($table === 'phinxlog') {
            continue;
        }
        $count = $connection->execute("SELECT COUNT(*) AS c FROM `$table`")->fetch('assoc');
        if ((int)($count['c'] ?? 0) > 0) {
            $leftovers[] = $table;
        }
    }

    if (!$leftovers) {
        return;
    }

    $connection->execute('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($leftovers as $table) {
        $connection->execute("TRUNCATE TABLE `$table`");
    }
    $connection->execute('SET FOREIGN_KEY_CHECKS = 1');

    fwrite(STDERR, sprintf(
        "Note: emptied %d table(s) left behind by an interrupted run (%s).\n",
        count($leftovers),
        implode(', ', $leftovers),
    ));
})();

/*
 * And one suite at a time.
 *
 * Separate from the above and still worth having: two runs sharing one schema
 * would truncate and reload under each other, which produces the same
 * unattributable failures for a different reason. This was written first, on
 * the assumption that concurrency was the cause. It was not — but the guard is
 * correct, and the cleanup above cannot help a collision that is happening now.
 *
 * flock on a lock file: the kernel releases it when the process ends, so a
 * killed run leaves nothing to clean up. The handle is kept in $GLOBALS so it
 * lives as long as the process — closing it would drop the lock immediately.
 */
(function (): void {
    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saito-phpunit-' . md5(ROOT) . '.lock';
    $handle = fopen($lockFile, 'c');
    if ($handle === false) {
        // Not being able to lock is no reason to refuse to test.
        fwrite(STDERR, "Note: could not open $lockFile — running without the concurrency guard.\n");

        return;
    }

    if (flock($handle, LOCK_EX | LOCK_NB)) {
        $GLOBALS['__saito_phpunit_lock'] = $handle;

        return;
    }

    fwrite(STDERR, <<<TXT

    Another PHPUnit run already holds the test database.

    Both would load fixtures into the same schema and report failures that
    belong to neither. Wait for the other run to finish, or stop it:

        pkill -f phpunit

    TXT);
    exit(1);
})();
