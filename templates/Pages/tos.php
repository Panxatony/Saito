<?php
use Cake\Core\Configure;

// The island layout renders no header subnav, so this page carries its own
// standalone back link instead.

$title = __('register_tos_linktext');
$this->set('titleForPage', $title);

// Terms-of-service content is environment-specific and configured as trusted
// HTML in config/saito_config.php under 'Saito.tos' — a forum's terms name its
// operator and its jurisdiction, so they cannot be shipped. See
// docs/terms-of-service-template.md for a German starting point.
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
            <p><?= h(__('No terms of service have been configured for this installation.')) ?></p>
        <?php endif; ?>
    </div>
</div>
