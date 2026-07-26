<?php
/**
 * "Load more" control for the member list, rendered as a table row.
 *
 * A row rather than a button below the table on purpose: the swap replaces this
 * element with the next page's rows plus a fresh control, and inside a <tbody>
 * only rows are valid HTML — a <div> there gets hoisted out of the table by the
 * parser.
 *
 * The href is the no-JS fallback (plain page two); htmx appends instead.
 *
 * @var \App\View\AppView $this
 * @var mixed $users
 */

// Paging state off the result set, not off PaginatorHelper: the helper throws
// when paginate() has not run, and a template should not depend on that.
if (!($users instanceof \Cake\Datasource\Paging\PaginatedInterface) || !$users->hasNextPage()) {
    return;
}

$query = $this->request->getQueryParams();
$nextPage = $users->currentPage() + 1;
// Left side wins in a union, so page/more override whatever is in the URL while
// sort and direction ride along — without them page two would sort differently.
$base = ['controller' => 'Users', 'action' => 'htmxUsers'];
$pageUrl = $this->Url->build($base + ['?' => ['page' => $nextPage] + $query], ['escape' => false]);
$moreUrl = $this->Url->build($base + ['?' => ['page' => $nextPage, 'more' => 1] + $query], ['escape' => false]);
?>
<tr id="js-usersLoadMore">
    <td colspan="2" class="text-center">
        <a href="<?= h($pageUrl) ?>" class="btn btn-link"
           hx-get="<?= h($moreUrl) ?>"
           hx-target="#js-usersLoadMore"
           hx-swap="outerHTML"
           hx-indicator="#js-usersLoadMore">
            <i class="fa fa-spinner fa-spin htmx-indicator"></i>
            <?= __('Load more') ?>
        </a>
    </td>
</tr>
