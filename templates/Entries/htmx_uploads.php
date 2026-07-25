<?php
/**
 * One page of the current user's upload archive (thumbnail tiles) for the editor
 * upload overlay, plus a "load more" control. Rendered by
 * EntriesController::htmxUploads(); the tiles insert their BBCode on click.
 *
 * @var \App\View\AppView $this
 * @var iterable $uploads
 * @var int $page
 * @var bool $hasMore
 */

$this->loadHelper('ImageUploader.ImageUploader');
$webroot = $this->request->getAttribute('webroot');

foreach ($uploads as $u) :
    $attr = $this->ImageUploader->image($u)['attributes'];
    $isImage = strpos((string)$attr['mime'], 'image/') === 0;
    $ext = strtoupper(ltrim((string)strrchr((string)$attr['name'], '.'), '.'));
    ?>
    <button type="button" class="js-uploadTile"
            data-name="<?= h($attr['name']) ?>" data-mime="<?= h($attr['mime']) ?>"
            title="<?= h($attr['title'] ?: $attr['name']) ?>">
        <?php if ($isImage) : ?>
            <img src="<?= h($attr['thumbnail_url']) ?>" alt="<?= h($attr['name']) ?>" loading="lazy">
        <?php else : ?>
            <span class="upload-tile-file"><?= h($ext !== '' ? $ext : 'FILE') ?></span>
        <?php endif; ?>
    </button>
    <?php
endforeach;

if ($hasMore) :
    ?>
    <button type="button" class="btn btn-link js-uploadsMore"
            hx-get="<?= $webroot ?>entries/htmx-uploads?page=<?= (int)$page + 1 ?>"
            hx-target="this" hx-swap="outerHTML">
        <?= h(__('Load more')) ?>
    </button>
<?php endif; ?>
