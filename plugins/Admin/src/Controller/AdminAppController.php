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

use App\Controller\AppController;
use Cake\Event\Event;

class AdminAppController extends AppController
{
    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->viewBuilder()->setLayout('Admin.admin');
    }
}
