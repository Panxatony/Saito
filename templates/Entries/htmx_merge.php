<?php
/**
 * Merge-thread standalone island page (/entries/htmx-merge/<sourceId>).
 *
 * Moderators enter the target posting id; htmxMerge moves the source thread
 * onto that target and redirects to the (now merged) thread. Native form
 * (FormHelper CSRF token). Authorized in the controller via
 * `saito.core.posting.merge`.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Entry $posting
 * @var bool $mergeError
 */

$id = (int)$posting->get('id');
$backUrl = $this->request->getAttribute('webroot') . 'entries/htmx-thread/' . $id;
?>
<div class="entry merge">
    <p class="mix-back" style="margin: 0 0 .75rem;">
        <a href="<?= h($backUrl) ?>" class="btn btn-link" rel="nofollow">
            <?= $this->Layout->textWithIcon(h(__('Back')), 'arrow-left') ?>
        </a>
    </p>
    <div class="panel panel-form panel-center">
        <?= $this->Layout->panelHeading(__('Merge thread {0}', $id)) ?>
        <div class="panel-content">
            <?php if (!empty($mergeError)) : ?>
                <div class="alert alert-error"><?= h(__('Error')) ?></div>
            <?php endif; ?>
            <p class="text-muted" style="margin-bottom: .75rem;">
                <?= h(__('The whole thread will be moved so it becomes an answer to the target posting.')) ?>
            </p>
            <?php
            echo $this->Form->create(null, ['url' => ['action' => 'htmxMerge', $id], 'type' => 'post']);
            echo $this->Form->control('targetId', [
                'class' => 'form-control', 'type' => 'number', 'min' => 1,
                'label' => __('Merge onto posting with ID:'),
            ]);
            echo $this->Form->button(__('Submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo $this->Form->end();
            ?>
        </div>
    </div>
</div>
