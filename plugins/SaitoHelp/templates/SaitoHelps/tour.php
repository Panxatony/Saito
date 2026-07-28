<?php
/**
 * The help overlay's content: the guided tour, and a way on to the help page.
 *
 * The individual topics are not repeated here — they live on /help, which
 * presents them properly. The overlay is the quick orientation.
 *
 * @var \App\View\AppView $this
 * @var bool $hasTopics whether there are any topics to link on to
 */
?>
<?= $this->element('layout/htmx_help') ?>

<?php if ($hasTopics) : ?>
    <p class="island-help-more">
        <a href="<?= $this->Url->build('/help') ?>">
            <?= h(__d('saito_help', 'overlay.topics')) ?>
        </a>
    </p>
<?php endif; ?>
