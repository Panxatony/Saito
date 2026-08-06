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
 * @var int $total how many the archive holds altogether
 */

$this->loadHelper('ImageUploader.ImageUploader');
// How many there are altogether, said once at the top. Without it there is no
// way to tell "that is all of them" from "it stopped loading" — which is
// exactly the question a member asked after clicking through 25 pages.
if (($page ?? 1) === 1 && isset($total)) {
    printf(
        '<p class="upload-count">%s</p>',
        h(__n('{0} file', '{0} files', (int)$total, (int)$total))
    );
}
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
            <?php // A native <details> so the answer toggles shut on a second
                  // click with no JS; htmx loads it once, when it first opens. ?>
            <details class="upload-usage-wrap">
                <summary class="upload-tile-usage" title="<?= h(__('upload.usage.link')) ?>">
                    <i class="fa fa-search" aria-hidden="true"></i>
                </summary>
                <div class="js-uploadUsage upload-usage"
                     hx-get="<?= $webroot ?>entries/htmx-upload-usage/<?= (int)$u->get('id') ?>"
                     hx-trigger="intersect once"
                     hx-target="this" hx-swap="innerHTML"><span class="upload-usage-loading"><?= h(__('Loading …')) ?></span></div>
            </details>
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
    <?php // `intersect once` alongside the click: the next page loads by itself
          // when the control scrolls into view, so nobody has to press the same
          // button two dozen times. `intersect` and not `revealed` on purpose —
          // it uses an IntersectionObserver, which honours the clipping of the
          // scrolling grid this control sits in; `revealed` measures against the
          // window and would fire for a control the reader cannot even see,
          // pulling the whole archive in one burst.
          //
          // The button stays a button: it is the fallback for keyboard use and
          // for anyone whose browser does not do the observing. ?>
    <button type="button" class="btn btn-link js-uploadsMore"
            hx-get="<?= $webroot ?>entries/htmx-uploads?page=<?= (int)$page + 1 ?><?= $manage ? '&manage=1' : '' ?><?= !empty($ownerId) ? '&id=' . (int)$ownerId : '' ?>"
            hx-trigger="click, intersect once"
            hx-target="this" hx-swap="outerHTML">
        <?= h(__('Load more')) ?>
    </button>
<?php endif; ?>
