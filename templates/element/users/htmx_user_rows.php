<?php
/**
 * Member-list table rows — shared by the shell (htmx_users.php) and the htmx
 * sort/refresh fragment (htmx_users_rows.php). Mirrors the columns of the SPA
 * member list (Users/index.php).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User[] $users
 */

foreach ($users as $user) : ?>
    <tr>
        <td>
            <?= $this->Html->link($user->get('username'), '/users/view/' . $user->get('id')) ?>
        </td>
        <td>
            <?php
            $info = [
                $this->Permissions->roleAsString($user->getRole()),
                __('user_since {0}', $this->TimeH->formatTime($user->get('registered'), 'd.m.Y')),
            ];
            if ($user->get('user_online') && $user->get('user_online')['logged_in']) {
                $info[] = __('Online');
            }
            if (!$user->isActivated() && $CurrentUser->permission('saito.core.user.activate.view')) {
                $info[] = h(__('user.actv.ny'));
            }
            if ($user->isLocked()) {
                $info[] = __('{0} banned', $this->User->banned(true));
            }
            echo $this->Html->nestedList($info);
            ?>
        </td>
    </tr>
<?php endforeach; ?>
