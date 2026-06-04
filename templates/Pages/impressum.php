<?php
use Cake\Core\Configure;

$this->start('headerSubnavLeft');
echo $this->Layout->navbarBack();
$this->end();

$title = __('Impressum');
$this->set('titleForPage', $title);

// Imprint content is environment-specific and configured as trusted HTML
// in config/saito_config.php under 'Saito.imprint'.
$imprint = (string)Configure::read('Saito.imprint');
?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content richtext">
        <?php if ($imprint !== ''): ?>
            <?= $imprint ?>
        <?php else: ?>
            <p><?= h(__('No imprint has been configured for this installation.')) ?></p>
        <?php endif; ?>
    </div>
</div>
