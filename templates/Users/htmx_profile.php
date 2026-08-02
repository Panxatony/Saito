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


// Island install: use the island's advanced search, so the link doesn't drop the
// reader into the SPA shell.
$historyUrl = [
    'controller' => 'searches',
    'action' => 'htmxAdvanced',
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

// The rank a member has written their way to. Off unless the installation
// configured it: `userranks_show` has to be on *and* `userranks_ranks` has to
// parse into something, so an install that never set this up — macfix — shows
// no row at all rather than an empty one.
//
// `numberOfPostings()` reads the `entry_count` column, so this costs nothing
// beyond the string work.
if (\Cake\Core\Configure::read('Saito.Settings.userranks_show')) {
    $rank = \Saito\User\Userranks\Ranks::fromSetting(
        \Cake\Core\Configure::read('Saito.Settings.userranks_ranks')
    )->titleFor($user->numberOfPostings());
    if ($rank !== null) {
        $rows[] = [__('user.rank.t'), h($rank)];
    }
}
if ($solved) {
    $rows[] = [$this->Posting->solvedBadge(), $solved];
}
if ($user->get('user_online') && $user->get('user_online')['logged_in']) {
    $rows[] = [__('userlist_online'), __('Online')];
}
// How many members ignore this one. Public by design — it says something about
// how somebody is received, and hiding it would only make it guesswork.
if ($ignoredByOthers > 0) {
    $rows[] = [__('user.ignored.by.t'), $ignoredByOthers];
}
// The other direction is nobody else's business, so it appears on your own
// profile only — $ignoredByMe is null everywhere else.
if ($ignoredByMe !== null && count($ignoredByMe) > 0) {
    $namen = [];
    foreach ($ignoredByMe as $ignored) {
        $namen[] = $this->Html->link(
            $ignored->get('username'),
            ['controller' => 'Users', 'action' => 'htmxProfile', $ignored->get('id')]
        );
    }
    $rows[] = [__('user.ignoring.t'), implode(', ', $namen)];
}
if ($user->get('profile')) {
    $rows[] = [__('user_profile'), $this->Parser->parse($user->get('profile'))];
}
if ($user->get('signature')) {
    $rows[] = [__('user_signature'), $this->Parser->parse($user->get('signature'), ['embed' => false])];
}
?>
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

    <?php
    // Moderation: blocking lives here rather than in the admin backend, because
    // `saito.core.user.lock.set` grants it to moderators and the backend is
    // admin-only. The SPA had this on its own profile page; without it, a forum
    // on the island frontend cannot block anybody.
    if ($mayLock) : ?>
        <div class="card mb-3">
            <div class="card-header">
                <?= $this->Layout->panelHeading(__('user.block.history')) ?>
            </div>
            <div class="card-body">
                <?= $this->element('users/block-report', ['UserBlock' => $user->get('user_blocks')]) ?>

                <?php if (!$user->get('user_lock')) : ?>
                    <?php
                    $durationLabels = [];
                    foreach ($lockDurations as $seconds) {
                        $hours = (int)($seconds / 3600);
                        $durationLabels[$seconds] = $hours < 48
                            ? __('user.block.hours', ['hours' => $hours])
                            : __n('{0} day', '{0} days', (int)($hours / 24), (int)($hours / 24));
                    }
                    ?>
                    <?= $this->Form->create($blockForm, [
                        'url' => ['controller' => 'Users', 'action' => 'lock'],
                        'class' => 'mt-3',
                    ]) ?>
                    <?= $this->Form->hidden('lockUserId', ['value' => (int)$user->get('id')]) ?>
                    <div class="form-group">
                        <?php // A plain select, so blocking works without JavaScript —
                              // the SPA drove a range slider from a script. ?>
                        <?= $this->Form->control('lockPeriod', [
                            'type' => 'select',
                            'options' => $durationLabels,
                            'default' => 86400,
                            'label' => __('user.block.duration'),
                        ]) ?>
                    </div>
                    <?= $this->Form->button(
                        '<i class="fa fa-ban"></i> ' . h(__('Block User')),
                        ['type' => 'submit', 'class' => 'btn btn-outline-danger', 'escapeTitle' => false]
                    ) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php // Own profile → private sections: bookmarks + uploads, lazy-loaded via
          // htmx from their existing island endpoints (HX-Request → fragment). ?>
    <?php if ($CurrentUser->isLoggedIn() && $CurrentUser->isUser($user)) : ?>
        <?php $webroot = $this->request->getAttribute('webroot'); ?>
        <?php // The bookmarks endpoint returns a self-contained card, so load it
              // bare (wrapping it would double the heading). ?>
        <div hx-get="<?= $webroot ?>users/bookmarks" hx-trigger="load" hx-swap="innerHTML">
            <p class="text-muted"><?= h(__('Loading …')) ?></p>
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
        <?php // Last on the page, and that is the whole reason it sits here rather
              // than after the bookmarks where it used to: the archive loads more
              // tiles as it is scrolled, so anything below it walks away from the
              // reader. A section that grows without end belongs at the end.
              //
              // The fragment is bare tiles (the grid normally comes from the editor
              // overlay), so give it a .upload-grid to lay out in. ?>
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

    <?php endif; ?>

    <?php // Somebody else's profile, seen by an admin: their upload archive.
          // `saito.plugin.uploader.view` has granted admins this all along, but
          // the only action honouring it was the token-authed REST controller —
          // so moderating an upload meant leaving the app. The delete control is
          // the same one the owner gets; `saito.plugin.uploader.delete` grants it
          // to admins too. ?>
    <?php if (
        $CurrentUser->isLoggedIn()
        && !$CurrentUser->isUser($user)
        && $CurrentUser->permission('saito.core.admin.backend')
    ) : ?>
        <?php $webroot = $this->request->getAttribute('webroot'); ?>
        <div class="card mb-3">
            <div class="card-header">
                <?= $this->Layout->panelHeading(__('upl.title.pl')) ?>
            </div>
            <div class="card-body">
                <div class="upload-grid js-uploadManageGrid"
                     hx-get="<?= $webroot ?>entries/htmx-uploads?manage=1&amp;id=<?= (int)$user->get('id') ?>"
                     hx-trigger="load" hx-swap="innerHTML">
                    <p class="text-muted"><?= h(__('Loading …')) ?></p>
                </div>
                <div class="upload-actions">
                    <button type="button" class="btn btn-outline-danger js-uploadsDeleteSelected"
                            data-label="<?= h(__('upl.delete.selected')) ?>"
                            data-confirm="<?= h(__('upl.delete.confirm')) ?>" disabled>
                        <i class="fa fa-trash-o"></i> <?= h(__('upl.delete.selected')) ?>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
