<?php
use Cake\Core\Configure;

// The island layout renders no header subnav, so this page carries its own
// standalone back link instead.

$title = __('privacy.t');
$this->set('titleForPage', $title);

// What a given installation has to declare depends on its hosting, analytics
// and admin settings, so the text is trusted HTML configured per installation
// under 'Saito.privacy'. See docs/privacy-policy-template.md for what Saito
// itself processes.
$privacy = (string)Configure::read('Saito.privacy');
?>
<?= $this->element('layout/htmx_back') ?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content richtext">
        <?php if ($privacy !== ''): ?>
            <?= $privacy ?>
        <?php else: ?>
            <p><?= h(__('privacy.none')) ?></p>
        <?php endif; ?>
    </div>
</div>
