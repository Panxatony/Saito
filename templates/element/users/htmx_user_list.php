<?php
/**
 * Sortable member list — sort menu + table. Shared by the shell (wraps it in
 * #js-userList) and the htmx sort fragment (renders just this). A sort click
 * htmx-swaps the whole thing into #js-userList, so the active-column arrow
 * updates too — and it avoids the fragile "<tr> into <tbody>" innerHTML swap.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User[] $users
 * @var array $menuItems
 */

$currentSort = $this->Paginator->param('sort');
$currentDir = strtolower((string)$this->Paginator->param('direction'));

$sortLinks = [];
foreach ($menuItems as $field => $item) {
    [$title, $opts] = $item;
    $isActive = ($currentSort === $field);
    if ($isActive) {
        $dir = $currentDir === 'asc' ? 'desc' : 'asc';
        $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
    } else {
        $dir = $opts['direction'] ?? 'asc';
        $arrow = '';
    }
    // escape:false → raw `&`, h() below encodes it exactly once.
    $url = $this->Url->build([
        'controller' => 'Users',
        'action' => 'htmxUsers',
        '?' => ['sort' => $field, 'direction' => $dir],
    ], ['escape' => false]);
    $sortLinks[] = sprintf(
        '<a href="%s" hx-get="%s" hx-target="#js-userList" hx-swap="innerHTML" hx-push-url="true"%s>%s%s</a>',
        h($url),
        h($url),
        $isActive ? ' class="active"' : '',
        h($title),
        $arrow
    );
}
?>
<div class="table-menu sort-menu">
    <?= __('Sort by: {0}', implode(', ', $sortLinks)) ?>
</div>
<table class="table th-left row-sep">
    <tbody>
        <?= $this->element('users/htmx_user_rows', ['users' => $users]) ?>
        <?= $this->element('users/htmx_user_more', ['users' => $users]) ?>
    </tbody>
</table>
