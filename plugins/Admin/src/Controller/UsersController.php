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
            // Handing out a role is the one admin action that makes another
            // account permanent — everything else can be undone by an admin who
            // still has access. It is therefore the last link in the chain that
            // turns a script running in an admin's browser into an attacker's
            // own admin account: the session alone used to be enough, and a
            // stolen session is exactly what an XSS gives away. Asking for the
            // password means the attacker needs something the browser does not
            // hold.
            if (!$this->isCurrentPassword($this->getRequest()->getData('confirm_password'))) {
                $this->Flash->set(__('user.role.set.confirm.error'), ['element' => 'error']);
                $this->set(compact('roles', 'user'));

                return null;
            }

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
     * Moved here with `role()` for the same reason. Admins and the owner only —
     * `saito.core.user.delete` says so, rather than the restriction being an
     * accident of who may open the backend. Moderators have every other tool
     * for handling a member; this one has no lesser version to try first.
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

    /**
     * Whether the given plain-text password is the acting admin's own.
     *
     * @param mixed $password what the confirmation field carried
     * @return bool true only on a match
     */
    private function isCurrentPassword($password): bool
    {
        if (!is_string($password) || $password === '') {
            return false;
        }

        $hash = $this->Users
            ->get($this->CurrentUser->getId(), fields: ['password'])
            ->get('password');

        return $this->Users->getPasswordHasher()->check($password, (string)$hash);
    }
}
