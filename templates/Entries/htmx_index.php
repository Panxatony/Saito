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

<div class="island-cols">
<div class="island-main">

<?php // Live poll: every 30s ask whether new postings arrived since page load. ?>
<?= $this->element('entry/htmx_new_posts_poller', ['newestEntryId' => $newestEntryId]) ?>

<?php // "New entry" trigger above the thread list; toggles an inline editor here. ?>
<?php if ($CurrentUser->isLoggedIn()) : ?>
    <div class="threadlist-actions">
        <a href="<?= $webroot ?>entries/htmx-add" class="btn btn-primary js-headerToggle"
           data-hx-url="<?= $webroot ?>entries/htmx-add?inline=1" data-hx-target="js-newEntrySlot">
            <?= $this->Layout->textWithIcon(h(__('new_entry_linkname')), 'plus') ?>
        </a>
        <?php // Mark everything read; the 204 + HX-Trigger reloads the list. ?>
        <a href="<?= $webroot ?>entries/update" class="btn btn-link js-markAllRead"
           hx-get="<?= $webroot ?>entries/update" hx-swap="none">
            <?= $this->Layout->textWithIcon(h(__('mark_all_read')), 'check') ?>
        </a>
        <?php // Category filter: setting it (204 + HX-Trigger) reloads the list. ?>
        <?php if (!empty($categoryChooser)) : ?>
            <select class="form-control js-categoryChooser" data-list-url="<?= $webroot ?>entries/htmx-index"
                    style="width: auto; display: inline-block; margin-left: .5rem;"
                    aria-label="<?= h(__('all_categories')) ?>">
                <option value="all"<?= ($activeCategory === 'all') ? ' selected' : '' ?>>
                    <?= h(__('all_categories')) ?>
                </option>
                <?php foreach ($categoryChooser as $cat) : ?>
                    <option value="<?= (int)$cat['id'] ?>"<?= ((int)$activeCategory === (int)$cat['id']) ? ' selected' : '' ?>>
                        <?= h($cat['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>
    <div id="js-newEntrySlot"></div>
<?php endif; ?>

<?php
// The list reloads its first page whenever a `refresh-recent` event reaches the
// body — fired by the HX-Trigger header the inline new-entry post returns (and
// usable by anything else that changes the list). The htmx-index HX response is
// the htmx_index_threads fragment (page 1 lines + load-more + an out-of-band
// poller reset).
?>
<div id="js-threadList" class="entry index js-thread-island"
     hx-get="<?= $webroot ?>entries/htmx-index"
     hx-trigger="refresh-recent from:body"
     hx-target="#js-threadList"
     hx-swap="innerHTML">
    <?= $this->element(
        'entry/thread_cached_init',
        ['entriesSub' => $entries, 'toolboxButtons' => ['panel-info' => true]]
    ) ?>
    <?= $this->element('entry/htmx_load_more') ?>
</div>
</div><?php // /.island-main ?>

<?php // Right rail: who's online / recent posts / my posts. Loads on page load,
      // refreshes every 60s and after new posts (refresh-recent). ?>
<aside class="island-sidebar"
       hx-get="<?= $webroot ?>entries/htmx-widgets"
       hx-trigger="load, every 60s, refresh-recent from:body"
       hx-swap="innerHTML"></aside>
</div><?php // /.island-cols ?>
