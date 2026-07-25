<?php
/**
 * Member list as an htmx/Alpine island (strangler-fig PoC).
 *
 * Reachable at /users/htmx-users. A sortable, server-rendered table; clicking a
 * column header htmx-swaps just the table body (in-place sort, hx-push-url for a
 * bookmarkable URL). The header links are real <a href> too, so without JS they
 * sort via a normal full-page request. Served standalone (no SPA) in the
 * htmx_island layout. Non-thread content — no thread-list island needed, but the
 * island bundle supplies the htmx runtime (its thread handler is inert here).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User[] $users
 * @var array $menuItems
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
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
    $url = $this->Url->build([
        'controller' => 'Users',
        'action' => 'htmxUsers',
        '?' => ['sort' => $field, 'direction' => $dir],
    ]);
    $sortLinks[] = sprintf(
        '<a href="%s" hx-get="%s" hx-target="#js-userRows" hx-swap="innerHTML" hx-push-url="true"%s>%s%s</a>',
        h($url),
        h($url),
        $isActive ? ' class="active"' : '',
        h($title),
        $arrow
    );
}
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="user index">
    <div class="panel">
        <?= $this->Layout->panelHeading(__('Members'), ['pageHeading' => true]) ?>
        <div class="panel-content">
            <div class="table-menu sort-menu">
                <?= __('Sort by: {0}', implode(', ', $sortLinks)) ?>
            </div>
            <table class="table th-left row-sep">
                <tbody id="js-userRows">
                    <?= $this->element('users/htmx_user_rows', ['users' => $users]) ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// htmx runtime via the shared island (thread handler is inert on this page).
echo $this->Html->script('htmx-threads.bundle.js');
