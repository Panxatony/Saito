<?php
/**
 * Delete guard (issue #64): shown in place of an upload's tile when a delete is
 * asked for one that is still embedded in a posting. Rendered by
 * EntriesController::htmxUploadDelete() on the first, unconfirmed request.
 *
 * "Delete anyway" re-posts with confirm=1 and the tile is removed; "Cancel"
 * reloads the archive (of the upload's owner, so an admin managing another
 * member's uploads gets the right grid back).
 *
 * @var \App\View\AppView $this
 * @var int $uploadId
 * @var int $usageCount
 * @var int $ownerId
 */

$webroot = $this->request->getAttribute('webroot');
?>
<div class="js-uploadManageItem upload-manage-item upload-delete-confirm">
    <p class="upload-delete-confirm-msg">
        <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
        <?= h(__n('Still used in {0} posting.', 'Still used in {0} postings.', $usageCount, $usageCount)) ?>
    </p>
    <div class="upload-delete-confirm-actions">
        <button type="button" class="btn btn-danger btn-sm"
                hx-post="<?= $webroot ?>entries/htmx-upload-delete/<?= (int)$uploadId ?>"
                hx-vals='{"confirm":"1"}'
                hx-target="closest .js-uploadManageItem" hx-swap="outerHTML">
            <?= h(__('upload.delete.anyway')) ?>
        </button>
        <button type="button" class="btn btn-link btn-sm"
                hx-get="<?= $webroot ?>entries/htmx-uploads?manage=1&amp;id=<?= (int)$ownerId ?>"
                hx-target="closest .js-uploadGrid" hx-swap="innerHTML">
            <?= h(__('Cancel')) ?>
        </button>
    </div>
</div>
