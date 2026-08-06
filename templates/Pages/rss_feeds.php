<?php
// The island layout renders no header subnav, so this page carries its own
// standalone back link instead — the same modern pattern impressum/privacy use.
// The old `headerSubnavLeft` block it wrote to is fetched by nobody in the
// island layout, so the back link was invisible here.
$title = __('s.rss.t');
$this->set('titleForPage', $title);
?>
<?= $this->element('layout/htmx_back') ?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content richtext">
        <?= $this->cell('Feeds.FeedLinks', [$CurrentUser]) ?>
    </div>
</div>
