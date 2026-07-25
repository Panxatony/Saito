<?php
/**
 * BBCode preview fragment for the htmx editor toolbar
 * (EntriesController::htmxPreview()). Swapped into the toolbar's preview slot.
 *
 * @var \App\View\AppView $this
 * @var string $previewText
 */

if (trim($previewText) === '') {
    return;
}
?>
<div class="postingBody htmx-editor-previewBox"
     style="border: 1px solid rgba(128,128,128,.35); border-radius: 3px; padding: .5em .75em; margin: .5em 0;">
    <?= $this->Parser->parse($previewText) ?>
</div>
