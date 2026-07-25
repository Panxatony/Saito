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

$historyUrl = [
    'controller' => 'searches', 'action' => 'advanced', '?' => ['name' => $user->get('username')],
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
    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('user.b.profile')) ?>
        </div>
        <div class="card-body">
            <div class="mb-3"><?= $this->User->getAvatar($user, ['link' => false]) ?></div>
            <table class="table th-left elegant">
                <?= $this->Html->tableCells($rows) ?>
            </table>
            <?php // Own profile → offer the settings page (the header cog was removed). ?>
            <?php if ($CurrentUser->isLoggedIn() && $CurrentUser->isUser($user)) : ?>
                <div class="mt-3">
                    <a href="<?= $this->request->getAttribute('webroot') ?>users/htmx-edit" class="btn btn-primary">
                        <?= $this->Layout->textWithIcon(h(__('Settings')), 'cog') ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('user.recentposts.t')) ?>
        </div>
        <div class="card-body js-thread-island">
            <?= $this->element(
                'users/recent_posts_list',
                compact('user', 'lastEntries', 'hasMoreEntriesThanShownOnPage')
            ) ?>
        </div>
    </div>
</div>

<?php
