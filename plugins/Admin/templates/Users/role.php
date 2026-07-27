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
    <div class="form-group">
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
    <div class="form-group">
        <?= $this->Form->submit(__('user.role.set.btn'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Html->link(__d('admin', 'cancel'), ['action' => 'index'], ['class' => 'btn btn-link']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
