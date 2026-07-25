<?php
/**
 * Live "new postings" banner fragment for the front-page island.
 *
 * Rendered by EntriesController::htmxNewCount() (no layout); the poller swaps it
 * into the banner slot. Empty when nothing is new. Clicking refreshes the thread
 * list *in place* (htmx loads page 1 into #js-threadList); that refresh fragment
 * also carries an out-of-band poller that resets the `since` marker and clears
 * this banner — no full page reload.
 *
 * @var \App\View\AppView $this
 * @var int $newCount
 */

if ($newCount < 1) {
    return;
}

$refreshUrl = $this->Url->build([
    'controller' => 'Entries',
    'action' => 'htmxIndex',
    '?' => ['page' => 1],
]);
?>
<button type="button" class="alert alert-info d-block text-center"
        style="width: 100%; margin-bottom: 1em; cursor: pointer;"
        hx-get="<?= h($refreshUrl) ?>"
        hx-target="#js-threadList"
        hx-swap="innerHTML"
        hx-indicator="#js-newPostsSpinner">
    <i class="fa fa-arrow-up"></i>
    <?= __('{0} new posting(s) — show', $newCount) ?>
    <i id="js-newPostsSpinner" class="fa fa-spinner fa-spin htmx-indicator"></i>
</button>
