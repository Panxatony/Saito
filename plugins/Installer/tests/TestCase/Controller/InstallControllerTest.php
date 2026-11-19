<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Installer\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Installer\Lib\DbVersion;
use Installer\Lib\InstallerState;
use Installer\Lib\IntegrationTestCase;
use LogicException;
use Migrations\Migrations;

class InstallControllerTest extends IntegrationTestCase
{
    /**
     * The test database as what it is. ConnectionManager::get() is declared
     * to return the bare interface, which has no execute() or schema access.
     *
     * @return \Cake\Database\Connection
     */
    private static function testConnection(): Connection
    {
        $connection = ConnectionManager::get('test');
        if (!$connection instanceof Connection) {
            throw new LogicException('The test connection is not a database connection.');
        }

        return $connection;
    }

    protected $isUpdated = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        $this->createInstallerToken();
        InstallerState::reset();

        Configure::write('Saito.installed', false);
        Configure::write('Saito.updated', false);
    }

    public function tearDown(): void
    {
        $this->createInstallerToken();
        $this->dropTables();
        InstallerState::reset();
        parent::tearDown();
    }

    public function testIndex()
    {
        $this->get('/');

        $this->assertRedirect('install/dbconnection');
    }

    public function testWrongInstallerState()
    {
        $actions = [
            'salt',
            'connected',
            'migrate',
            'data',
            'finished',
        ];

        foreach ($actions as $action) {
            $this->get('install/' . $action);
            $this->assertRedirect('/');
        }
    }

    public function testMigrateAndData()
    {
        InstallerState::set('migrate');
        $this->post('install/migrate');

        $email = 'test@example.com';
        $this->post(
            'install/data',
            [
                'username' => 'admin',
                'password' => 'admin',
                'password_confirm' => 'admin',
                'user_email' => $email,
            ]
        );

        $this->assertRedirect('install/finished');

        $Settings = TableRegistry::getTableLocator()->get('Settings');
        $this->assertEquals($email, $Settings->findByName('forum_email')->first()->get('value'));

        $dbVersion = (new DbVersion($Settings))->get();
        $this->assertEquals(Configure::read('Saito.v'), $dbVersion);

        $Users = TableRegistry::getTableLocator()->get('Users');
        $admin = $Users->get(1);
        $this->assertTextContains('$2y$', $admin->get('password'));
        $this->assertEquals($email, $admin->get('user_email'));
    }

    /**
     * cakephp/migrations 5 records in `cake_seeds` which seeds have run and
     * skips them afterwards. A database that was emptied for a fresh start but
     * still carries that table must be seeded all the same, or the forum comes
     * up without settings, categories and smilies.
     */
    public function testMigrateSeedsAlthoughTheSeedsWereRunBefore()
    {
        // Let the library itself leave its seed log behind: install once,
        // then drop everything but `cake_seeds`.
        $connection = self::testConnection();
        $migrations = new Migrations(['connection' => 'test']);
        $migrations->migrate(['target' => 20180620093430]);
        $migrations->seed();
        foreach ($connection->getSchemaCollection()->listTables() as $table) {
            if ($table !== 'cake_seeds') {
                $connection->execute(sprintf('DROP TABLE `%s`', $table));
            }
        }
        $this->assertGreaterThan(
            0,
            (int)$connection->execute('SELECT COUNT(*) FROM `cake_seeds`')->fetch()[0],
            'The premise of this test: the seed log survived.',
        );

        InstallerState::set('migrate');
        $this->post('install/migrate');

        $count = fn (string $table): int => (int)$connection
            ->execute(sprintf('SELECT COUNT(*) FROM `%s`', $table))
            ->fetch()[0];
        $this->assertGreaterThan(0, $count('settings'));
        $this->assertGreaterThan(0, $count('categories'));
        $this->assertGreaterThan(0, $count('smilies'));
    }

    public function testConnectedDbExists()
    {
        InstallerState::set('connected');
        $this->createSettings();
        (new DbVersion(TableRegistry::getTableLocator()->get('Settings')))->set('4.10.0');

        $this->assertTrue(file_exists(CONFIG . 'installer'));

        $this->get('install/connected');

        $this->assertResponseCode(200);
    }

    private function createInstallerToken()
    {
        touch(CONFIG . 'installer');
    }

    private function createSettings()
    {
        $connection = self::testConnection();
        $connection->execute('DROP TABLE IF EXISTS `settings`;');
        $connection->execute('CREATE TABLE `settings` (id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT);');
        $connection->execute('ALTER TABLE settings ADD `name` VARCHAR(100)  NULL  DEFAULT NULL  AFTER `id`;');
        $connection->execute('ALTER TABLE settings ADD `value` VARCHAR(100)  NULL  DEFAULT NULL  AFTER `id`;');
        $connection->execute("INSERT INTO `settings` (`id`, `name`, `value`) VALUES ('1', 'db_version', NULL);");
        $connection->execute("INSERT INTO `settings` (`id`, `name`, `value`) VALUES ('2', 'forum_email', NULL);");
    }
}
