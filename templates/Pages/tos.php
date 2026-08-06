<?php
// The island layout renders no header subnav, so this page carries its own
// standalone back link instead.
//
// The terms text itself comes from the pages/tos_body element: a forum's own
// `Saito.tos`, or the shipped default. See that element and
// docs/terms-of-service-template.md.

$title = __('register_tos_linktext');
$this->set('titleForPage', $title);
?>
<?= $this->element('layout/htmx_back') ?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content richtext">
        <?= $this->element('pages/tos_body') ?>
    </div>
</div>
