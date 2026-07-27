<?php
use Cake\Core\Configure;

// The island layout renders no header subnav, so navbarBack() would vanish
// there — it gets the standalone back link instead.
$isIsland = Configure::read('Saito.frontend') === 'island';
if (!$isIsland) {
    $this->start('headerSubnavLeft');
    echo $this->Layout->navbarBack();
    $this->end();
}

$title = __('privacy.t');
$this->set('titleForPage', $title);

// What a given installation has to declare depends on its hosting, analytics
// and admin settings, so the text is trusted HTML configured per installation
// under 'Saito.privacy'. See docs/privacy-policy-template.md for what Saito
// itself processes.
$privacy = (string)Configure::read('Saito.privacy');
?>
<?php if ($isIsland) : ?>
    <?= $this->element('layout/htmx_back') ?>
<?php endif; ?>
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
