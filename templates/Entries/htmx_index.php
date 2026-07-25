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
$addUrl = $this->Url->build(
    ['controller' => 'Entries', 'action' => 'htmxAdd', '?' => ['inline' => 1]],
    ['escape' => false]
);
$searchUrl = $this->Url->build(
    ['plugin' => 'SaitoSearch', 'controller' => 'Searches', 'action' => 'htmxSimple'],
    ['escape' => false]
);
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<?php // Inline actions: create a posting / search without leaving the page. ?>
<div class="htmx-index-actions" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <?php if ($CurrentUser->isLoggedIn()) : ?>
        <button type="button" class="btn btn-primary"
                hx-get="<?= h($addUrl) ?>"
                hx-target="#js-newEntry"
                hx-swap="innerHTML">
            <i class="fa fa-plus"></i> <?= h(__('new_entry_linkname')) ?>
        </button>
    <?php endif; ?>
    <form style="flex:1; min-width:14rem; margin:0; display:flex; gap:.4rem;"
          hx-get="<?= h($searchUrl) ?>"
          hx-target="#js-inlineSearchResults"
          hx-swap="innerHTML">
        <input type="search" name="searchTerm" class="form-control" style="margin:0;"
               placeholder="<?= h(__('Search')) ?>&hellip;">
        <button type="submit" class="btn btn-secondary"><i class="fa fa-search"></i></button>
    </form>
</div>
<div id="js-newEntry"></div>
<div id="js-inlineSearchResults" class="js-thread-island"></div>

<?php // Live poll: every 30s ask whether new postings arrived since page load. ?>
<?= $this->element('entry/htmx_new_posts_poller', ['newestEntryId' => $newestEntryId]) ?>

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
