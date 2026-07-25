<?php
/**
 * htmx fragment: one page of front-page thread boxes plus the next "load more"
 * control. Rendered without a layout (see EntriesController::htmxIndex()); htmx
 * swaps it in place of the previous "load more" button, so the new page appends
 * below the already-loaded threads and the button advances to the page after.
 *
 * @var \App\View\AppView $this
 * @var array $entries
 */

echo $this->element(
    'entry/thread_cached_init',
    ['entriesSub' => $entries, 'toolboxButtons' => ['panel-info' => true]]
);
echo $this->element('entry/htmx_load_more');
