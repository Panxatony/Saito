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

use App\Model\Table\SmiliesTable;

/**
 * @property SmiliesTable $Smilies
 */
class SmiliesController extends AdminAppController
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Smilies = $this->fetchTable('Smilies');
    }

    /**
     * Show all smilies.
     *
     * @return void
     */
    public function index()
    {
        // Build the query explicitly: in Cake 5 the paginator no longer applies
        // a `contain` setting, so contain SmileyCodes on the query directly
        // (the template builds a Collection from each smiley's smiley_codes).
        $query = $this->Smilies->find()
            ->contain(['SmileyCodes'])
            ->orderBy(['Smilies.sort' => 'ASC']);

        // limit high enough so that no paging should occur
        $this->set('smilies', $this->paginate($query, ['limit' => 1000, 'maxLimit' => 1000]));
    }

    /**
     * Add new smiley.
     *
     * @return \Cake\Http\Response|void
     */
    public function add()
    {
        $smiley = $this->Smilies->newEmptyEntity();
        if ($this->request->is('post')) {
            // Block primary-key mass-assignment from the admin form.
            $this->Smilies->patchEntity(
                $smiley,
                $this->request->getData(),
                ['accessibleFields' => ['id' => false]]
            );
            if ($this->Smilies->save($smiley)) {
                $this->Flash->set(
                    __('The smily has been saved'),
                    ['element' => 'success']
                );
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->set(
                    __('The smiley could not be saved. Please, try again.'),
                    ['element' => 'error']
                );
            }
        }
        $this->set(compact('smiley'));
    }

    /**
     * Edit smiley.
     *
     * @param string|null $id smiley-ID
     * @return \Cake\Http\Response|void
     */
    public function edit($id = null)
    {
        // `||`, as delete() has it. The `&&` made sense only while an id could
        // arrive in the POST body; since primary-key mass-assignment was blocked
        // an id-less POST fell through to get(null), and
        // InvalidPrimaryKeyException carries no HTTP code — so the admin got a
        // 500 instead of the "Invalid smiley." flash this branch exists for.
        if (empty($id)) {
            $this->Flash->set(__('Invalid smiley.'), ['element' => 'error']);
            return $this->redirect(['action' => 'index']);
        }

        $smiley = $this->Smilies->get($id);
        if (!empty($this->request->getData())) {
            // Block primary-key mass-assignment from the admin form.
            $this->Smilies->patchEntity(
                $smiley,
                $this->request->getData(),
                ['accessibleFields' => ['id' => false]]
            );
            if ($this->Smilies->save($smiley)) {
                $this->Flash->set(
                    __('The smily has been saved'),
                    ['element' => 'success']
                );
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->set(
                    __('The smiley could not be saved. Please, try again.'),
                    ['element' => 'error']
                );
            }
        }
        $this->set(compact('smiley'));
    }

    /**
     * Delete smiley.
     *
     * @param string|null $id Smiley-ID
     * @return \Cake\Http\Response|void
     */
    public function delete($id = null)
    {
        // Deleting travels by POST only: a GET is not covered by CSRF
        // protection, so an image tag on any page an admin opens could
        // fire it.
        $this->request->allowMethod(['post', 'delete']);

        if (empty($id) || !$this->Smilies->exists(['id' => $id])) {
            $this->Flash->set(__('Invalid smiley.'), ['element' => 'error']);
            return $this->redirect(['action' => 'index']);
        }
        $smiley = $this->Smilies->get($id);
        if ($this->Smilies->delete($smiley)) {
            $this->Flash->set(__('Smiley deleted.'), ['element' => 'success']);
            return $this->redirect(['action' => 'index']);
        }
        $this->Flash->set(__('Smily was not deleted.'), ['element' => 'error']);
        return $this->redirect(['action' => 'index']);
    }
}
