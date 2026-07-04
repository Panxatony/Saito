<?php
/**
 * Delete confirmation page.
 *
 * The actual deletion is a CSRF/FormProtection-protected POST (see
 * EntriesController::delete). This GET-rendered confirmation form is what turns
 * a lured cross-site delete link into a harmless no-op.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Entry $posting
 */
$isRoot = $posting->isRoot();
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card mt-3">
            <div class="card-body">
                <h1 class="h4 card-title"><?= __('delete_confirm.title') ?></h1>
                <p><?= $isRoot
                    ? __('delete_confirm.warning_thread')
                    : __('delete_confirm.warning_answer') ?></p>
                <p class="text-muted"><strong><?= h($posting->get('subject')) ?></strong></p>
                <?= $this->Form->create(null, ['url' => ['action' => 'delete', $posting->get('id')]]) ?>
                <?= $this->Form->button(
                    __('delete_confirm.confirm'),
                    ['type' => 'submit', 'class' => 'btn btn-danger']
                ) ?>
                <?= $this->Html->link(
                    __('delete_confirm.cancel'),
                    ['controller' => 'Entries', 'action' => 'view', $posting->get('tid')],
                    ['class' => 'btn btn-secondary']
                ) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
