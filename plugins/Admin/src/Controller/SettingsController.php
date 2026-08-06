<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Admin\Controller;

use App\Model\Table\SettingsTable;
use Cake\Http\Exception\NotFoundException;

/**
 * @property SettingsTable $Settings
 */
class SettingsController extends AdminAppController
{

    public $helpers = [
        'Admin.Setting',
        'TimeH',
    ];

    protected $settingsShownInAdminIndex = [
        'autolink' => ['type' => 'bool'],
        'bbcode_img' => ['type' => 'bool'],
        // Activates and deactivates the category-chooser on entries/index
        'category_chooser_global' => ['type' => 'bool'],
        // Allows users to show the category-chooser even if the default
        // setting `category_chooser_global` is off
        'category_chooser_user_override' => ['type' => 'bool'],
        'content_embed_active' => ['type' => 'bool'],
        'content_embed_media' => ['type' => 'bool'],
        'content_embed_text' => ['type' => 'bool'],
        'edit_delay' => 1,
        'edit_period' => 1,
        'email_contact' => 1,
        'email_register' => 1,
        'email_system' => 1,
        'forum_disabled' => ['type' => 'bool'],
        'forum_disabled_text' => 1,
        'forum_email' => 1,
        'forum_name' => 1,
        'quote_symbol' => 1,
        'signature_separator' => 1,
        // stopwatch_get is gone from here: the index template dropped its
        // section when the profiler chart died with the SPA, but leaving the key
        // listed kept /admin/settings/edit/stopwatch_get loading and saving a
        // switch that controls nothing. The database row stays, inert.
        'store_ip' => ['type' => 'bool'],
        'store_ip_anonymized' => ['type' => 'bool'],
        'subject_maxlength' => 1,
        'thread_depth_indent' => 1,
        'timezone' => 1,
        'topics_per_page' => 1,
        'tos_enabled' => ['type' => 'bool'],
        'tos_url' => 1,
        // The rank ladder. Both rows have been in the database since the
        // feature left the code in 2014 and were editable nowhere; the
        // reference install still held a fully configured ladder that nothing
        // displayed. Listed here so the thresholds can be changed without a
        // deploy — which matters, because a ladder whose top rung sits above
        // anything anyone has written is a ladder with an unreachable top.
        'userranks_show' => ['type' => 'bool'],
        'userranks_ranks' => 1,
        'video_domains_allowed' => 1,
    ];

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Settings = $this->fetchTable('Settings');
    }

    /**
     * index settings
     *
     * @return void
     */
    public function index()
    {
        $settings = $this->Settings->getSettings();
        foreach ($settings as $key => $value) {
            $this->request = $this->request->withData($key, $value);
        }
        $settings = array_intersect_key($settings, $this->settingsShownInAdminIndex);
        $this->set('Settings', $settings);
    }

    /**
     * edit setting
     *
     * @param string|null $id settings-ID
     *
     * @return \Cake\Http\Response|void
     */
    public function edit(?string $id = null)
    {
        if (empty($id)) {
            throw new NotFoundException();
        }

        $setting = $this->Settings->get($id);
        if (empty($setting)) {
            throw new NotFoundException();
        }

        if ($this->request->is(['post', 'put'])) {
            $this->Settings->patchEntity(
                $setting,
                $this->request->getData(),
                ['fields' => ['value']]
            );
            if ($this->Settings->save($setting)) {
                // @lo
                $this->Flash->set('Saved.', ['element' => 'notice']);

                return $this->redirect(['action' => 'index', '#' => $id]);
            }

            $errors = $setting->getErrors();
            // @lo
            $msg = !empty($errors) ? current(current($errors)) : 'Something went wrong';
            $this->Flash->set($msg, ['element' => 'error']);
        }

        $type = $this->settingsShownInAdminIndex[$id]['type'] ?? null;
        $setting->set('type', $type);

        $this->set('setting', $setting);

        if ($id === 'timezone') {
            return $this->render('timezone');
        }

        return null;
    }
}
