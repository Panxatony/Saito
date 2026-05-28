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

use App\Model\Table\SmileyCodesTable;
use Cake\ORM\Entity;

/**
 * @property SmileyCodesTable $SmileyCodes
 */
class SmileyCodesController extends AdminAppController
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->SmileyCodes = $this->fetchTable('SmileyCodes');
    }

    /**
     * List smiley-codes.
     *
     * @return void
     */
    public function index()
    {
        // Cake 5's paginator no longer applies a `contain` setting; contain
        // Smilies on the query directly (the template reads each code's
        // associated smiley via $smileyCode->get('smiley')).
        $query = $this->SmileyCodes->find()->contain(['Smilies']);
        $this->set('smileyCodes', $this->paginate($query, ['limit' => 1000, 'maxLimit' => 1000]));
    }

    /**
     * Add smiley-code.
     *
     * @return void
     */
    public function add()
    {
        $smiley = $this->SmileyCodes->newEmptyEntity();
        $this->_addEditCommon($smiley);
    }

    /**
     * Edit smiley-code.
     *
     * @param string $id smiley-code-ID
     * @return void
     */
    public function edit($id)
    {
        if (!$id && empty($this->request->getData())) {
            $this->Flash->set(
                __('Invalid smiley code'),
                ['element' => 'error']
            );
            $this->redirect(['action' => 'index']);

            return;
        }
        $smiley = $this->SmileyCodes->get($id);
        $this->_addEditCommon($smiley);
    }

    /**
     * Code shared between add and edit.
     *
     * @param Entity $smiley smiley
     * @return void
     */
    protected function _addEditCommon(Entity $smiley)
    {
        if (!empty($this->request->getData())) {
            $smiley = $this->SmileyCodes->patchEntity(
                $smiley,
                $this->request->getData()
            );
            if ($this->SmileyCodes->save($smiley)) {
                $this->Flash->set(
                    __('The smiley code has been saved'),
                    ['element' => 'success']
                );
                $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->set(
                    __('The smiley code could not be saved. Please, try again.'),
                    ['element' => 'error']
                );
            }
        }

        $smilies = $this->SmileyCodes->Smilies
            ->find(
                'list',
                keyField: 'id',
                valueField: 'icon',
            )
            ->toArray();
        $this->set(compact('smiley', 'smilies'));
    }

    /**
     * Delete smiley-code.
     *
     * @param string $id smiley-code-ID
     * @return void
     */
    public function delete($id)
    {
        if (!$id) {
            $this->Flash->set(
                __('Invalid id for smiley code'),
                ['element' => 'error']
            );
            $this->redirect(['action' => 'index']);

            return;
        }
        $smiley = $this->SmileyCodes->get($id);
        if ($this->SmileyCodes->delete($smiley)) {
            $this->Flash->set(
                __('Smiley code deleted'),
                ['element' => 'error']
            );
            $this->redirect(['action' => 'index']);

            return;
        }
        $this->Flash->set(
            __('Smiley code was not deleted'),
            ['element' => 'error']
        );
        $this->redirect(['action' => 'index']);
    }
}
