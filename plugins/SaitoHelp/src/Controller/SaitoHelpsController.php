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
use SaitoHelp\Model\Table\SaitoHelpTable;

/**
 * @property SaitoHelpTable $SaitoHelp
 */
class SaitoHelpsController extends AppController
{
    /**
     * redirects help/<id> to help/<current language>/id
     *
     * @param string $id help page ID
     * @return void
     */
    public function languageRedirect($id)
    {
        $this->autoRender = false;
        $language = Configure::read('Saito.language');
        $this->redirect("/help/$language/$id");
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
        $this->set('titleForPage', __('Help'));
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
                str_contains((string)$help->get('text'), '<!-- admin -->')
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
        $this->Authentication->allowUnauthenticated(['languageRedirect', 'view', 'index']);
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
        $collect = function (string $lang): array {
            $folderPath = ROOT . DS . 'docs' . DS . 'help' . DS . $lang;
            if (!is_dir($folderPath)) {
                return [];
            }

            $topics = [];
            foreach (array_diff(scandir($folderPath), ['.', '..']) as $file) {
                if (!preg_match('/^(?<id>[^-.]+)(-.*?)?\.md$/', $file, $m)) {
                    continue;
                }
                $text = (string)file_get_contents($folderPath . DS . $file);
                $topics[$m['id']] = [
                    'id' => $m['id'],
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
