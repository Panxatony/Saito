<?php
/**
 * One page of an upload archive (thumbnail tiles), plus a "load more" control.
 * Rendered by EntriesController::htmxUploads() — the current user's own uploads,
 * or another member's when an admin asked for them by id.
 *
 * Two modes:
 *  - editor overlay (default): bare tiles that insert their BBCode on click.
 *  - profile manage (`$manage`): each tile is wrapped with a delete control
 *    that htmx-removes the upload (EntriesController::htmxUploadDelete).
 *
 * @var \App\View\AppView $this
 * @var iterable $uploads
 * @var int $page
 * @var bool $hasMore
 * @var bool $manage
 */

$this->loadHelper('ImageUploader.ImageUploader');
$webroot = $this->request->getAttribute('webroot');
$manage = !empty($manage);

foreach ($uploads as $u) :
    $attr = $this->ImageUploader->image($u)['attributes'];
    $isImage = strpos((string)$attr['mime'], 'image/') === 0;
    $ext = strtoupper(ltrim((string)strrchr((string)$attr['name'], '.'), '.'));

    // The tile itself — same markup in both modes.
    ob_start();
    ?>
    <button type="button" class="js-uploadTile"
            data-upload-id="<?= (int)$u->get('id') ?>"
            data-name="<?= h($attr['name']) ?>" data-mime="<?= h($attr['mime']) ?>"
            title="<?= h($attr['title'] ?: $attr['name']) ?>">
        <?php if ($isImage) : ?>
            <img src="<?= h($attr['thumbnail_url']) ?>" alt="<?= h($attr['name']) ?>" loading="lazy">
        <?php else : ?>
            <span class="upload-tile-file"><?= h($ext !== '' ? $ext : 'FILE') ?></span>
        <?php endif; ?>
    </button>
    <?php
    $tile = ob_get_clean();

    if ($manage) :
        ?>
        <div class="js-uploadManageItem upload-manage-item">
            <?= $tile ?>
            <button type="button" class="upload-tile-del"
                    hx-post="<?= $webroot ?>entries/htmx-upload-delete/<?= (int)$u->get('id') ?>"
                    hx-target="closest .js-uploadManageItem" hx-swap="outerHTML"
                    hx-confirm="<?= h(__('Delete this upload?')) ?>"
                    title="<?= h(__('Delete')) ?>">
                <i class="fa fa-trash-o" aria-hidden="true"></i>
            </button>
        </div>
        <?php
    else :
        echo $tile;
    endif;
endforeach;

if ($hasMore) :
    ?>
    <button type="button" class="btn btn-link js-uploadsMore"
            hx-get="<?= $webroot ?>entries/htmx-uploads?page=<?= (int)$page + 1 ?><?= $manage ? '&manage=1' : '' ?><?= !empty($ownerId) ? '&id=' . (int)$ownerId : '' ?>"
            hx-target="this" hx-swap="outerHTML">
        <?= h(__('Load more')) ?>
    </button>
<?php endif; ?>
