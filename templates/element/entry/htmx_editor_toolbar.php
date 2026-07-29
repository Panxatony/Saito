<?php
/**
 * Minimal BBCode editor toolbar for the htmx island reply / new-thread forms.
 *
 * Buttons wrap the textarea selection in BBCode (handled by the island); the
 * preview button htmx-posts the form to htmxPreview and shows the rendered
 * result above the textarea. Placed directly before a `<textarea name="text">`
 * inside the same form.
 *
 * Icons are inline SVGs (Lucide-style, stroke = currentColor) so they render
 * crisply and don't depend on the theme's FontAwesome version.
 *
 * @var \App\View\AppView $this
 */

$previewUrl = $this->Url->build(
    ['controller' => 'Entries', 'action' => 'htmxPreview'],
    ['escape' => false]
);

// Inline SVG icon: stroked, sized to the current font, vertically centred.
$icon = function (string $paths): string {
    return '<svg viewBox="0 0 24 24" width="1.05em" height="1.05em" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
        . 'focusable="false" style="vertical-align:-0.18em">' . $paths . '</svg>';
};

$icons = [
    'bold' => '<path d="M6 4h8a4 4 0 0 1 0 8H6z"/><path d="M6 12h9a4 4 0 0 1 0 8H6z"/>',
    'italic' => '<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/>'
        . '<line x1="15" y1="4" x2="9" y2="20"/>',
    'strike' => '<path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/>'
        . '<line x1="4" y1="12" x2="20" y2="12"/>',
    'quote' => '<path d="M3 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 '
        . '1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/>'
        . '<path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 '
        . '1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/>',
    'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
        . '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'image' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>'
        . '<circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
        . '<polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
    'media' => '<rect x="2" y="2" width="20" height="20" rx="2.18"/><line x1="7" y1="2" x2="7" y2="22"/>'
        . '<line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/>'
        . '<line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/>'
        . '<line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/>',
    'smile' => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/>'
        . '<line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>',
];

// Smilies for the picker: one button per smiley (first code), rendered exactly
// like SmileyRenderer (font glyph or image), inserting its code on click.
$smilies = [];
$seenSmiley = [];
foreach ((new \Saito\Smiley\SmileyLoader())->get() as $s) {
    $key = (string)$s['image'];
    if (isset($seenSmiley[$key])) {
        continue;
    }
    $seenSmiley[$key] = true;
    $smilies[] = $s;
}

$buttons = [
    ['[b]', '[/b]', $icon($icons['bold']), __('Bold')],
    ['[i]', '[/i]', $icon($icons['italic']), __('Italic')],
    ['[s]', '[/s]', $icon($icons['strike']), __('Strikethrough')],
    ['[quote]', '[/quote]', $icon($icons['quote']), __('Quote')],
    ['[code]', '[/code]', $icon($icons['code']), __('Code')],
];
?>
<div class="js-editor-toolbar btn-toolbar" style="margin-bottom: .4em; gap: .3em;">
    <div class="btn-group btn-group-sm" role="group">
        <?php foreach ($buttons as [$open, $close, $label, $title]) : ?>
            <button type="button" class="btn btn-secondary js-bb-btn"
                    data-bb-open="<?= h($open) ?>" data-bb-close="<?= h($close) ?>"
                    title="<?= h($title) ?>" aria-label="<?= h($title) ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </div>
    <?php
    // One button, not two. There used to be a "Link" and a "Media" button side
    // by side, identical in every respect — same class, same overlay, same
    // behaviour. What is inserted has never depended on which one was pressed
    // but on the address typed into it: a YouTube link becomes a frame, an image
    // address an [img], a media file a [video] or [audio], anything else a link.
    // Two buttons offered a choice that did not exist.
    ?>
    <button type="button" class="btn btn-secondary btn-sm js-insertOpen"
            data-preview-url="<?= h($previewUrl) ?>"
            title="<?= h(__('insert.title')) ?>" aria-label="<?= h(__('insert.title')) ?>"><?= $icon($icons['link']) ?></button>
    <button type="button" class="btn btn-sm btn-link js-bb-upload" title="<?= h(__('upl.title.pl')) ?>">
        <?= $icon($icons['upload']) ?> <?= h(__('upl.title.pl')) ?>
    </button>
    <?php if (!empty($smilies)) : ?>
        <button type="button" class="btn btn-secondary btn-sm js-smiley-toggle"
                title="<?= h(__('Smilies')) ?>" aria-label="<?= h(__('Smilies')) ?>"><?= $icon($icons['smile']) ?></button>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-link js-bb-preview"
            data-preview-url="<?= h($previewUrl) ?>">
        <?= $icon($icons['eye']) ?> <?= h(__('Preview')) ?>
    </button>
    <?php if (!empty($smilies)) : ?>
        <?php // Smiley picker — hidden until toggled; each button inserts its code. ?>
        <div class="js-smiley-panel smiley-panel" hidden>
            <?php foreach ($smilies as $s) : ?>
                <button type="button" class="js-smiley" data-code="<?= h($s['code']) ?>"
                        title="<?= h($s['code']) ?>">
                    <?php if ($s['type'] === 'font') : ?>
                        <i class="saito-smiley-font saito-smiley-<?= h($s['image']) ?>"></i>
                    <?php else : ?>
                        <?= $this->Html->image('smilies/' . $s['image'], ['class' => 'saito-smiley-image', 'alt' => $s['code']]) ?>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
