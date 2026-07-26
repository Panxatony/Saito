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
        <?php // Bewusst ohne `required`: ein Beitrag ohne Text ist gewollt. Saito
              // kennt das seit jeher als "n/t" (no text) — isNt() ist schlicht
              // ein leerer Text, und PostingHelper haengt beim Rendern " n/t"
              // an den Betreff. Nur dieses Formular verlangte Text und
              // verhinderte damit eine Funktion, die es laengst gibt. ?>
        <textarea name="text" class="form-control" rows="4"
                  placeholder="<?= h(__('entry.text.ph.nt')) ?>"><?= h($text) ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">
        <?= h(__('forum_answer_linkname')) ?>
    </button>
</form>
