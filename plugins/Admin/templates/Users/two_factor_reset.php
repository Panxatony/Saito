<?php
/**
 * Clear a member's second factor when they are locked out of their own account.
 *
 * The member's own credentials are not asked for — they are here because they
 * have neither their device nor their recovery codes. The acting admin's
 * password is, because the effect is to weaken somebody else's account.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var bool $isEnabled
 */

$this->Breadcrumbs->add(__('Users'), ['action' => 'index']);
$this->Breadcrumbs->add(h($user->get('username')), false);
?>
<div class="users twoFactorReset">
    <h1><?= h(__('user.2fa.admin.reset.t', $user->get('username'))) ?></h1>

    <?php if (!$isEnabled) : ?>
        <p class="text-muted"><?= h(__('user.2fa.admin.reset.none')) ?></p>
        <?= $this->Html->link(__d('admin', 'cancel'), ['action' => 'index'], ['class' => 'btn btn-link']) ?>
    <?php else : ?>
        <p class="text-muted"><?= h(__('user.2fa.admin.reset.exp')) ?></p>

        <?= $this->Form->create(null, ['url' => ['action' => 'twoFactorReset', $user->get('id')]]) ?>
        <div class="form-group">
            <?php // Your own password, not the account being changed. ?>
            <?= $this->Form->control('confirm_password', [
                'autocomplete' => 'off',
                'label' => __('user.role.set.confirm.label'),
                'required' => true,
                'type' => 'password',
                'value' => '',
            ]) ?>
            <small class="text-muted"><?= h(__('user.pw.set.confirm.exp')) ?></small>
        </div>
        <div class="form-group">
            <?= $this->Form->submit(__('user.2fa.admin.reset.btn'), ['class' => 'btn btn-danger']) ?>
            <?= $this->Html->link(__d('admin', 'cancel'), ['action' => 'index'], ['class' => 'btn btn-link']) ?>
        </div>
        <?= $this->Form->end() ?>
    <?php endif; ?>
</div>
