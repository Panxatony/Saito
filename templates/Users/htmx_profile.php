<?php
/**
 * A user's profile as an htmx island page (strangler-fig PoC).
 *
 * Reachable at /users/htmx-profile/<id>. A slim profile summary plus the user's
 * recent postings (reusing the recent_posts_list element in a .js-thread-island,
 * so the island enhances them). Served standalone (no SPA).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array $lastEntries
 * @var bool $hasMoreEntriesThanShownOnPage
 * @var mixed $solved
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');

// Island install: use the island's advanced search, so the link doesn't drop the
// reader into the SPA shell.
$historyUrl = [
    'controller' => 'searches',
    'action' => \Cake\Core\Configure::read('Saito.frontend') === 'island' ? 'htmxAdvanced' : 'advanced',
    '?' => ['name' => $user->get('username')],
];

$rows = [
    [
        __('username_marking'),
        h($user->get('username'))
            . " <span class='infoText'>(" . h($this->Permissions->roleAsString($user->getRole())) . ")</span>",
    ],
];
if ($user->get('user_real_name')) {
    $rows[] = [__('user_real_name'), h($user->get('user_real_name'))];
}
if ($user->get('user_hp')) {
    $rows[] = [__('user_hp'), $this->User->linkExternalHomepage($user->get('user_hp'))];
}
if ($user->get('user_place')) {
    $rows[] = [__('user_place'), h($user->get('user_place'))];
}
$rows[] = [__('user_since'), $this->TimeH->formatTime($user->get('registered'), 'd.m.Y')];
if ($CurrentUser->permission('saito.core.user.lastLogin.view')) {
    $rows[] = [
        __('user.lastLogin.t'),
        empty($user->get('last_login'))
            ? __('user.lastLogin.never')
            : $this->TimeH->formatTime($user->get('last_login')),
    ];
}
$rows[] = [__('user_postings'), $this->Html->link($user->numberOfPostings(), $historyUrl)];
if ($solved) {
    $rows[] = [$this->Posting->solvedBadge(), $solved];
}
if ($user->get('user_online') && $user->get('user_online')['logged_in']) {
    $rows[] = [__('userlist_online'), __('Online')];
}
if ($user->get('profile')) {
    $rows[] = [__('user_profile'), $this->Parser->parse($user->get('profile'))];
}
if ($user->get('signature')) {
    $rows[] = [__('user_signature'), $this->Parser->parse($user->get('signature'), ['embed' => false])];
}
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">

<div class="users view">
    <?= $this->element('layout/htmx_back') ?>
    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('user.b.profile')) ?>
        </div>
        <div class="card-body">
            <div class="mb-3"><?= $this->User->getAvatar($user, ['link' => false]) ?></div>
            <table class="table th-left elegant">
                <?= $this->Html->tableCells($rows) ?>
            </table>
            <?php // Own profile → settings; another member's → ignore toggle. ?>
            <?php if ($CurrentUser->isLoggedIn() && $CurrentUser->isUser($user)) : ?>
                <div class="mt-3">
                    <a href="<?= $this->request->getAttribute('webroot') ?>users/htmx-edit" class="btn btn-primary">
                        <?= $this->Layout->textWithIcon(h(__('Settings')), 'cog') ?>
                    </a>
                </div>
            <?php elseif ($CurrentUser->isLoggedIn()) : ?>
                <?php // Ignore / unignore this member — reuses the classic POST actions
                      // (they redirect back to the referer, i.e. this profile). ?>
                <?php $isIgnored = (bool)$CurrentUser->ignores((int)$user->get('id')); ?>
                <?php $canContact = $user->get('personal_messages')
                    || $CurrentUser->permission('saito.core.user.contact'); ?>
                <div class="mt-3" style="display:flex; gap:.5rem; flex-wrap:wrap;">
                    <?php if ($canContact) : ?>
                        <?php $contactUrl = $this->request->getAttribute('webroot')
                            . 'contacts/htmx-contact-user/' . (int)$user->get('id'); ?>
                        <a href="<?= $contactUrl ?>"
                           class="btn btn-outline-secondary js-contactModalOpen"
                           data-modal-url="<?= $contactUrl ?>">
                            <i class="fa fa-envelope"></i> <?= h(__('user_contact_link')) ?>
                        </a>
                    <?php endif; ?>
                    <?= $this->Form->create(null, [
                        'url' => ['controller' => 'Users', 'action' => $isIgnored ? 'unignore' : 'ignore'],
                    ]) ?>
                    <?= $this->Form->hidden('id', ['value' => (int)$user->get('id')]) ?>
                    <?= $this->Form->button(
                        '<i class="fa fa-' . ($isIgnored ? 'check' : 'ban') . '"></i> '
                            . h($isIgnored ? __('unignore_this_user') : __('ignore_this_user')),
                        ['type' => 'submit', 'class' => 'btn btn-outline-secondary', 'escapeTitle' => false]
                    ) ?>
                    <?= $this->Form->end() ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('user.recentposts.t', [$user->get('username')])) ?>
        </div>
        <div class="card-body js-thread-island">
            <?= $this->element(
                'users/recent_posts_list',
                compact('user', 'lastEntries', 'hasMoreEntriesThanShownOnPage')
            ) ?>
        </div>
    </div>

    <?php // Own profile → private sections: bookmarks + uploads, lazy-loaded via
          // htmx from their existing island endpoints (HX-Request → fragment). ?>
    <?php if ($CurrentUser->isLoggedIn() && $CurrentUser->isUser($user)) : ?>
        <?php $webroot = $this->request->getAttribute('webroot'); ?>
        <?php // The bookmarks endpoint returns a self-contained card, so load it
              // bare (wrapping it would double the heading). ?>
        <div hx-get="<?= $webroot ?>users/bookmarks" hx-trigger="load" hx-swap="innerHTML">
            <p class="text-muted"><?= h(__('Loading …')) ?></p>
        </div>

        <?php // The uploads fragment is bare tiles (the grid normally comes from the
              // editor overlay), so give it a .upload-grid to lay out in. ?>
        <div class="card mb-3">
            <div class="card-header">
                <?php // "Uploads", not "upload images": this manages the archive. ?>
                <?= $this->Layout->panelHeading(__('upl.title.pl')) ?>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin: 0 0 .5rem; font-size: .9rem;">
                    <?= h(__('upl.manage.exp')) ?>
                </p>
                <div class="upload-grid js-uploadManageGrid"
                     hx-get="<?= $webroot ?>entries/htmx-uploads?manage=1"
                     hx-trigger="load" hx-swap="innerHTML">
                    <p class="text-muted"><?= h(__('Loading …')) ?></p>
                </div>
                <?php // Bulk action: lives outside the grid so it survives the
                      // htmx swaps that "load more" performs. ?>
                <div class="upload-actions">
                    <button type="button" class="btn btn-outline-danger js-uploadsDeleteSelected"
                            data-label="<?= h(__('upl.delete.selected')) ?>"
                            data-confirm="<?= h(__('upl.delete.confirm')) ?>" disabled>
                        <i class="fa fa-trash-o"></i> <?= h(__('upl.delete.selected')) ?>
                    </button>
                </div>
            </div>
        </div>

        <?php // Personalized RSS feeds (carry the user's token → include their
              // readable non-public categories). The Feeds cell builds the links. ?>
        <div class="card mb-3">
            <div class="card-header">
                <?= $this->Layout->panelHeading(__('s.rss.t')) ?>
            </div>
            <div class="card-body">
                <?= $this->cell('Feeds.FeedLinks', [$CurrentUser]) ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
