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
 * @var list<string>|null $minimisedWidgets widgets the member keeps as icons
 * @var list<string>|null $widgetOrder the order the member dragged them into
 */

// A minimised widget renders as a square icon in the rail instead of a card.
// Decided server-side so the rail does not flash open before a script folds it.
$minimised = $minimisedWidgets ?? [];
$widgetClass = fn(string $id): string => in_array($id, $minimised, true)
    ? 'island-widget is-min'
    : 'island-widget';

// The drag handle. A button of its own rather than something inside the
// heading: the heading already *is* a button, nesting one in it is invalid and
// browsers repair it by splitting the element. Being a real button also makes
// the rail keyboard-orderable for free (see widgets.ts).
$widgetGrip = fn(string $label): string => sprintf(
    '<button type="button" class="island-widget-grip js-widgetDrag" aria-label="%s" title="%s">'
    . '<i class="fa fa-bars" aria-hidden="true"></i></button>',
    h(__('Move widget') . ': ' . $label),
    h(__('Move widget')),
);

// Shown only while minimised (CSS): the glyph that stands for the widget, plus
// — for the online widget — the number of signed-in members.
$widgetIcon = function (string $icon, ?int $badge = null): string {
    $html = sprintf('<i class="fa fa-%s island-widget-icon" aria-hidden="true"></i>', h($icon));
    if ($badge !== null && $badge > 0) {
        $html .= sprintf('<span class="island-widget-badge">%d</span>', $badge);
    }

    return $html;
};

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

// Each widget is rendered into its own buffer and echoed further down in the
// member's order. Capturing beats moving the markup into partials: the order is
// data, the markup is not, and this way one is expressed without disturbing the
// other.
$blocks = [];
?>
<?php if (isset($online)) : ?>
    <?php ob_start(); ?>
    <?php // The icon badge counts signed-in members only: guests and bots are
          // shown inside the open widget but are not what "who is here" means. ?>
    <?php $onlineLabel = __('{0} {1} online', (int)($onlineCount ?? 0), __('Users')); ?>
    <section class="<?= $widgetClass('online') ?>" data-widget="online"
             data-icon="users" data-badge="<?= (int)($onlineCount ?? 0) ?>"
             data-label="<?= h($onlineLabel) ?>">
        <?= $widgetGrip($onlineLabel) ?>
        <button type="button" class="island-widget-head js-widgetToggle">
            <?= $widgetIcon('users', (int)($onlineCount ?? 0)) ?>
            <?php // Everything but the icon and its badge lives in one wrapper, so
                  // minimising hides it with a single rule. Hiding the parts
                  // individually does not work: the label carries Bootstrap's
                  // `.d-md-inline` (display: inline !important) through
                  // .saito-icon-text, which no ordinary rule can override. ?>
            <span class="island-widget-label">
                <?php // "3 Benutzer online" — das Wort führt zur Mitgliederübersicht.
                      // textWithIcon maskiert nicht, der Link darf also HTML sein. ?>
                <?php $usersLink = $this->Html->link(__('Users'), $webroot . 'users/htmx-users'); ?>
                <?= $this->Layout->textWithIcon(
                    __('{0} {1} online', (int)($onlineCount ?? 0), $usersLink),
                    'users'
                ) ?>
                <i class="fa fa-chevron-up island-widget-caret" aria-hidden="true"></i>
            </span>
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
    <?php $blocks['online'] = ob_get_clean(); ?>
<?php endif; ?>

<?php ob_start(); ?>
<section class="<?= $widgetClass('recent') ?>" data-widget="recent"
         data-icon="clock-o" data-label="<?= h(__('Recent entries')) ?>">
    <?= $widgetGrip(__('Recent entries')) ?>
    <button type="button" class="island-widget-head js-widgetToggle">
        <?= $widgetIcon('clock-o') ?>
        <span class="island-widget-label">
            <?= $this->Layout->textWithIcon(h(__('Recent entries')), 'clock-o') ?>
            <i class="fa fa-chevron-up island-widget-caret" aria-hidden="true"></i>
        </span>
    </button>
    <div class="island-widget-body"><?php $postingList($recentEntries ?? []); ?></div>
</section>
<?php $blocks['recent'] = ob_get_clean(); ?>

<?php if (isset($myPosts)) : ?>
    <?php ob_start(); ?>
    <?php $mineLabel = __('user.recentposts.t', [$CurrentUser->get('username')]); ?>
    <section class="<?= $widgetClass('mine') ?>" data-widget="mine"
             data-icon="book"
             data-label="<?= h($mineLabel) ?>">
        <?= $widgetGrip($mineLabel) ?>
        <button type="button" class="island-widget-head js-widgetToggle">
            <?= $widgetIcon('book') ?>
            <span class="island-widget-label">
                <?= $this->Layout->textWithIcon(h(__('user.recentposts.t', [$CurrentUser->get('username')])), 'book') ?>
                <i class="fa fa-chevron-up island-widget-caret" aria-hidden="true"></i>
            </span>
        </button>
        <div class="island-widget-body"><?php $postingList($myPosts); ?></div>
    </section>
    <?php $blocks['mine'] = ob_get_clean(); ?>
<?php endif; ?>
<?php
// Emit in the member's order, then anything that order does not name. The
// second half is what keeps a widget added in a later release visible to
// members who arranged their rail before it existed.
$rendered = array_keys($blocks);
$order = array_merge(
    array_intersect($widgetOrder ?? [], $rendered),
    array_diff($rendered, $widgetOrder ?? []),
);
foreach ($order as $id) {
    echo $blocks[$id];
}
