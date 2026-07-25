<?php
/**
 * htmx "load more" pagination control for the front-page thread island.
 *
 * When another page exists, renders a button that htmx-GETs the next page and
 * replaces itself (outerHTML) with that page's fragment — which ends with a
 * fresh button for the page after. So each click appends the next page and
 * advances the button; it disappears on the last page. Shared by the shell
 * (htmx_index.php) and the fragment (htmx_index_threads.php).
 *
 * @var \App\View\AppView $this
 */

if (!$this->Paginator->hasNext()) {
    return;
}

$nextUrl = $this->Url->build([
    'controller' => 'Entries',
    'action' => 'htmxIndex',
    '?' => ['page' => $this->Paginator->current() + 1],
]);
?>
<div id="js-loadMore" class="text-center" style="padding: 1em 0;">
    <button type="button" class="btn btn-link"
            hx-get="<?= h($nextUrl) ?>"
            hx-target="#js-loadMore"
            hx-swap="outerHTML"
            hx-indicator="#js-loadMore">
        <i class="fa fa-spinner fa-spin htmx-indicator"></i>
        <?= __('Load more') ?>
    </button>
</div>
