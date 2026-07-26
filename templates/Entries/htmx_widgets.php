<?php
/**
 * Right-rail widgets for the island front page (EntriesController::htmxWidgets):
 * who's online, recent posts, and the member's own recent posts. Clean island
 * cards (collapsible via .js-widgetToggle); links open the island thread view.
 *
 * @var \App\View\AppView $this
 * @var iterable|null $online
 * @var int|null $onlineCount
 * @var int|null $guestCount
 * @var int|null $botCount
 * @var iterable $recentEntries
 * @var iterable|null $myPosts
 */

$webroot = $this->request->getAttribute('webroot');

// Role → status icon shown before an online user's name. All glyphs exist in
// the theme's Font-Awesome 4 set (no fa-crown there — owner uses fa-trophy).
$roleIcon = function (string $type): string {
    switch ($type) {
        case 'owner':
            return 'trophy';
        case 'admin':
            return 'certificate';
        case 'mod':
            return 'magic'; // wand
        default:
            return 'user';
    }
};

$postingList = function ($entries) use ($webroot) {
    if (empty($entries)) {
        echo '<p class="island-widget-empty">–</p>';

        return;
    }
    echo '<ul class="island-widget-list">';
    foreach ($entries as $entry) {
        $subject = (string)$entry->get('subject');
        printf(
            '<li><a href="%sentries/htmx-thread/%d">%s</a>'
            . '<span class="island-widget-meta">%s · %s</span></li>',
            $webroot,
            (int)$entry->get('id'),
            h($subject !== '' ? $subject : __('forum_show_thread')),
            h((string)$entry->get('name')),
            // formatTime returns a trusted <time> element — do not escape.
            $this->TimeH->formatTime($entry->get('time'))
        );
    }
    echo '</ul>';
};
?>
<?php if (isset($online)) : ?>
    <section class="island-widget" data-widget="online">
        <button type="button" class="island-widget-head js-widgetToggle">
            <?php // "3 Benutzer online" — das Wort führt zur Mitgliederübersicht.
                  // textWithIcon maskiert nicht, der Link darf also HTML sein. ?>
            <?php $usersLink = $this->Html->link(__('Users'), $webroot . 'users/htmx-users'); ?>
            <?= $this->Layout->textWithIcon(
                __('{0} {1} online', (int)($onlineCount ?? 0), $usersLink),
                'users'
            ) ?>
            <i class="fa fa-chevron-up island-widget-caret" aria-hidden="true"></i>
        </button>
        <div class="island-widget-body">
            <?php if (!empty($online)) : ?>
                <ul class="island-widget-list island-widget-users">
                    <?php foreach ($online as $userOnline) : $u = $userOnline->user; if ($u === null) {
                        continue;
                    } ?>
                        <?php $type = (string)$u->get('user_type'); ?>
                        <li>
                            <i class="fa fa-<?= h($roleIcon($type)) ?> island-role-icon island-role-<?= h($type) ?>"
                               title="<?= h($this->Permissions->roleAsString($type)) ?>" aria-hidden="true"></i>
                            <a href="<?= $webroot ?>users/htmx-profile/<?= (int)$u->get('id') ?>"><?= h($u->get('username')) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="island-widget-empty">–</p>
            <?php endif; ?>
            <?php // Guests + bots — no names, just a headcount with an icon each. ?>
            <?php if (!empty($guestCount) || !empty($botCount)) : ?>
                <div class="island-widget-anon">
                    <?php if (!empty($guestCount)) : ?>
                        <span title="<?= h(__('Guests')) ?>"><i class="fa fa-user-secret" aria-hidden="true"></i> <?= (int)$guestCount ?></span>
                    <?php endif; ?>
                    <?php if (!empty($botCount)) : ?>
                        <span title="<?= h(__('Bots')) ?>"><i class="fa fa-android" aria-hidden="true"></i> <?= (int)$botCount ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="island-widget" data-widget="recent">
    <button type="button" class="island-widget-head js-widgetToggle">
        <?= $this->Layout->textWithIcon(h(__('Recent entries')), 'clock-o') ?>
        <i class="fa fa-chevron-up island-widget-caret" aria-hidden="true"></i>
    </button>
    <div class="island-widget-body"><?php $postingList($recentEntries ?? []); ?></div>
</section>

<?php if (isset($myPosts)) : ?>
    <section class="island-widget" data-widget="mine">
        <button type="button" class="island-widget-head js-widgetToggle">
            <?= $this->Layout->textWithIcon(h(__('user.recentposts.t', [$CurrentUser->get('username')])), 'book') ?>
            <i class="fa fa-chevron-up island-widget-caret" aria-hidden="true"></i>
        </button>
        <div class="island-widget-body"><?php $postingList($myPosts); ?></div>
    </section>
<?php endif; ?>
