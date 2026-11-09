<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Installer\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\I18n\I18n;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Installer\Form\UpdaterStartForm;
use Installer\Lib\DbVersion;
use Psr\Log\LogLevel;

/**
 * Updater Controller
 */
class UpdaterController extends AppController
{
    /** @var string */
    private $dbVersion;

    /** @var string */
    private $saitoVersion;

    /**
     * Failed start attempts per client and window. Every visitor reaches this
     * page while the versions differ, and the form asks for the database
     * password: without a limit it could be guessed for as long as the window
     * stays open. Kept in a file, because caches are off in here.
     */
    private const START_MAX_ATTEMPTS = 10;
    private const START_WINDOW = 900;

    protected bool $clearCachesOnEveryRequest = false;

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->dbVersion = Configure::read('Saito.Settings.db_version');
        $this->saitoVersion = Configure::read('Saito.v');
    }

    /**
     * @return string file holding the failed-attempt counters
     */
    private function startAttemptsFile(): string
    {
        return TMP . 'updater-start-attempts.json';
    }

    /**
     * @return array<string, array{count: int, first: int}>
     */
    private function readStartAttempts(): array
    {
        $raw = @file_get_contents($this->startAttemptsFile());
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @return string the client's key, not the address itself
     */
    private function startAttemptsKey(): string
    {
        return hash('sha256', (string)$this->getRequest()->clientIp());
    }

    /**
     * @return bool whether this client has used up its attempts for now
     */
    private function startAttemptsExhausted(): bool
    {
        $record = $this->readStartAttempts()[$this->startAttemptsKey()] ?? null;

        return is_array($record)
            && time() - (int)$record['first'] < self::START_WINDOW
            && (int)$record['count'] >= self::START_MAX_ATTEMPTS;
    }

    /**
     * @return void
     */
    private function registerFailedStart(): void
    {
        $all = array_filter(
            $this->readStartAttempts(),
            fn($r): bool => is_array($r) && time() - (int)($r['first'] ?? 0) < self::START_WINDOW,
        );
        $key = $this->startAttemptsKey();
        $record = $all[$key] ?? ['count' => 0, 'first' => time()];
        $record['count']++;
        $all[$key] = $record;
        file_put_contents($this->startAttemptsFile(), (string)json_encode($all), LOCK_EX);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeRender(\Cake\Event\EventInterface $event)
    {
        $this->set('titleForLayout', __d('installer', 'update.title'));
    }

    /**
     * Index method
     *
     * @return \Cake\Http\Response|void
     */
    public function start()
    {
        $tokenPath = CONFIG . 'updater';

        if (file_exists($tokenPath)) {
            $this->renderFailure(__d('installer', 'update.failure.explanation'), 1529737182);

            return;
        }

        $this->set('dbVersion', $this->dbVersion);
        $this->set('saitoVersion', $this->saitoVersion);

        if (empty($this->dbVersion)) {
            $msg = __d('installer', 'update.failure.nodbversion');
            $this->renderFailure($msg, 1529737397);
            $this->log($msg, LogLevel::ERROR, ['saito.install']);

            return;
        }

        if (version_compare($this->dbVersion, '4.10.0', '<')) {
            $msg = __d('installer', 'update.failure.wrongdbversion', ['v' => $this->dbVersion]);
            $this->renderFailure($msg, 1529737648);
            $this->log($msg, LogLevel::ERROR, ['saito.install']);

            return;
        }

        $startForm = new UpdaterStartForm();
        $this->set('startForm', $startForm);

        if (!$this->getRequest()->is('post')) {
            return;
        }

        if ($this->startAttemptsExhausted()) {
            $this->set('startAuthError', true);

            return;
        }

        if (!$startForm->execute($this->request->getData())) {
            $this->registerFailedStart();
            // don't emit errors in frontend in case form is accidental on live-installation
            $startForm->setErrors([]);
            $this->set('startAuthError', true);

            return;
        }

        $this->logCurrentState('Pre-update state.');

        file_put_contents($tokenPath, '');

        try {
            if (
                version_compare($this->dbVersion, '4.10.0', '>=')
                && version_compare($this->dbVersion, '5.0.0', '<')
            ) {
                $this->migrations->markMigrated('20180620081553');
                $this->logCurrentState('Marked version 4.10.0 migrated.');
            }

            $this->migrations->migrate();
            (new DbVersion($this->fetchTable('Settings')))->set($this->saitoVersion);
            $this->logCurrentState('Post upgrade state.');
        } catch (\Throwable $e) {
            $this->logCurrentState('Migration failed: ' . $e->getMessage());

            return $this->redirect('/');
        }

        if (file_exists($tokenPath)) {
            unlink($tokenPath);
        }
        // Once, now that the schema and code agree — not on every visit.
        Cache::enable();
        Cache::clearAll();
        Cache::disable();
        $this->viewBuilder()->setTemplate('success');

        $this->logCurrentState('Update successfull.');
    }

    /**
     * Render the update error template
     *
     * @param string $message message for failure
     * @param int $code failure-code
     * @return void
     */
    private function renderFailure(string $message, int $code)
    {
        $this->viewBuilder()->setTemplate('failure');
        $this->set('code', $code);
        $this->set('incident', $message);
    }

    /**
     * Log current strate
     *
     * @param string $message message for log
     * @return void
     */
    private function logCurrentState(string $message)
    {
        $this->log($message, LogLevel::INFO, ['saito.install']);
        $content = [
            'date' => date('c'),
            'dbVersion' => $this->dbVersion,
            'saitoVersion' => $this->saitoVersion,
            'status' => $this->migrations->status(),
        ];
        $this->log(print_r($content, true), LogLevel::INFO, ['saito.install']);
    }
}
