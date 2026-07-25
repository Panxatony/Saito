<?php
/**
 * Confirmation fragment after a successful inline reply
 * (EntriesController::htmxReply()). Swapped into the reply slot.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Entry $posting
 */
?>
<div class="alert alert-success" style="margin-top: 0.75em;">
    <i class="fa fa-check"></i>
    <?= h(__('Your reply has been saved.')) ?>
    <a href="<?= h($this->Url->build('/entries/view/' . $posting->get('id'))) ?>">
        <?= h(__('forum_show_thread')) ?>
    </a>
</div>
