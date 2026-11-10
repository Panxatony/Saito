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
use Installer\Lib\IntegrationTestCase;
use LogicException;
use Migrations\Migrations;

class UpdaterControllerTest extends IntegrationTestCase
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

    public array $fixtures = ['app.Setting', 'plugin.Installer.Phinxlog'];

    /** @var string */
    protected $tokenPath;

    /** @var DbVersion */
    protected $dbVersion;

    public function setUp(): void
    {
        parent::setUp();
        $this->tokenPath = CONFIG . 'updater';
        $this->dbVersion = (new DbVersion(TableRegistry::getTableLocator()->get('Settings')));
        Configure::write('Saito.updated', false);
        @unlink(TMP . 'updater-start-attempts.json');
    }

    public function tearDown(): void
    {
        if (file_exists($this->tokenPath)) {
            unlink($this->tokenPath);
        }
        @unlink(TMP . 'updater-start-attempts.json');
        parent::tearDown();
    }

    /**
     * Put the schema back the way the suite expects to find it.
     *
     * Not because of the `DROP TABLE phinxlog` three of these tests perform —
     * those are deliberate, and the installer they exercise rebuilds the log.
     * It is the `plugin.Installer.Phinxlog` fixture: the fixture manager
     * truncates it when the test finishes, which is exactly its job, and an
     * empty migration log beside a full schema is the one state phinx cannot
     * work with.
     *
     * tests/bootstrap.php then took its recovery path on the next run — drop
     * every table, migrate from scratch. That path is meant for a crash, and it
     * was running every single time.
     *
     * Once per class, not per test: rebuilding between tests refills `settings`
     * from the migrations, and the next test's fixture then collides with those
     * rows. The state only has to be right for whatever comes after this class.
     *
     * Measured before this: 11 recorded migrations going in, 0 coming out.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        $connection = self::testConnection();
        $vorhanden = $connection->getSchemaCollection()->listTables();
        $leer = !in_array('phinxlog', $vorhanden, true);
        if (!$leer) {
            $leer = (int)$connection
                ->execute('SELECT COUNT(*) FROM `phinxlog`')
                ->fetch()[0] === 0;
        }
        if (!$leer) {
            return;
        }

        // Drop what the partial migration left behind, then build it up again —
        // the same thing the bootstrap would otherwise do later, but here, where
        // the test that caused it can pay for it.
        foreach (
            [
                'bookmarks', 'categories', 'drafts', 'entries', 'password_reset_tokens',
                'phinxlog', 'settings', 'smiley_codes', 'smilies',
                'two_factor_credentials', 'two_factor_recovery_codes',
                'two_factor_trusted_devices', 'uploads', 'webauthn_credentials',
                'user_blocks', 'user_ignores', 'user_reads', 'useronline', 'users',
                'esevents', 'esnotifications', 'shouts',
            ] as $table
        ) {
            $connection->execute(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
        (new Migrations())->migrate(['connection' => 'test']);

        // Migrating also writes the default rows some migrations insert, and the
        // next test class inserts its own fixtures on top of them — a primary
        // key collision. Only the migration log is worth keeping here; empty
        // everything else, which is the state the bootstrap leaves behind too.
        foreach ($connection->getSchemaCollection()->listTables() as $table) {
            if ($table === 'phinxlog') {
                continue;
            }
            $connection->execute(sprintf('TRUNCATE TABLE `%s`', $table));
        }
    }

    public function testUpdaterShowFailureAfterAbortedUpdated()
    {
        file_put_contents($this->tokenPath, '');
        $this->get('/');

        $this->assertResponseOk();
        $this->assertEquals('failure', $this->_controller->viewBuilder()->getTemplate());
        $this->assertResponseContains((string)1529737182);
    }

    public function testUpdaterShowFailureNoDbVersionString()
    {
        $this->dbVersion->set(null);
        $this->get('/');

        $this->assertResponseOk();
        $this->assertEquals('failure', $this->_controller->viewBuilder()->getTemplate());
        $this->assertResponseContains((string)1529737397);
    }

    public function testUpdaterShowFailureWrongDbVersionString()
    {
        $this->dbVersion->set('4.9.99');
        $this->get('/');

        $this->assertResponseOk();
        $this->assertEquals('failure', $this->_controller->viewBuilder()->getTemplate());
        $this->assertResponseContains((string)1529737648);
    }

    public function testUpdaterInitMigrationsFormEmpty()
    {
        $this->dropTables();
        $migration = new Migrations(['connection' => 'test']);
        $migration->migrate(['target' => 20180620081553]);
        $migration->seed(['seed' => 'SettingsSeed']);
        $this->dbVersion->set('4.10.0');
        $connection = self::testConnection();
        $connection->execute('DROP TABLE `phinxlog`;');

        $this->post('/');

        $this->assertResponseOk();
        $this->assertEquals('start', $this->_controller->viewBuilder()->getTemplate());

        $this->assertTrue($this->viewVariable('startAuthError'));

        $status = $migration->status();
        $this->assertEquals('down', array_pop($status)['status']);
    }

    public function testUpdaterInitMigrationsFailureWrongPassword()
    {
        $this->dropTables();
        $migration = new Migrations(['connection' => 'test']);
        $migration->migrate(['target' => 20180620081553]);
        $migration->seed(['seed' => 'SettingsSeed']);
        $this->dbVersion->set('4.10.0');
        $connection = self::testConnection();
        $connection->execute('DROP TABLE `phinxlog`;');

        $connection = ConnectionManager::get('default');
        $config = $connection->config();

        $this->mockSecurity();
        $this->post('/', ['dbname' => $config['database'], 'dbpassword' => 'foobar']);

        $this->assertResponseOk();
        $this->assertEquals('start', $this->_controller->viewBuilder()->getTemplate());

        $this->assertTrue($this->viewVariable('startAuthError'));

        $status = $migration->status();
        $this->assertEquals('down', array_pop($status)['status']);
    }

    /**
     * Anybody reaches the start form while an update is pending, and it asks
     * for the database password. After ten wrong tries even the right one is
     * refused for a while (#170).
     */
    public function testUpdaterStartIsThrottled()
    {
        $this->dropTables();
        $migration = new Migrations(['connection' => 'test']);
        $migration->migrate(['target' => 20180620081553]);
        $migration->seed(['seed' => 'SettingsSeed']);
        $this->dbVersion->set('4.10.0');
        $connection = self::testConnection();
        $connection->execute('DROP TABLE `phinxlog`;');
        $config = ConnectionManager::get('default')->config();

        for ($i = 0; $i < 10; $i++) {
            $this->mockSecurity();
            $this->post('/', ['dbname' => $config['database'], 'dbpassword' => 'guess-' . $i]);
        }
        $this->mockSecurity();
        $this->post('/', ['dbname' => $config['database'], 'dbpassword' => $config['password']]);

        $this->assertEquals('start', $this->_controller->viewBuilder()->getTemplate());
        $this->assertTrue($this->viewVariable('startAuthError'));
        $status = $migration->status();
        $this->assertEquals('down', array_pop($status)['status']);
    }

    public function testUpdaterInitMigrationsSuccess()
    {
        $this->dropTables();
        $migration = new Migrations(['connection' => 'test']);
        $migration->migrate(['target' => 20180620081553]);
        $migration->seed(['seed' => 'SettingsSeed']);
        $this->dbVersion->set('4.10.0');
        $connection = self::testConnection();
        $connection->execute('DROP TABLE `phinxlog`;');

        $connection = ConnectionManager::get('default');
        $config = $connection->config();

        $this->mockSecurity();
        $this->post('/', ['dbname' => $config['database'], 'dbpassword' => $config['password']]);

        $this->assertResponseOk();
        $this->assertEquals('success', $this->_controller->viewBuilder()->getTemplate());

        $status = $migration->status();
        $this->assertEquals('up', array_pop($status)['status']);
    }
}
