<?php
/**
 * Inline reply form for the htmx island (EntriesController::htmxReply()).
 *
 * A minimal subject/text form. Submits via htmx to the same action; the island
 * attaches the CSRF header. The rich editor (BBCode toolbar, upload, preview)
 * is out of scope for this slice.
 *
 * @var \App\View\AppView $this
 * @var int $parentId
 * @var bool $forbidden
 * @var array $errors
 * @var array $submitted
 */

if (!empty($forbidden)) {
    echo '<div class="alert alert-warning">' . h(__('Answering is not allowed here.')) . '</div>';

    return;
}

$actionUrl = $this->Url->build(
    ['controller' => 'Entries', 'action' => 'htmxReply', $parentId],
    ['escape' => false]
);
$subject = $submitted['subject'] ?? '';
$text = $submitted['text'] ?? '';
?>
<form class="htmx-reply-form" style="margin-top: 0.75em;"
      hx-post="<?= h($actionUrl) ?>"
      hx-target="closest .js-replySlot"
      hx-swap="innerHTML">
    <?php if (!empty($errors)) : ?>
        <div class="alert alert-error"><?= h(__('Please check your entry.')) ?></div>
    <?php endif; ?>
    <div class="form-group">
        <input type="text" name="subject" class="form-control"
               placeholder="<?= h(__('subject')) ?>" value="<?= h($subject) ?>">
    </div>
    <?= $this->element('entry/htmx_editor_toolbar') ?>
    <div class="form-group">
        <textarea name="text" class="form-control" rows="4" required
                  placeholder="<?= h(__('text')) ?>"><?= h($text) ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">
        <?= h(__('forum_answer_linkname')) ?>
    </button>
</form>
