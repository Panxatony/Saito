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
use Cake\Log\Log;
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
     * Set a member's password for them.
     *
     * Saito had this until the SPA was retired: `UsersController::setpassword()`
     * went with the SPA profile page it hung off, and the permission it checked
     * stayed behind in `permissions.php`, declared and unused. What went with it
     * was, at the time, the only way back in for a member who had forgotten
     * their password.
     *
     * It is no longer the only way: 8.4.0 added a self-service reset (#63), and
     * a member who can still read their own e-mail should use that. This is
     * what remains for the cases it cannot reach — a stale address, a mailbox
     * nobody has access to any more, an account whose owner is standing next to
     * you.
     *
     * Two guards, both deliberate:
     *
     * The permission is the original one, `saito.core.user.password.set`,
     * scoped to the target's role — an admin may set a moderator's or a member's
     * password, and only the owner may set an admin's.
     *
     * And the acting admin re-enters their *own* password, the same as
     * {@see role()}. Setting someone else's password is account takeover with
     * the forum's blessing: it is the one act here that survives the session
     * that performed it, so it asks for something a hijacked browser does not
     * carry.
     *
     * Note what is *not* asked for: the member's current password. That is the
     * whole point — they do not have it. `patchEntity()` with an explicit
     * `fields` list keeps `password_old` out of the picture, while the
     * confirmation field still guards against a typo becoming a lockout.
     *
     * @param string $id user-ID
     * @return \Cake\Http\Response|null
     */
    public function password($id)
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($id);

        if (
            !$this->CurrentUser->permission(
                'saito.core.user.password.set',
                (new ResourceAI())->onRole($user->getRole())
            )
        ) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to set password for user %s.', $id),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if ($this->getRequest()->is(['put', 'post'])) {
            if (!$this->isCurrentPassword($this->getRequest()->getData('confirm_password'))) {
                $this->Flash->set(__('user.pw.set.confirm.error'), ['element' => 'error']);
                $this->set(compact('user'));

                return null;
            }

            $data = [
                'password' => $this->getRequest()->getData('password'),
                'password_confirm' => $this->getRequest()->getData('password_confirm'),
            ];
            // `fields` names only `password`: `password_confirm` still has to be
            // in the data for the validator to compare against, but it is not a
            // column and must not be assigned.
            $patched = $this->Users->patchEntity($user, $data, ['fields' => ['password']]);

            $errors = $patched->getErrors();
            if (empty($errors) && $this->Users->save($patched)) {
                $this->Flash->set(
                    __('user.pw.set.s', $user->get('username')),
                    ['element' => 'success']
                );

                return $this->redirect(['action' => 'index']);
            }

            $message = empty($errors)
                ? __('user.pw.set.error')
                : __d('nondynamic', (string)current(array_pop($errors)));
            $this->Flash->set($message, ['element' => 'error']);
        }

        $this->set(compact('user'));

        return null;
    }

    /**
     * Clear a member's second factor, for when they are locked out of their own
     * account: the phone is gone and the recovery codes with it.
     *
     * Gated on the same permission as setting somebody's password, and for the
     * same reason — an administrator who can do that can already take the
     * account over, so this adds no power they did not have. It asks for the
     * administrator's own password as well, because the effect is to weaken
     * another person's account and a borrowed admin session should not be able
     * to do it quietly.
     *
     * Logged either way. Removing somebody's second factor is exactly the step
     * an attacker who reached an admin session would take, so it has to leave a
     * trace that is readable after the fact.
     *
     * @param string|null $id member whose second factor to clear
     * @return \Cake\Http\Response|null
     */
    public function twoFactorReset($id = null)
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->Users->get($id);

        if (
            !$this->CurrentUser->permission(
                'saito.core.user.password.set',
                (new ResourceAI())->onRole($user->getRole())
            )
        ) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to reset the second factor for user %s.', $id),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        $this->set(compact('user'));
        $Credentials = $this->fetchTable('TwoFactorCredentials');
        $this->set('isEnabled', $Credentials->isEnabledFor((int)$user->get('id')));

        if (!$this->getRequest()->is(['put', 'post'])) {
            return null;
        }

        if (!$this->isCurrentPassword($this->getRequest()->getData('confirm_password'))) {
            // The *failed* attempt is the interesting one for anybody reading
            // this later: somebody held an admin session and did not know the
            // password that goes with it.
            Log::write(
                'warning',
                sprintf(
                    'Failed second-factor reset for user "%s" (id %d), attempted by "%s" (id %d): wrong password.',
                    $user->get('username'),
                    (int)$user->get('id'),
                    (string)$this->CurrentUser->get('username'),
                    (int)$this->CurrentUser->getId(),
                ),
                ['scope' => ['saito.info']],
            );
            $this->Flash->set(__('user.pw.set.confirm.error'), ['element' => 'error']);

            return null;
        }

        $Credentials->disableFor((int)$user->get('id'));
        $this->fetchTable('TwoFactorRecoveryCodes')->clearFor((int)$user->get('id'));
        // An administrator resets the second factor because the member lost
        // control of it. Devices trusted on the strength of that factor have to
        // go too, or the reset leaves the thing it was meant to undo in place —
        // and so do the passkeys, which are registrations of that same factor
        // and would otherwise still complete a second step.
        $this->fetchTable('TwoFactorTrustedDevices')->clearFor((int)$user->get('id'));
        $this->fetchTable('WebauthnCredentials')->clearFor((int)$user->get('id'));

        Log::write(
            'info',
            sprintf(
                'Second factor reset for user "%s" (id %d) by "%s" (id %d).',
                $user->get('username'),
                (int)$user->get('id'),
                (string)$this->CurrentUser->get('username'),
                (int)$this->CurrentUser->getId(),
            ),
            ['scope' => ['saito.info']],
        );

        $this->Flash->set(
            __('user.2fa.admin.reset.done', $user->get('username')),
            ['element' => 'success'],
        );

        return $this->redirect(['action' => 'index']);
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
