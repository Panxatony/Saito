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
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');

$rows = [
    [__('username_marking'), h($user->get('username')) . " <span class='infoText'>(" . h($this->Permissions->roleAsString($user->getRole())) . ")</span>"],
    [__('user_since'), $this->TimeH->formatTime($user->get('registered'), 'd.m.Y')],
    [__('user_postings'), $user->numberOfPostings()],
];
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
echo $this->Html->script('htmx-threads.bundle.js');
