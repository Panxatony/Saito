<?php
/**
 * Set a user's role.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array<string> $roles roles the current user is allowed to assign
 */

$this->Breadcrumbs->add(__('Users'), ['action' => 'index']);
$this->Breadcrumbs->add(h($user->get('username')), false);
?>
<div class="users role">
    <h1><?= h(__('user.role.set.t', $user->get('username'))) ?></h1>

    <?= $this->Form->create($user, ['url' => ['action' => 'role', $user->get('id')]]) ?>
    <div class="mb-3">
        <?= $this->Form->control(
            'user_type',
            [
                'label' => false,
                'options' => array_map(
                    fn(string $role): array => [
                        'text' => $this->Permissions->roleAsString($role),
                        'value' => $role,
                    ],
                    $roles
                ),
                'required' => true,
                'type' => 'radio',
            ]
        ) ?>
    </div>
    <div class="mb-3">
        <?php
        // Your own password, not the account being changed. A role lasts beyond
        // the session that granted it, so this asks for something a hijacked
        // browser does not carry.
        echo $this->Form->control(
            'confirm_password',
            [
                'autocomplete' => 'off',
                'label' => __('user.role.set.confirm.label'),
                'required' => true,
                'type' => 'password',
                'value' => '',
            ]
        );
        ?>
        <small class="text-muted"><?= h(__('user.role.set.confirm.exp')) ?></small>
    </div>
    <div class="mb-3">
        <?= $this->Form->submit(__('user.role.set.btn'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Html->link(__d('admin', 'cancel'), ['action' => 'index'], ['class' => 'btn btn-link']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
