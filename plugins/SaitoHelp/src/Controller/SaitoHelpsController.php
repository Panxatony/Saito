<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace SaitoHelp\Controller;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Event\Event;
use Cake\Http\Response;
use Cake\ORM\Entity;

/**
 */
class SaitoHelpsController extends AppController
{
    /**
     * redirects help/<id> to help/<current language>/id
     *
     * @param string $id help page ID
     * @return \Cake\Http\Response
     */
    public function languageRedirect($id)
    {
        $this->autoRender = false;
        $language = Configure::read('Saito.language');

        return $this->redirect("/help/$language/$id");
    }

    /**
     * Central overview page listing all available help topics.
     *
     * @return void
     */
    public function index()
    {
        $lang = (string)Configure::read('Saito.language');
        $isAdmin = (bool)$this->CurrentUser->permission('saito.core.admin.backend');
        $this->set('topics', $this->findAll($lang, $isAdmin));
        $this->set('lang', $lang);
        $this->set('titleForPage', __('Help'));
    }

    /**
     * The help overlay's content: the guided tour and the list of topics.
     *
     * Loaded on demand rather than rendered into every page — the overlay is
     * opened rarely and its markup is not small.
     *
     * @return void
     */
    public function tour()
    {
        $lang = (string)Configure::read('Saito.language');
        $isAdmin = (bool)$this->CurrentUser->permission('saito.core.admin.backend');
        // Only whether there is anywhere to go — the topics themselves belong on
        // /help, not repeated in the overlay.
        $this->set('hasTopics', $this->findAll($lang, $isAdmin) !== []);
        $this->viewBuilder()->disableAutoLayout();
    }

    /**
     * View a help page.
     *
     * @param string $lang language
     * @param string $id help page ID
     * @return Response|Null
     */
    public function view($lang, $id)
    {
        $help = $this->find($lang, $id);

        if (!$help && $lang !== 'en') {
            // Help file at least for localization not found. Try to fallback to
            // english default language.
            return $this->redirect("/help/en/$id");
        }
        if ($help) {
            // Admin-only topics are marked with an `<!-- admin -->` comment.
            // findAll() hides them from the overview for non-admins, but view()
            // must enforce it too — otherwise anyone guessing the id could read
            // an admin topic directly. Treat it as not-found for non-admins.
            if (
                $this->isAdminTopic($id, $lang)
                && !$this->CurrentUser->permission('saito.core.admin.backend')
            ) {
                $this->Flash->set(__('sh.nf'), ['element' => 'error']);

                return $this->redirect('/');
            }
            $this->set('help', $help);
        } else {
            $this->Flash->set(__('sh.nf'), ['element' => 'error']);

            return $this->redirect('/');
        }

        $isCore = !strpos($id, '.');
        $this->set(compact('isCore'));

        $this->set('titleForPage', __('Help'));

        // Opened from the help overlay: return the topic alone, so it can be
        // swapped in beside the tour instead of navigating away from whatever
        // the reader was doing.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_view');
        }

