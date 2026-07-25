<?php
/**
 * htmx fragment: one page of front-page thread boxes plus the next "load more"
 * control. Rendered without a layout (see EntriesController::htmxIndex()); htmx
 * swaps it in place of the previous "load more" button, so the new page appends
 * below the already-loaded threads and the button advances to the page after.
 *
 * @var \App\View\AppView $this
 * @var array $entries
 * @var int $newestEntryId
 */

echo $this->element(
    'entry/thread_cached_init',
    ['entriesSub' => $entries, 'toolboxButtons' => ['panel-info' => true]]
);
echo $this->element('entry/htmx_load_more');

// On an in-place refresh (page 1) reset the live poller out-of-band: swap a
// fresh slot carrying the new `since` marker, which also clears the banner.
// Load-more (page > 1) must not touch it.
if ($this->Paginator->current() === 1) {
    echo $this->element(
        'entry/htmx_new_posts_poller',
        ['newestEntryId' => $newestEntryId, 'oob' => true]
    );
}
