<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller;

use Api\Controller\ApiAppController;
use App\Controller\Component\PostingComponent;
use App\Model\Entity\Entry;
use App\Model\Table\EntriesTable;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Saito\Exception\SaitoForbiddenException;
use Saito\Posting\PostingInterface;

/**
 * Endpoint for adding/POST and editing/PUT posting
 *
 * @property EntriesTable $Entries
 * @property PostingComponent $Posting
 */
class PostingsController extends ApiAppController
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Entries = $this->fetchTable('Entries');
        $this->loadComponent('Posting');
    }

    /**
     * Add a a new posting
     *
     * @return void
     */
    public function add(): void
    {
        $data = $this->getRequest()->getData();
        // `edited` / `edited_by` must never be client-supplied (a new posting is
        // not edited); leaving them in let a user forge the edit attribution and
        // timestamp — same hardening as edit() below.
        $allowedFields = ['category_id', 'pid', 'subject', 'text'];
        $data = array_intersect_key($data, array_fill_keys($allowedFields, 1));

        $data += [
            'name' => $this->CurrentUser->get('username'),
            'user_id' => $this->CurrentUser->getId(),
        ];

        /** @var Entry */
        $posting = $this->Posting->create($data, $this->CurrentUser);

        if (empty($posting)) {
            throw new BadRequestException();
        }

        $errors = $posting->getErrors();

        if (!count($errors)) {
            $this->set(compact('posting'));

            return;
        }

        $this->set(compact('errors'));
        $this->viewBuilder()->setTemplate('/Error/json/entityValidation');
    }

    /**
     * Edit an existing posting
     *
     * @param string $id Unused in favor of request-data.
     * @return void
     */
    public function edit(string $id): void
    {
        $id = $this->getRequest()->getData('id', null);

        if (empty($id)) {
            throw new BadRequestException('No posting-id provided.');
        }

        $posting = $this->Entries->get($id);
        if (!$posting) {
            throw new NotFoundException('Posting not found.');
        }

        $data = $this->getRequest()->getData();
        // `edited` and `edited_by` are server-set below — they must NOT be
        // client-supplied. Leaving them in the whitelist let a user pass their
        // own values (the `+=` union does not overwrite existing keys), which
        // enabled a stored-XSS payload in edited_by and a forged edit time.
        $allowedFields = ['category_id', 'subject', 'text'];
        $data = array_intersect_key($data, array_fill_keys($allowedFields, 1));

        $data += [
            'edited' => bDate(),
            'edited_by' => $this->CurrentUser->get('username'),
        ];

        $updatedPosting = $this->Posting->update($posting, $data, $this->CurrentUser);

        if (!$updatedPosting) {
            throw new BadRequestException('Posting could not be saved.');
        }

        if (!$updatedPosting->hasErrors()) {
            $this->set('posting', $updatedPosting);
            $this->render('/Postings/json/add');

            return;
        }

        $errors = $updatedPosting->getErrors();
        $this->set(compact('errors'));
        $this->viewBuilder()->setTemplate('/Error/json/entityValidation');
    }

    /**
     * Delete a posting (a thread-root deletes its whole subtree).
     *
     * DELETE /api/v2/postings/<id> — JWT-authenticated, so it needs no CSRF
     * token and is not FormProtection-gated.
     *
     * @param string $id posting-id
     * @return \Cake\Http\Response
     */
    public function delete(string $id)
    {
        $id = (int)$id;
        if (!$id) {
            throw new BadRequestException('No posting-id provided.');
        }
        /** @var Entry $posting */
        $posting = $this->Entries->get($id);
        if (!$posting) {
            throw new NotFoundException('Posting not found.');
        }

        // Same two-layer authorization as the server-side EntriesController
        // (beforeFilter authorizeAction + in-action category check): the general
        // posting-delete permission (moderator/admin), then the per-category
        // thread/answer permission. Without the first layer any JWT user could
        // delete via the API.
        if (!$this->CurrentUser->permission('saito.core.posting.delete')) {
            throw new SaitoForbiddenException();
        }
        $action = $posting->isRoot() ? 'thread' : 'answer';
        if (!$this->CurrentUser->getCategories()->permission($action, $posting->get('category_id'))) {
            throw new SaitoForbiddenException();
        }

        if (!$this->Entries->deletePosting($id)) {
            throw new BadRequestException('Posting could not be deleted.');
        }

        return $this->getResponse()->withStatus(204);
    }

    /**
     * Serves meta information required to add or edit a posting
     *
     * @param string|null $id ID of the posting (send on edit)
     * @return void
     */
    public function meta(?string $id = null): void
    {
        $id = (int)$id;
        $isEdit = !empty($id);
        $pid = $this->getRequest()->getQuery('pid', null);
        $isAnswer = !empty($pid);

        if ($isAnswer) {
            /** @var PostingInterface */
            $parent = $this->Entries->get($pid)->toPosting()->withCurrentUser($this->CurrentUser);

            // Don't leak content of forbidden categories
            if ($parent->isAnsweringForbidden()) {
                throw new SaitoForbiddenException(
                    'Access to parent in PostingsController:meta() forbidden.',
                    ['CurrentUser' => $this->CurrentUser]
                );
            }

            $this->set('parent', $parent);
        }

        if ($isEdit) {
            /** @var PostingInterface */
            $posting = $this->Entries->get($id)->toPosting()->withCurrentUser($this->CurrentUser);
            if (!$posting->isEditingAllowed()) {
                throw new SaitoForbiddenException(
                    'Access to posting in PostingsController:meta() forbidden.',
                    ['CurrentUser' => $this->CurrentUser]
                );
            }
            $this->set('posting', $posting);
        } else {
            /// We currently don't save drafts for edits
            $where = ['user_id' => $this->CurrentUser->getId()];
            if (is_numeric($pid)) {
                $where['pid'] = $pid;
            }
            $draft = $this->Entries->Drafts->find()->where($where)->first();

            if ($draft) {
                $this->set('draft', $draft);
            }
        }

        $settings = Configure::read('Saito.Settings');

        $this->set(compact('isAnswer', 'isEdit', 'settings'));

        $action = $isAnswer ? 'answer' : 'thread';
        $categories = $this->CurrentUser->getCategories()->getAll($action, 'list');
        $this->set('categories', $categories);
    }
}
