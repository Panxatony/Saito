<?php
/**
 * A single posting with the thread it belongs to — the island counterpart to
 * the SPA's `entries/view`.
 *
 * The posting in full at the top, the thread's tree of subject lines below it.
 * The tree is the same renderer the front page uses, so a click on a line opens
 * that posting inline, right here.
 *
 * @var \App\View\AppView $this
 * @var \Saito\Posting\PostingInterface $entry
 * @var \Saito\Posting\PostingInterface $tree
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
$webroot = $this->getRequest()->getAttribute('webroot');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<?= $this->element('layout/htmx_back') ?>

<div class="entry view js-thread-island">
    <div class="viewEntry">
        <?= $this->element('/entry/view_posting', ['entry' => $entry]) ?>
    </div>

    <?php // The rest of the conversation, as the tree of subject lines. The
          // collapse control is off: one is already inside a single thread. ?>
    <div class="viewEntry-thread">
        <h2 class="viewEntry-threadHeading"><?= h(__('Thread')) ?></h2>
        <?= $this->element(
            'entry/thread_cached_init',
            ['entriesSub' => [$tree], 'toolboxButtons' => ['collapse' => false]]
        ) ?>
    </div>

    <p style="margin-top: 1rem;">
        <a href="<?= $webroot ?>entries/htmx-thread/<?= (int)$entry->get('tid') ?>" class="btn btn-link">
            <?= $this->Layout->textWithIcon(h(__('gn.btn.mix.t')), 'mix') ?>
        </a>
    </p>
</div>

<?php
// Reusable htmx + Alpine thread-list island (ENTRY=htmx-threads).
echo $this->Html->script('htmx-threads.bundle.js');
