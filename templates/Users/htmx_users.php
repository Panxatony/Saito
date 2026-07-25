<?php
/**
 * Member list as an htmx/Alpine island (strangler-fig PoC).
 *
 * Reachable at /users/htmx-users. A sortable, server-rendered table; clicking a
 * column header htmx-swaps the whole list (sort menu + table) into #js-userList,
 * so sorting happens in place and the active-column arrow updates. The header
 * links are real <a href> too, so without JS they sort via a full-page request.
 * Served standalone (no SPA) in the htmx_island layout. Non-thread content — the
 * island bundle just supplies the htmx runtime (its thread handler is inert).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User[] $users
 * @var array $menuItems
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="user index">
    <div class="panel">
        <?= $this->Layout->panelHeading(__('Members'), ['pageHeading' => true]) ?>
        <div class="panel-content" id="js-userList">
            <?= $this->element('users/htmx_user_list', compact('users', 'menuItems')) ?>
        </div>
    </div>
</div>

<?php
// htmx runtime via the shared island (thread handler is inert on this page).
