<?php
use Cake\Core\Configure;

// The island layout renders no header subnav, so this page carries its own
// standalone back link instead.

$title = __('register_tos_linktext');
$this->set('titleForPage', $title);

// A forum sets its own terms as trusted HTML in config/saito_config.php under
// 'Saito.tos' — it names the operator and the jurisdiction, so it is
// per-installation. Left empty, the shipped German default (element
// pages/tos_default) renders instead, with the forum name filled in and the
// operator taken from the imprint; docs/terms-of-service-template.md is the same
// text to start from when writing custom terms.
$tos = (string)Configure::read('Saito.tos');
?>
<?= $this->element('layout/htmx_back') ?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content richtext">
        <?php if ($tos !== ''): ?>
            <?= $tos ?>
        <?php else: ?>
            <?= $this->element('pages/tos_default') ?>
        <?php endif; ?>
    </div>
</div>
