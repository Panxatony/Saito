<?php
/**
 * PoC shell page: a user's recent postings rendered with htmx + Alpine.js
 * instead of the Backbone/Marionette SPA.
 *
 * Reachable at /users/recent-posts/<id>. The htmx container pulls the
 * server-rendered list fragment from the same action (which returns just the
 * fragment when the `HX-Request` header is present). Alpine drives the local
 * auto-refresh toggle; htmx does the fetching and DOM swap. The island bundle
 * (htmx-recent.bundle.js) is loaded only here and coexists with the SPA.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */

$fragmentUrl = $this->Url->build([
    'controller' => 'Users',
    'action' => 'recentPosts',
    $user->get('id'),
]);
$csrfToken = $this->getRequest()->getAttribute('csrfToken');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="users recent-posts-htmx"
     x-data="{
        auto: false,
        timer: null,
        toggleAuto() {
            this.auto = !this.auto;
            if (this.auto) {
                this.timer = setInterval(
                    () => window.htmx.trigger(document.body, 'refresh-recent'),
                    30000
                );
            } else {
                clearInterval(this.timer);
            }
        }
     }">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <?= $this->Layout->panelHeading(
                    __('user.recentposts.t') . ' — ' . h($user->get('username'))
                ) ?>
            </span>
            <span class="d-flex align-items-center">
                <button type="button" class="btn btn-sm btn-link"
                        hx-get="<?= h($fragmentUrl) ?>"
                        hx-target="#js-recentPostsList"
                        hx-swap="innerHTML"
                        hx-indicator="#js-recentPostsSpinner">
                    <i class="fa fa-refresh"></i> <?= __('Reload') ?>
                </button>
                <label class="mb-0 ml-3" style="font-weight: normal;">
                    <input type="checkbox" x-model="auto" @change="toggleAuto()">
                    <?= __('Auto') ?> (30s)
                </label>
            </span>
        </div>
        <div class="card-body">
            <span id="js-recentPostsSpinner" class="htmx-indicator">
                <i class="fa fa-spinner fa-spin"></i> <?= __('Loading') ?>&hellip;
            </span>
            <div id="js-recentPostsList"
                 hx-get="<?= h($fragmentUrl) ?>"
                 hx-trigger="load, refresh-recent from:body"
                 hx-swap="innerHTML"
                 hx-indicator="#js-recentPostsSpinner">
            </div>
        </div>
    </div>
</div>

<?php
// The htmx + Alpine island, built by Vite (ENTRY=htmx-recent). Loaded only on
// this page; cache-busted by Asset.timestamp.
echo $this->Html->script('htmx-recent.bundle.js');
