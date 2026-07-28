<?php
/**
 * The guided tour in the help overlay (#js-helpModal).
 *
 * The text is Markdown, not markup: see SaitoHelp\Lib\OverlayHelp for where the
 * file is looked up and how a forum supplies its own. This element only turns
 * the parts into the overlay's markup.
 *
 * @var \App\View\AppView $this
 */

use SaitoHelp\Lib\OverlayHelp;

// Loaded here rather than relied upon from the controller's helper list: this
// element is the only thing in the island layout that renders Markdown, and it
// should not break if that list is rearranged.
$this->loadHelper('Commonmark.Commonmark');

// Saito.language, the same source SaitoHelpsController reads — not
// App.defaultLocale, which is the framework's formatting locale and defaults to
// en_US even on a German forum.
$markdown = OverlayHelp::markdown(
    (string)\Cake\Core\Configure::read('Saito.language'),
    $this->getTheme()
);

if ($markdown === null) {
    // No tour anywhere. Say so plainly rather than opening an empty overlay:
    // an operator who removed the file should see why, and a reader should not
    // be left staring at nothing.
    echo '<div class="island-help"><p class="island-help-lead">'
        . h(__('help.overlay.missing'))
        . '</p></div>';

    return;
}

$tour = OverlayHelp::split($markdown);
?>
<div class="island-help">
    <?php if ($tour['lead'] !== '') : ?>
        <div class="island-help-lead">
            <?= $this->Commonmark->parse($tour['lead']) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($tour['sections'] as $section) : ?>
        <div class="island-help-item">
            <?php if ($section['icon'] !== null) : ?>
                <i class="fa fa-<?= h($section['icon']) ?> island-help-icon" aria-hidden="true"></i>
            <?php endif; ?>
            <div>
                <strong><?= h($section['title']) ?></strong>
                <?= $this->Commonmark->parse($section['body']) ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($tour['outro'] !== '') : ?>
        <div class="island-help-outro">
            <?= $this->Commonmark->parse($tour['outro']) ?>
        </div>
    <?php endif; ?>
</div>
