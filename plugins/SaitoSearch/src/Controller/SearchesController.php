<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace SaitoSearch\Controller;

use App\Controller\AppController;
use App\Model\Table\EntriesTable;
use Cake\Chronos\Chronos;
use Cake\Database\Driver\Mysql;
use Cake\Event\Event;
use Cake\Http\Response;
use Cake\I18n\Date;
use SaitoSearch\Lib\SimpleSearchString;
use Saito\Exception\SaitoForbiddenException;
use Search\Controller\Component\PrgComponent;

/**
 * @property EntriesTable $Entries
 * @property PrgComponent $Prg
 */
class SearchesController extends AppController
{
    /** @var array CakePHP helpers */
    public $helpers = ['Form', 'Html', 'Posting'];

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Entries = $this->fetchTable('Entries');


        if (in_array($this->getRequest()->getParam('action'), ['simple', 'htmxSimple'], true)) {
            $this->Entries->addBehavior('SaitoSearch.SaitoSearch');
        } else {
            $this->Entries->addBehavior('Search.Search');
            // friendsofcake/search v6: Search component replaces PrgComponent
            $this->loadComponent('Search.Search');
            $this->Search->setConfig('actions', ['advanced']);
            $this->Search->setConfig('queryStringWhitelist', []);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['simple', 'htmxSimple']);
    }

    /**
     * Simple search
     *
     * @return void|Response
     */
    public function simple()
    {
        $this->set('titleForPage', __d('saito_search', 'simple.t'));

        $defaults = [
            'searchTerm' => '',
            'order' => 'time',
        ];

        // @td pgsql
        $connection = $this->Entries->getConnection();
        if (!($connection->getDriver() instanceof Mysql)) {
            return $this->redirect(['action' => 'advanced']);
        }

        $query = $this->request->getQueryParams();
        $query = array_intersect_key($query, array_flip(['searchTerm', 'order']));
        $query += $defaults;
        $this->set('searchDefaults', $query);

        $showEmptyForm = empty($query['searchTerm']);
        if ($showEmptyForm) {
            return;
        }

        $this->runSimpleSearch($query);
        $this->set('showBottomNavigation', true);
    }

    /**
     * Run the fulltext search for a query and set the result view vars.
     *
     * Shared by {@see simple()} (full SPA page) and {@see htmxSimple()} (htmx
     * island). Sets `results`, `omittedWords`, `minWordLength`.
     *
     * @param array $query normalised query params (`searchTerm`, `order`)
     * @return void
     */
    protected function runSimpleSearch(array $query): void
    {
        $searchString = new SimpleSearchString($query['searchTerm']);
        $finder = $query['order'] === 'rank' ? 'simpleSearchByRank' : 'simpleSearchByTime';
        $config = [
            'finder' => [
                $finder => [
                    'categories' => $this->CurrentUser->getCategories()->getAll('read'),
                    'searchTerm' => $searchString,
                ],
            ],
            // only sort paginate for "page"-query-param
            'allowedParameters' => ['page'],
        ];

        $results = $this->paginate($this->Entries, $config);
        $this->set('omittedWords', $searchString->getOmittedWords());
        $this->set('minWordLength', $searchString->getMinWordLength());
        $this->set('results', $results);
    }

    /**
     * Simple search as an htmx island (strangler-fig migration).
     *
     * Reuses the same fulltext search as {@see simple()} but renders standalone
     * (no SPA). The search form submits via htmx: an `HX-Request` returns only
     * the results fragment, which htmx swaps in place; a direct visit gets the
     * full shell page (form + results) in the htmx_island layout.
     *
     * @return void|\Cake\Http\Response
     */
    public function htmxSimple()
    {
        $this->set('titleForPage', __d('saito_search', 'simple.t'));

        // @td pgsql — the fulltext finder is MySQL-only, like simple().
        if (!($this->Entries->getConnection()->getDriver() instanceof Mysql)) {
            return $this->redirect(['action' => 'advanced']);
        }

        $query = $this->request->getQueryParams();
        $query = array_intersect_key($query, array_flip(['searchTerm', 'order']));
        $query += ['searchTerm' => '', 'order' => 'time'];
        $this->set('searchDefaults', $query);

        $this->set('results', null);
        if (!empty($query['searchTerm'])) {
            $this->runSimpleSearch($query);
        }

        // htmx swaps only the results fragment; a direct visit gets the shell.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()
                ->disableAutoLayout()
                ->setTemplate('htmx_results');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_simple');
        }
    }

    /**
     * Advanced Search
     *
     * @return void
     */
    public function advanced()
    {
        $this->set('titleForPage', __d('saito_search', 'advanced.t'));

        $queryData = $this->request->getQueryParams();

        $now = Chronos::now();

        /// Setup time filter data
        $first = $this->Entries->find()
            ->orderBy(['id' => 'ASC'])
            ->first();
        if ($first) {
            $startDate = $first->get('time');
            /// Limit default search range to one year in the past
            $aYearAgo = Chronos::now()->subYears(1);
            $defaultDate = $startDate < $aYearAgo ? $aYearAgo : $startDate;
        } else {
            /// No entries yet
            $startDate = $defaultDate = $now;
        }
        $startYear = $startDate->format('Y');

        // calculate current month and year
        $month = $queryData['month']['month'] ?? $defaultDate->month;
        $year = $queryData['year']['year'] ?? $defaultDate->year;
        $this->set(compact('month', 'year', 'startYear'));

        /// Category drop-down data
        $categories = $this->CurrentUser->getCategories()->getAll('read', 'select');
        $this->set('categories', $categories);

        if (empty($queryData['subject']) && empty($queryData['text']) && empty($queryData['name'])) {
            // just show form;
            return;
        }

        /// setup find
        $query = $this->Entries
            ->find('search', search: $queryData)
            ->contain(['Categories', 'Users'])
            ->orderBy(['Entries.id' => 'DESC']);

        /// Time filter
        $time = Chronos::createFromDate((int)$year, (int)$month, 1);
        if ($now->year !== $defaultDate->year || $now->month !== $defaultDate->month) {
            $query->where(['time >=' => $time]);
        }

        /// Category filter
        $categories = array_flip($categories);
        if (!empty($queryData['category_id'])) {
            $category = $queryData['category_id'];
            if (!in_array($category, $categories)) {
                throw new SaitoForbiddenException(
                    "Tried to search category $category.",
                    ['CurrentUser' => $this->CurrentUser]
                );
            }
            $categories = [$category];
        }
        $query->where(['category_id IN' => $categories]);

        $results = $this->paginate($query);
        $this->set(compact('results'));
        $this->set('showBottomNavigation', true);
    }
}
