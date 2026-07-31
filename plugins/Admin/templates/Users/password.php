<?php
/**
 * Set a member's password for them.
 *
 * The member's own current password is not asked for — they are here because
 * they do not have it. The acting admin's is, because this is the one act in
 * the backend that outlives the session performing it.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */

$this->Breadcrumbs->add(__('Users'), ['action' => 'index']);
$this->Breadcrumbs->add(h($user->get('username')), false);
?>
<div class="users password">
    <h1><?= h(__('user.pw.set.t', $user->get('username'))) ?></h1>

    <p class="text-muted"><?= h(__('user.pw.set.exp')) ?></p>

    <?= $this->Form->create(null, ['url' => ['action' => 'password', $user->get('id')]]) ?>
    <div class="form-group">
        <?= $this->Form->control('password', [
            'autocomplete' => 'new-password',
            'label' => __('user.pw.set.new.label'),
            'required' => true,
            'type' => 'password',
            'value' => '',
        ]) ?>
    </div>
    <div class="form-group">
        <?= $this->Form->control('password_confirm', [
            'autocomplete' => 'new-password',
            'label' => __('user.pw.set.repeat.label'),
            'required' => true,
            'type' => 'password',
            'value' => '',
        ]) ?>
    </div>
    <div class="form-group">
        <?php
        // Your own password, not the account being changed — see the action.
        echo $this->Form->control('confirm_password', [
            'autocomplete' => 'off',
            'label' => __('user.role.set.confirm.label'),
            'required' => true,
            'type' => 'password',
            'value' => '',
        ]);
        ?>
        <small class="text-muted"><?= h(__('user.pw.set.confirm.exp')) ?></small>
    </div>
    <div class="form-group">
        <?= $this->Form->submit(__('user.pw.set.btn'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Html->link(__d('admin', 'cancel'), ['action' => 'index'], ['class' => 'btn btn-link']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
