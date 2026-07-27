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

use App\Model\Entity\User;
use App\Model\Table\UsersTable;
use InvalidArgumentException;
use Saito\App\Registry;
use Saito\Exception\SaitoForbiddenException;
use Saito\User\Permission\Permissions;
use Saito\User\Permission\ResourceAI;

/**
 * @property UsersTable $Users
 */
class UsersController extends AdminAppController
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Users = $this->fetchTable('Users');
    }

    /**
     * List all users.
     *
     * @return void
     */
    public function index()
    {
        $data = $this->Users->find()
            ->select(
                [
                    'id',
                    'username',
                    'user_type',
                    'user_email',
                    'registered',
                    'user_lock',
                ]
            )
            ->orderBy(['username' => 'asc'])
            ->all();
        $this->set('users', $data);
    }

    /**
     * add user
     *
     * @return \Cake\Http\Response|void
     */
    public function add()
    {
        if (!$this->request->is('post') && empty($this->request->getData())) {
            $user = $this->Users->newEmptyEntity();
        } else {
            $user = $this->Users->register($this->request->getData(), true);
            if (!empty($user) && !$user->hasErrors()) {
                $this->Flash->set(__('user.admin.add.success'), ['element' => 'success']);

                return $this->redirect(['plugin' => false, 'action' => 'view', $user->get('id')]);
            } else {
                $this->Flash->set(__('user.admin.add.error'), ['element' => 'error']);
            }
        }
        $this->set('user', $user);
    }

    /**
     * List all blocked users.
     *
     * @return void
     */
    public function block()
    {
        $this->set('UserBlock', $this->Users->UserBlocks->getAll());
    }

    /**
     * View and set a user's role.
     *
     * Moved here from the forum's own UsersController when the SPA was retired.
     * It used to hang off the SPA profile page, which was the only way to reach
     * it — so on an island install the forum had no way to appoint a moderator
     * at all. The permission check is the original one, unchanged.
     *
     * @param string $id user-ID
     * @return \Cake\Http\Response|null
     */
    public function role($id)
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($id);
        $identifier = (new ResourceAI())->onRole($user->getRole());
        $unrestricted = $this->CurrentUser->permission('saito.core.user.role.set.unrestricted', $identifier);
        $restricted = $this->CurrentUser->permission('saito.core.user.role.set.restricted', $identifier);
        if (!$restricted && !$unrestricted) {
            throw new SaitoForbiddenException(null, ['CurrentUser' => $this->CurrentUser]);
        }

        /** @var \Saito\User\Permission\Permissions $Permissions */
        $Permissions = Registry::get('Permissions');
        $roles = $Permissions->getRoles()->get($this->CurrentUser->getRole(), false, $unrestricted);

        if ($this->getRequest()->is(['put', 'post'])) {
            $type = $this->getRequest()->getData('user_type');
            if (!in_array($type, $roles)) {
                throw new InvalidArgumentException(
                    sprintf('User type "%s" is not available.', $type),
                    1573376871
                );
            }
            // `user_type` is guarded against mass-assignment on the User entity
            // (see App\Model\Entity\User). This is the one authorized path to
            // change a role — the permission check above gates it and $type is
            // validated against the roles the current user may assign — so
            // explicitly allow the field here.
            $patched = $this->Users->patchEntity(
                $user,
                ['user_type' => $type],
                ['accessibleFields' => ['user_type' => true]],
            );

            $errors = $patched->getErrors();
            if (empty($errors)) {
                $this->Users->save($patched);
                $this->Flash->set(__('user.role.set.t', $user->get('username')), ['element' => 'success']);

                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->set(current(current($errors)), ['element' => 'error']);
        }

        $this->set(compact('roles', 'user'));

        return null;
    }

    /**
     * Delete a user account, keeping their postings.
     *
     * Moved here with `role()` for the same reason. Note that the permission
     * `saito.core.user.delete` also grants this to moderators, but the
     * administration backend itself requires `saito.core.admin.backend`, which
     * is admin-only — so in practice this is now an admin action. The check
     * below is left as it was rather than narrowed, so the restriction lives in
     * exactly one place: who may enter the backend.
     *
     * @param string $id user-ID
     * @return \Cake\Http\Response|null
     */
    public function delete($id)
    {
        $id = (int)$id;
        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($id);

        $permission = $this->CurrentUser->permission(
            'saito.core.user.delete',
            (new ResourceAI())->onRole($user->getRole())
        );
        if (!$permission) {
            throw new SaitoForbiddenException(
                'Not allowed to delete a user.',
                ['CurrentUser' => $this->CurrentUser, 'user_id' => $user->get('username')]
            );
        }

        $this->set('user', $user);

        if (!$this->getRequest()->is('post')) {
            return null;
        }

        if (!$this->getRequest()->getData('userdeleteconfirm')) {
            $this->Flash->set(__('user.del.fail.3'), ['element' => 'error']);

            return null;
        }

        if ($this->CurrentUser->isUser($user)) {
            $this->Flash->set(__('user.del.fail.1'), ['element' => 'error']);

            return null;
        }

        $username = $user->get('username');
        if (empty($this->Users->deleteAllExceptEntries($id))) {
            $this->Flash->set(__('user.del.fail.2'), ['element' => 'error']);

            return null;
        }

        $this->Flash->set(__('user.del.ok.m', $username), ['element' => 'success']);

        return $this->redirect(['action' => 'index']);
    }
}
