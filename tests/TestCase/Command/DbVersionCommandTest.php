<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * Reading and writing the version the database believes it is at.
 *
 * The reporting half matters more than the setting half: a deploy script asks
 * before it acts, and it has to be able to tell "they agree" from "they differ"
 * without either answer being treated as a failure.
 */
class DbVersionCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    public array $fixtures = ['app.Setting'];

    private function dbVersion(): string
    {
        $row = TableRegistry::getTableLocator()->get('Settings')
            ->find()->where(['name' => 'db_version'])->first();

        return (string)$row->get('value');
    }

    private function setDbVersion(string $value): void
    {
        $Settings = TableRegistry::getTableLocator()->get('Settings');
        $row = $Settings->find()->where(['name' => 'db_version'])->first();
        $row->set('value', $value);
        $Settings->saveOrFail($row);
    }

    /**
     * @return void
     */
    public function testItReportsAgreement(): void
    {
        Configure::write('Saito.v', '9.9.9');
        $this->setDbVersion('9.9.9');

        $this->exec('db_version');

        $this->assertExitSuccess();
        $this->assertOutputContains('9.9.9');
        $this->assertOutputContains('in agreement');
    }

    /**
     * Drift is the state between copying files and setting the row, so it is
     * reported rather than treated as an error — a deploy script asking the
     * question must not be told it has failed.
     *
     * @return void
     */
    public function testDriftIsReportedButNotAFailure(): void
    {
        Configure::write('Saito.v', '9.9.9');
        $this->setDbVersion('8.8.8');

        $this->exec('db_version');

        $this->assertExitSuccess();
        $this->assertErrorContains('differ');
    }

    /**
     * @return void
     */
    public function testItSetsTheVersion(): void
    {
        $this->setDbVersion('8.8.8');

        $this->exec('db_version 9.9.9');

        $this->assertExitSuccess();
        $this->assertSame('9.9.9', $this->dbVersion());
    }

    /**
     * @return void
     */
    public function testSettingWhatIsAlreadySetChangesNothing(): void
    {
        $this->setDbVersion('9.9.9');

        $this->exec('db_version 9.9.9');

        $this->assertExitSuccess();
        $this->assertOutputContains('nothing to do');
        $this->assertSame('9.9.9', $this->dbVersion());
    }
}
