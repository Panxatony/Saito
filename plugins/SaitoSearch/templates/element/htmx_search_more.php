<?php
/**
 * "Load more" control for the island search results.
 *
 * The button replaces *itself* with the next page's fragment, which ends with a
 * button for the page after — so each click appends a page and the control moves
 * down; on the last page it is not rendered at all.
 *
 * Two things this must get right:
 *
 * - It reads the paging state off the result set, not off PaginatorHelper. The
 *   advanced search returns early when no search term was given, so paginate()
 *   never ran and the helper throws "You must set a pagination instance".
 * - It links back to the action that is actually running. This fragment serves
 *   both the simple search (header widget) and the advanced one; hard-wiring
 *   htmxAdvanced sent the widget's `searchTerm` to the wrong action.
 *
 * @var \App\View\AppView $this
 * @var mixed $results
 */

if (!($results instanceof \Cake\Datasource\Paging\PaginatedInterface) || !$results->hasNextPage()) {
    return;
}

$query = $this->request->getQueryParams();
$nextPage = $results->currentPage() + 1;
// Left side wins in a union, so page overrides whatever is in the URL while the
// search terms ride along — without them page two would be a fresh search.
$nextUrl = $this->Url->build([
    'plugin' => 'SaitoSearch',
    'controller' => 'Searches',
    'action' => $this->request->getParam('action'),
    '?' => ['page' => $nextPage] + $query,
], ['escape' => false]);
?>
<div id="js-searchLoadMore" class="text-center" style="padding: 1em 0;">
    <button type="button" class="btn btn-link"
            hx-get="<?= h($nextUrl) ?>"
            hx-target="#js-searchLoadMore"
            hx-swap="outerHTML"
            hx-indicator="#js-searchLoadMore">
        <i class="fa fa-spinner fa-spin htmx-indicator"></i>
        <?= __('Load more') ?>
    </button>
</div>
