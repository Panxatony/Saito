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
// What was typed survives a rejected submission. The parent's subject is *not*
// filled in as a value but offered as a placeholder — see the field below.
$subject = $submitted['subject'] ?? '';
$text = $submitted['text'] ?? '';

// A saved draft fills the empty form. Only when nothing was submitted: a rejected
// submission is newer than a draft stored seconds earlier, and overwriting it
// would throw away what the writer had just typed. The controller only looks the
// draft up on a GET for the same reason.
$restored = false;
if (!isset($submitted) && !empty($draft)) {
    $subject = $draft['subject'];
    $text = $draft['text'];
    $restored = true;
}
?>
<?= $this->element('entry/htmx_editor_preview') ?>
<?php // Datenattribute für die Vorschau: eine Antwort erbt die Kategorie des
      // Elternbeitrags, und ein neuer Beitrag hat naturgemäß null Aufrufe. ?>
<form class="htmx-reply-form" style="margin-top: 0.75em;"
      data-preview-category="<?= (int)($parentCategoryId ?? 0) ?>" data-preview-views="0"
      hx-post="<?= h($actionUrl) ?>"
      hx-target="closest .js-replySlot"
      hx-swap="innerHTML">
    <?php if (!empty($errors)) : ?>
        <div class="alert alert-error"><?= h(__('Please check your entry.')) ?></div>
    <?php endif; ?>
    <?php if ($restored) : ?>
        <?php // Say why there is text in a box the writer just opened. Without
              // this the draft looks like the forum inventing content. ?>
        <p class="draft-restored exp">
            <?= h(__('draft.restored')) ?>
            <button type="button" class="btn btn-sm btn-link js-draftDiscard">
                <?= h(__('draft.discard')) ?>
            </button>
        </p>
    <?php endif; ?>
    <div class="form-group">
        <?php $subjectMax = (int)(\Cake\Core\Configure::read('Saito.Settings.subject_maxlength') ?: 100); ?>
        <?php
        // The parent's subject is a placeholder, not a value: it shows what will
        // be used, in pale text, and gets out of the way the moment anything is
        // typed. Filling it in as a value would mean every reply starts by
        // deleting a line nobody asked for.
        //
        // Leaving it empty is the normal case, so the server puts the same
        // subject in — EntriesController::htmxReply() uses replySubject() for
        // exactly the text shown here. The two must not drift apart, or the
        // field would promise one thing and the posting carry another.
        $subjectPlaceholder = ($replySubject ?? '') !== '' ? $replySubject : __('subject');
        ?>
        <input type="text" name="subject" class="form-control js-subject" maxlength="<?= $subjectMax ?>"
               style="--subject-max: <?= $subjectMax ?>"
               placeholder="<?= h($subjectPlaceholder) ?>" value="<?= h($subject) ?>"
               autofocus>
    </div>
    <?php // An answer can be marked too — it is its own posting. Nothing is
          // inherited from the thread it hangs under. ?>
    <?= $this->element('entry/htmx_editor_toolbar', [
        'nsfwValue' => (bool)($submitted['nsfw'] ?? false),
    ]) ?>
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
