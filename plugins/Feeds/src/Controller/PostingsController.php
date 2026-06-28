<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\Controller;

use App\Controller\AppController;
use App\Model\Table\EntriesTable;
use Cake\Event\Event;
use Cake\Http\Exception\BadRequestException;
use Feeds\Model\Behavior\FeedsPostingBehavior;

/**
 * Feed Posting Controller
 *
 * @property EntriesTable $Entries
 */
class PostingsController extends AppController
{
    public $helpers = ['Feeds.Feeds'];

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();

        /** @var EntriesTable */
        $this->Entries = $this->fetchTable('Entries');
        $this->Entries->addBehavior(FeedsPostingBehavior::class);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['new', 'threads']);
        $this->viewBuilder()->enableAutoLayout(false);
        $this->viewBuilder()->setTemplate('posting');
    }

    /**
     * RSS-feed for postings.
     *
     * @return void
     */
    public function new(): void
    {
        $this->checkRss();

        $entries = $this->Entries
            ->find('feed')
            ->where(['category_id IN' => $this->CurrentUser->getCategories()->getAll('read')]);
        $this->set('entries', $entries);

        $this->set('titleForPage', __d('feeds', 'postings.new.t'));
    }

    /**
     * RSS-feed for new threads.
     *
     * @return void
     */
    public function threads(): void
    {
        $this->checkRss();

        $entries = $this->Entries
            ->find('feed')
            ->where([
                'category_id IN' => $this->CurrentUser->getCategories()->getAll('read'),
                'pid' => 0,
            ]);
        $this->set('entries', $entries);

        $this->set('titleForPage', __d('feeds', 'threads.new.t'));
    }

    /**
     * Check that request is Rss
     *
     * @throws BadRequestException
     * @return void
     */
    private function checkRss(): void
    {
        if ($this->request->accepts('application/rss+xml') || $this->request->getParam('_ext') === 'rss') {
            // Cake 5 removed RequestHandlerComponent, which in 3.x set the
            // content type from the `.rss` extension automatically. Set it
            // explicitly so feed readers receive application/rss+xml.
            $this->response = $this->response->withType('rss');

            return;
        }
        throw new BadRequestException();
    }
}
