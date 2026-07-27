<?php
/**
 * Delete a user account.
 *
 * Deliberately a plain page with an explicit confirmation rather than a modal:
 * this removes an account for good, and the three consequences are worth
 * reading before clicking. The wording is the one the retired profile page
 * used — same keys, so nothing needs re-translating.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */

$this->Breadcrumbs->add(__('Users'), ['action' => 'index']);
$this->Breadcrumbs->add(h($user->get('username')), false);
?>
<div class="users delete">
    <h1><?= __('user.del.exp.1', h($user->get('username'))) ?></h1>

    <div class="alert alert-danger">
        <ul class="mb-0">
            <li><?= __('user.del.exp.2') ?></li>
            <li><?= __('user.del.exp.3') ?></li>
            <li><?= __('user.del.exp.4') ?></li>
        </ul>
    </div>

    <?= $this->Form->create(null, ['url' => ['action' => 'delete', $user->get('id')]]) ?>
    <div class="form-group form-check">
        <?= $this->Form->control(
            'userdeleteconfirm',
            [
                'class' => 'form-input mr-1',
                'label' => __('user.del.confirm'),
                'required' => true,
                'type' => 'checkbox',
            ]
        ) ?>
    </div>
    <div class="form-group">
        <?= $this->Form->submit(__('user.del.btn.t'), ['class' => 'btn btn-danger']) ?>
        <?= $this->Html->link(__d('admin', 'cancel'), ['action' => 'index'], ['class' => 'btn btn-link']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
