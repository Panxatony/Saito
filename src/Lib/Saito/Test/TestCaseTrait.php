<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Test;

use Cake\Core\Configure;
use Cake\Event\EventManager;
use Cake\I18n\I18n;
use Cake\Mailer\TransportFactory;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Saito\App\Registry;
use Saito\Cache\CacheSupport;

trait TestCaseTrait
{
    private $saitoSettings;

    protected $saitoPermissions;

    private bool $_saitoSetupDone = false;

    /**
     * set-up saito
     *
     * @return void
     */
    protected function setUpSaito()
    {
        $this->_saitoSetupDone = true;
        Registry::initialize();

        $this->_storeSettings();
        $this->mockMailTransporter();
        $this->_clearCaches();
    }

    /**
     * tear down saito
     *
     * @return void
     */
    protected function tearDownSaito()
    {
        if (!$this->_saitoSetupDone) {
            return;
        }
        $this->_saitoSetupDone = false;
        $this->_restoreSettings();
        $this->_clearCaches();
    }

    /**
     * clear caches
     *
     * @return void
     */
    protected function _clearCaches()
    {
        $CacheSupport = new CacheSupport();
        $CacheSupport->clear();
        EventManager::instance()->off($CacheSupport);
        unset($CacheSupport);
    }

    /**
     * store global settings
     *
     * @return void
     */
    protected function _storeSettings()
    {
        $this->saitoSettings = Configure::read('Saito.Settings');
        $this->saitoPermissions = clone(Configure::read('Saito.Permission.Resources'));
        $this->setI18n('en');
        Configure::write('Saito.Settings.ParserPlugin', \Plugin\BbcodeParser\src\Lib\Markup::class);
        Configure::write('Saito.Settings.uploader', clone($this->saitoSettings['uploader']));
    }

    /**
     * restore global settings
     *
     * @return void
     */
    protected function _restoreSettings()
    {
        Configure::write('Saito.Settings', $this->saitoSettings);
        Configure::write('Saito.Permission.Resources', $this->saitoPermissions);
    }

    /**
     * Set the current translation language
     *
     * @param string $lang language code
     * @return void
     */
    public function setI18n(string $lang): void
    {
        Configure::write('Saito.language', $lang);
        I18n::setLocale($lang);
    }

    /**
     * Mock table
     *
     * @param string $table table
     * @param array $methods methods to mock
     * @return mixed
     */
    public function getMockForTable($table, array $methods = [])
    {
        $tableName = Inflector::underscore($table);
        $Mock = $this->getMockForModel(
            $table,
            $methods,
            ['table' => strtolower($tableName)]
        );

        return $Mock;
    }

    /**
     * Insert categories into permissions
     *
     * @return void
     */
    protected function insertCategoryPermissions(): void
    {
        Registry::get('Permissions')
            ->buildCategories(TableRegistry::getTableLocator()->get('Categories'));
    }

    /**
     * Mock mailtransporter
     *
     * @return mixed
     */
    protected function mockMailTransporter()
    {
        $mock = $this->createMock('Cake\Mailer\Transport\DebugTransport');
        TransportFactory::drop('saito');
        TransportFactory::setConfig('saito', $mock);
        // The 'saito' Mailer profile points at the 'saito' transport so
        // `new Mailer('saito')` actually uses the mocked transport.
        // Cake 5 dropped Cake\Mailer\Email; Mailer is the replacement.
        \Cake\Mailer\Mailer::drop('saito');
        \Cake\Mailer\Mailer::setConfig('saito', [
            'transport' => 'saito',
            'from' => 'system@example.com',
        ]);

        return $mock;
    }

    /**
     * Creates a mock image file at $filePath
     *
     * @param string $filePath Absolute path with extension.
     *
     * Mime type is taken from extension. Allowed extensions: png, jpeg, jpg
     *
     * @param int $size size of the mock image in kB
     * @return void
     */
    protected function mockMediaFile(string $filePath, int $size = 100): void
    {
        $Image = imagecreatetruecolor(1, 1);
        imagesetpixel($Image, 0, 0, imagecolorallocate($Image, 0, 0, 0));

        switch (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            case 'jpeg':
            case 'jpg':
                imagejpeg($Image, $filePath);
                break;
            case 'png':
                imagepng($Image, $filePath);
                break;
            default:
                throw new \InvalidArgumentException();
        }

        file_put_contents($filePath, str_repeat('0', $size * 1024), FILE_APPEND);
    }
}