        // Render the help page; explicit null so all paths return (the
        // redirect paths above return a Response).
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['languageRedirect', 'view', 'index', 'tour']);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeRender(\Cake\Event\EventInterface $event)
    {
        parent::beforeRender($event);
        $this->viewBuilder()->setLayout('htmx_island');
    }

    /**
     * Loads help file
     *
     * @param string $lang Language. Folder docs/help/<langugage>
     * @param string $id Plugin file id. [<plugin>.]<id>
     * @return Entity|null Null if help file wan't found
     */
    private function find(string $lang, string $id): ?Entity
    {
        $findFiles = function ($id, $lang) {
            list($plugin, $id) = pluginSplit($id);
            if ($plugin) {
                $folderPath = Plugin::path($plugin);
            } else {
                $folderPath = ROOT . DS;
            }
            $folderPath .= 'docs' . DS . 'help' . DS . $lang;

            $files = [];
            if (is_dir($folderPath)) {
                $allFiles = array_values(array_diff(scandir($folderPath), ['.', '..']));
                $files = preg_grep('/^' . preg_quote($id, '/') . '(-.*?)?\.md$/', $allFiles);
                sort($files);
                $files = array_values($files);
            }

            return [$files, $folderPath];
        };

        list($files, $folderPath) = $findFiles($id, $lang);

        if (empty($files)) {
            list($lang) = explode('_', $lang);
            list($files, $folderPath) = $findFiles($id, $lang);
        }

        if (!$files) {
            return null;
        }
        $name = $files[0];
        $text = file_get_contents($folderPath . DS . $name);
        $data = [
            'file' => $name,
            'id' => $id,
            'lang' => $lang,
            'text' => $text,
        ];
        $result = new Entity($data);

        return $result;
    }

    /**
     * Whether a topic is for administrators only.
     *
     * Decided from the **English** file, whatever language is being served. The
     * marker used to be read off whichever file had been found, which made the
     * visibility of a topic a property of its translation: leave the
     * `<!-- admin -->` line out of a new translation and that topic quietly
     * became public in that language. Nothing would have failed, and the mistake
     * is invisible in the file that contains it.
     *
     * English is the baseline everywhere else too — findAll() collects it first
     * and view() falls back to it — so it is the one place a topic exists
     * exactly once. The requested language is consulted only when there is no
     * English file at all, which a plugin shipping a single language may do.
     *
     * @param string $id help page id, optionally `<Plugin>.<id>`
     * @param string $lang language being served, used only as a fallback
     * @return bool
     */
    private function isAdminTopic(string $id, string $lang): bool
    {
        $help = $this->find('en', $id);
        if ($help === null && $lang !== 'en') {
            $help = $this->find($lang, $id);
        }
        if ($help === null) {
            return false;
        }

        return str_contains((string)$help->get('text'), '<!-- admin -->');
    }

    /**
     * Lists all core help topics for the overview page.
     *
     * Topics available only in English (e.g. admin help) are still listed;
     * the per-topic English fallback in view() serves them. Admin-only topics
     * (marked with an `<!-- admin -->` comment) are shown to admins only.
     *
     * @param string $lang language. Folder docs/help/<language>
     * @param bool $isAdmin whether the current user may see admin topics
     * @return array<array{id: string, title: string, admin: bool}> topics sorted by id
     */
    private function findAll(string $lang, bool $isAdmin): array
    {
        $collect = function (string $lang, ?string $plugin = null): array {
            $folderPath = ($plugin === null ? ROOT . DS : Plugin::path($plugin))
                . 'docs' . DS . 'help' . DS . $lang;
            if (!is_dir($folderPath)) {
                return [];
            }
            // A plugin's topics are addressed as `<Plugin>.<id>`, which is what
            // find() already understands.
            $prefix = $plugin === null ? '' : $plugin . '.';

            $topics = [];
            foreach (array_diff(scandir($folderPath), ['.', '..']) as $file) {
                // overlay.md is the guided tour shown in the help overlay, not a
                // topic of its own — listing it here would offer the same text
                // twice under two different names.
                if ($file === 'overlay.md') {
                    continue;
                }
                if (!preg_match('/^(?<id>[^-.]+)(-.*?)?\.md$/', $file, $m)) {
                    continue;
                }
                $text = (string)file_get_contents($folderPath . DS . $file);
                $topics[$prefix . $m['id']] = [
                    'id' => $prefix . $m['id'],
                    'title' => $this->extractTitle($text),
                    'admin' => str_contains($text, '<!-- admin -->'),
                ];
            }

            return $topics;
        };

        // English as the baseline, overridden by the localized titles.
        $topics = $collect('en');
        if ($lang !== 'en') {
            $topics = array_replace($topics, $collect($lang));
        }

        // Plugins carry help of their own — the BBCode reference is the one that
        // matters most to a reader, and it was written years ago but has never
        // been listed anywhere, because this only ever looked in the core.
        foreach (Plugin::loaded() as $plugin) {
            $fromPlugin = $collect('en', $plugin);
            if ($lang !== 'en') {
                $fromPlugin = array_replace($fromPlugin, $collect($lang, $plugin));
            }
            $topics += $fromPlugin;
        }

        // Re-decide "admin only" per topic instead of keeping whatever the
        // collected file happened to say. A localized topic replaces the English
        // baseline entry wholesale above, so a translation that omitted the
        // `<!-- admin -->` line made that topic public in that language — with no
        // test or lint to notice, and no way to tell from the outside.
        foreach ($topics as $id => $topic) {
            $topics[$id]['admin'] = $this->isAdminTopic((string)$id, $lang);
        }

        if (!$isAdmin) {
            $topics = array_filter($topics, fn(array $topic): bool => !$topic['admin']);
        }

        uksort($topics, 'strnatcmp');

        return array_values($topics);
    }

    /**
     * Extracts a topic title from the first Markdown heading.
     *
     * @param string $markdown help file contents
     * @return string heading text, or empty string when none is found
     */
    private function extractTitle(string $markdown): string
    {
        foreach (explode("\n", $markdown) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#')) {
                return trim($line, "# \t");
            }
        }

        return '';
    }
}
