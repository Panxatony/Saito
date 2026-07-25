<?php
/**
 * Front-page thread list as an htmx/Alpine island (strangler-fig PoC).
 *
 * Reachable at /entries/htmx-index. Renders page 1 of the thread list
 * server-side; the "load more" control htmx-appends further pages
 * (htmx_index_threads.php fragment). The reusable thread-list island enhances
 * the lines (inline posting). Served standalone (no SPA) in the htmx_island
 * layout. Read-only slice — mark-as-read, category chooser, slidetabs and
 * whole-thread collapse are out of scope.
 *
 * @var \App\View\AppView $this
 * @var array $entries
 * @var int $newestEntryId
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
$webroot = $this->getRequest()->getAttribute('webroot');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<?php // Live poll: every 30s ask whether new postings arrived since page load. ?>
<?= $this->element('entry/htmx_new_posts_poller', ['newestEntryId' => $newestEntryId]) ?>

<?php // "New entry" trigger above the thread list; toggles an inline editor here. ?>
<?php if ($CurrentUser->isLoggedIn()) : ?>
    <div class="threadlist-actions">
        <a href="<?= $webroot ?>entries/htmx-add" class="btn btn-primary js-headerToggle"
           data-hx-url="<?= $webroot ?>entries/htmx-add?inline=1" data-hx-target="js-newEntrySlot">
            <?= $this->Layout->textWithIcon(h(__('new_entry_linkname')), 'plus') ?>
        </a>
    </div>
    <div id="js-newEntrySlot"></div>
<?php endif; ?>

<div id="js-threadList" class="entry index js-thread-island">
    <?= $this->element(
        'entry/thread_cached_init',
        ['entriesSub' => $entries, 'toolboxButtons' => ['panel-info' => true]]
    ) ?>
    <?= $this->element('entry/htmx_load_more') ?>
</div>

<?php
// Reusable htmx + Alpine thread-list island (ENTRY=htmx-threads).
echo $this->Html->script('htmx-threads.bundle.js');
