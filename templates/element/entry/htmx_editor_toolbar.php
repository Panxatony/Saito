<?php
/**
 * Minimal BBCode editor toolbar for the htmx island reply / new-thread forms.
 *
 * Buttons wrap the textarea selection in BBCode (handled by the island); the
 * preview button htmx-posts the form to htmxPreview and shows the rendered
 * result above the textarea. Placed directly before a `<textarea name="text">`
 * inside the same form.
 *
 * @var \App\View\AppView $this
 */

$previewUrl = $this->Url->build(
    ['controller' => 'Entries', 'action' => 'htmxPreview'],
    ['escape' => false]
);

$buttons = [
    ['[b]', '[/b]', '<b>B</b>', __('Bold')],
    ['[i]', '[/i]', '<i>I</i>', __('Italic')],
    ['[s]', '[/s]', '<s>S</s>', __('Strikethrough')],
    ['[quote]', '[/quote]', '<i class="fa fa-quote-right"></i>', __('Quote')],
    ['[code]', '[/code]', '<i class="fa fa-code"></i>', __('Code')],
    ['[url]', '[/url]', '<i class="fa fa-link"></i>', __('Link')],
    ['[img]', '[/img]', '<i class="fa fa-picture-o"></i>', __('Image')],
];
?>
<div class="js-editor-toolbar btn-toolbar" style="margin-bottom: .4em; gap: .3em;">
    <div class="btn-group btn-group-sm" role="group">
        <?php foreach ($buttons as [$open, $close, $label, $title]) : ?>
            <button type="button" class="btn btn-secondary js-bb-btn"
                    data-bb-open="<?= h($open) ?>" data-bb-close="<?= h($close) ?>"
                    title="<?= h($title) ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-link js-bb-preview"
            hx-post="<?= h($previewUrl) ?>"
            hx-include="closest form"
            hx-target="next .js-editor-preview"
            hx-swap="innerHTML">
        <i class="fa fa-eye"></i> <?= h(__('Preview')) ?>
    </button>
    <div class="js-editor-preview"></div>
</div>
