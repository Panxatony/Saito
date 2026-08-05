<?php
/**
 * Where an upload is embedded (issue #64). Rendered by
 * EntriesController::htmxUploadUsage() as an HX fragment and swapped in beside
 * the upload's tile in the profile manage grid.
 *
 * @var \App\View\AppView $this
 * @var iterable $postings
 */

$webroot = $this->request->getAttribute('webroot');

$list = [];
foreach ($postings as $p) {
    $list[] = $p;
}
?>
<?php if ($list === []) : ?>
    <p class="upload-usage-none text-muted"><?= h(__('upload.usage.none')) ?></p>
<?php else : ?>
    <p class="upload-usage-count">
        <?= h(__n('Embedded in {0} posting', 'Embedded in {0} postings', count($list), count($list))) ?>
    </p>
    <ul class="upload-usage-list">
        <?php foreach ($list as $p) : ?>
            <li>
                <a href="<?= $webroot ?>entries/view/<?= (int)$p->get('id') ?>">
                    <?= h(((string)$p->get('subject')) !== '' ? $p->get('subject') : __('(no subject)')) ?>
                </a>
                <?php // formatTime returns a trusted <time> element — do not escape. ?>
                <?= $this->TimeH->formatTime($p->get('time')) ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
