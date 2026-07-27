<?php
use Cake\Core\Configure;

$this->Breadcrumbs->add(__('Users'), false);
?>
<div class="users index">
    <h1><?= __('Users') ?></h1>
    <?=
    $this->Html->link(
        __('New User'),
        ['action' => 'add'],
        ['class' => 'btn btn-primary']
    )
?>
    <hr/>
    <table id="usertable" class="table table-striped">
        <thead>
        <?php
        $tableHeaders = [
            __('username_marking'),
            __('user_type'),
            __('user_email'),
            __('registered'),
            __('user.set.lock.t'),
            '',
        ];
        echo $this->Html->tableHeaders($tableHeaders);
        ?>
        </thead>
        <tbody>
        <?php
        foreach ($users as $user) {
            $tableCells = [
                '<strong>' . $this->Html->link($user->get('username'), "/users/view/{$user->get('id')}") . '</strong>',
                $this->Permissions->roleAsString($user->getRole()),
                $this->Html->link(
                    $user->get('user_email'),
                    'mailto:' . $user->get('user_email')
                ),
                // output date format sortable by datatable JS plugin
                $this->TimeH->formatTime(
                    $user->get('registered'),
                    'Y-m-d H:i',
                    ['wrap' => false]
                ),
                // without the &nbsp; the JS-sorting with the datatables plugin doesn't work
                $this->User->banned($user->get('user_lock')) . '&nbsp;',
                // Role and deletion used to hang off the forum's own profile
                // page, which the island frontend replaced — leaving no way to
                // appoint a moderator or remove an account. They live here now.
                $this->Html->link(
                    __('user.role.set.btn'),
                    ['action' => 'role', $user->get('id')],
                    ['class' => 'btn btn-sm btn-outline-secondary']
                ) . ' ' . $this->Html->link(
                    __('user.del.btn.t'),
                    ['action' => 'delete', $user->get('id')],
                    ['class' => 'btn btn-sm btn-outline-danger']
                ),
            ];
            echo $this->Html->tableCells(
                [$tableCells],
                ['class' => 'a'],
                ['class' => 'b']
            );
        }
        ?>
        </tbody>
    </table>
</div>
<?php $this->Admin->jqueryTable('#usertable', "[[3, 'desc'], [0, 'asc']]"); ?>
