<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Installer\Lib;

use App\Test\Fixture\UserFixture;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Migrations\Migrations;
use Saito\Test\IntegrationTestCase as SaitoIntegrationTestCase;

/**
 * Integration Test Case for Installer
 */
abstract class IntegrationTestCase extends SaitoIntegrationTestCase
{
    /**
     * Tables that exist in the full Saito 5 schema (after all migrations).
     * Used for both drop-before-migrate and truncate-after-migrate.
     */
    private static array $knownTables = [
        'bookmarks',
        'categories',
        'drafts',
        'entries',
        'phinxlog',
        'settings',
        'smiley_codes',
        'smilies',
        'uploads',
        'user_blocks',
        'user_ignores',
        'user_reads',
        'useronline',
        'users',
    ];

    /**
     * Legacy Saito 4 tables that migration 5.1.0 drops.
     * Must be dropped before re-running migrations (the initial migration
     * creates them; migration 5.1.0 drops them), but must NOT be truncated
     * after all migrations have run (they no longer exist then).
     */
    private static array $legacyTables = [
        'esevents',
        'esnotifications',
        'shouts',
    ];

    /** Tracks whether dropTables() was explicitly called during the current test. */
    private bool $tablesDropped = false;

    /**
     * Drop all known Saito tables (Saito 4 and 5)
     *
     * @return void
     */
    protected function dropTables()
    {
        $this->_dropAllKnownTables();
        $this->tablesDropped = true;

        // Tell the fixture system that all tables are gone so teardown won't try
        // to TRUNCATE tables that no longer exist.
        $this->fixtureStrategy = null;
    }

    private function _dropAllKnownTables(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        $allTables = array_merge(self::$knownTables, self::$legacyTables);
        foreach ($allTables as $table) {
            $connection->execute('DROP TABLE IF EXISTS ' . $table . ';');
        }
    }

    /**
     * After fixture teardown, restore the full schema via migrations so that
     * subsequent test cases find their tables again.
     *
     * @return void
     */
    protected function teardownFixtures(): void
    {
        parent::teardownFixtures();
        if ($this->tablesDropped) {
            $this->tablesDropped = false;
            // Drop any partially-created tables before re-running migrations
            // (some Installer tests leave tables in a partial state).
            $this->_dropAllKnownTables();
            (new Migrations())->migrate(['connection' => 'test']);
            // Truncate any data that migrations may have seeded, so subsequent
            // tests' TruncateStrategy.setupTest() can INSERT without conflicts.
            $this->_truncateAllKnownTables();
        }
    }

    private function _truncateAllKnownTables(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        foreach (self::$knownTables as $table) {
            $connection->execute('TRUNCATE TABLE `' . $table . '`;');
        }
    }
}
