<?php
/**
 * Confirmation after an inline new-thread post (EntriesController::htmxAdd()).
 * The list refreshes via an HX-Trigger; this fills the editor slot.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Entry $posting
 */
?>
<div class="alert alert-success" style="margin: .5rem 0;">
    <i class="fa fa-check"></i>
    <?= h(__('Your reply has been saved.')) ?>
    <a href="<?= h($this->Url->build('/entries/htmx-thread/' . $posting->get('id'))) ?>">
        <?= h(__('forum_show_thread')) ?>
    </a>
</div>
