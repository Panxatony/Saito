<?php
/**
 * "Load more" control for the island search results, mirroring the front-page
 * thread list: the button htmx-GETs the next page and replaces *itself* with
 * that page's fragment, which ends with a button for the page after. So each
 * click appends a page and moves the button down; on the last page it is simply
 * not rendered any more.
 *
 * The current search terms have to ride along in the URL, or page two would be
 * a fresh, unfiltered search.
 *
 * @var \App\View\AppView $this
 */

if (!$this->Paginator->hasNext()) {
    return;
}

$query = $this->request->getQueryParams();
$query['page'] = $this->Paginator->current() + 1;

// escape => false, because h() below does the escaping; letting both run turns
// the query separators into &amp;amp; and page two loses every search term.
$nextUrl = $this->Url->build([
    'plugin' => 'SaitoSearch',
    'controller' => 'Searches',
    'action' => 'htmxAdvanced',
    '?' => $query,
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
