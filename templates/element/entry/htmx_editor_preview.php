<?php
/**
 * The editor's preview panel.
 *
 * Sits *above* the form it belongs to, which is where the forum has always put
 * it: you write at the bottom and watch the result appear above, rather than
 * hunting for it between the toolbar and the text box. Filled by the island
 * (see editor.ts) from EntriesController::htmxPreview().
 *
 * Hidden until there is something to show — an empty frame above every reply
 * box would cost every reader vertical space for nothing.
 *
 * @var \App\View\AppView $this
 */
?>
<div class="panel htmx-editor-previewPanel js-editorPreviewPanel" hidden>
    <div class="panel-heading htmx-editor-previewPanel-head">
        <?php // Closing it is a plain hide: the next press of "Preview" fills and
              // reopens it, so nothing is lost by getting it out of the way. ?>
        <button type="button" class="btn btn-link btn-sm js-editorPreviewClose"
                aria-label="<?= h(__('Close')) ?>" title="<?= h(__('Close')) ?>">&times;</button>
        <span class="htmx-editor-previewPanel-title"><?= h(__('Preview')) ?></span>
    </div>
    <div class="panel-body js-editor-preview"></div>
</div>
