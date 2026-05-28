<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller\Component;

use App\Model\Table\EntriesTable;
use Cake\Controller\Component;
use Cake\Core\Configure;
use Cake\ORM\Entity;
use Saito\Posting\Basic\BasicPostingInterface;
use Saito\User\CurrentUser\CurrentUserInterface;
use Stopwatch\Lib\Stopwatch;

/**
 * Class ThreadsComponent
 *
 * @property AuthUserComponent $AuthUser
 */
#[\AllowDynamicProperties]
class ThreadsComponent extends Component
{
    public array $components = ['AuthUser'];

    /**
     * Entries table
     *
     * @var EntriesTable
     */
    protected $Table;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->Table = $config['table'];
    }

    /**
     * Load paginated threads
     *
     * @param mixed $order order to apply
     * @param CurrentUserInterface $CurrentUser CurrentUser
     * @return array
     */
    public function paginate($order, CurrentUserInterface $CurrentUser): array
    {
        $initials = $this->paginateThreads($order, $CurrentUser);
        if (empty($initials)) {
            return [];
        }

        return $this->Table->postingsForThreads($initials, $order, $CurrentUser);
    }

    /**
     * Gets thread ids for paginated entries/index.
     *
     * @param array $order sort order
     * @param CurrentUserInterface $User current-user
     * @return array thread ids
     */
    protected function paginateThreads($order, CurrentUserInterface $User): array
    {
        Stopwatch::start('Entries->_getInitialThreads() Paginate');
        $categories = $User->getCategories()->getCurrent('read');
        if (empty($categories)) {
            // no readable categories for user (e.g. no public categories
            return [];
        }

        ////! Check DB performance after changing conditions/sorting!
        $customFinderOptions = [
            'conditions' => [
                'Entries.category_id IN' => $categories,
            ],
            // @td sanitize input?
            'limit' => (int)Configure::read('Saito.Settings.topics_per_page'),
            'order' => $order,
            // Performance: Custom counter from categories counter-cache;
            // avoids a costly COUNT(*) DB call counting all pages for pagination.
            'counter' => function ($query) use ($categories) {
                $results = $this->Table->Categories->find('all')
                ->select(['thread_count'])
                ->where(['id IN' => $categories])
                ->all();
                $count = array_reduce(
                    $results->toArray(),
                    function ($carry, Entity $entity) {
                        return $carry + $entity->get('thread_count');
                    },
                    0
                );

                return $count;
            },
        ];

        $settings = [
            'finder' => ['indexPaginator' => $customFinderOptions],
            'allowedParameters' => ['page'],
            'limit' => (int)Configure::read('Saito.Settings.topics_per_page'),
        ];
        $initialThreads = $this->getController()->paginate($this->Table, $settings);

        // Expose the PaginatedInterface to view so PaginatorHelper can find it
        $this->getController()->set('_paginationMeta', $initialThreads);

        $initialThreadsNew = [];
        foreach ($initialThreads as $k => $v) {
            $initialThreadsNew[$k] = $v['id'];
        }
        Stopwatch::stop('Entries->_getInitialThreads() Paginate');

        return $initialThreadsNew;
    }

    /**
     * Increment views for all postings in thread
     *
     * @param BasicPostingInterface $posting posting
     * @param CurrentUserInterface $CurrentUser current user
     * @return void
     */
    public function incrementViewsForThread(BasicPostingInterface $posting, CurrentUserInterface $CurrentUser)
    {
        if ($this->AuthUser->isBot()) {
            return;
        }

        $where = ['tid' => $posting->get('tid')];
        if ($CurrentUser->isLoggedIn()) {
            $where['user_id !='] = $CurrentUser->getId();
        }

        $this->Table->increment($where, 'views');
    }

    /**
     * Increment views for posting if posting
     *
     * @param BasicPostingInterface $posting posting
     * @param CurrentUserInterface $CurrentUser current user
     * @return void
     */
    public function incrementViewsForPosting(BasicPostingInterface $posting, CurrentUserInterface $CurrentUser)
    {
        if ($this->AuthUser->isBot()) {
            return;
        }

        if (
            $CurrentUser->isLoggedIn()
            && ($posting->get('user_id') === $CurrentUser->getId())
        ) {
            return;
        }

        $this->Table->increment($posting->get('id'), 'views');
    }
}
