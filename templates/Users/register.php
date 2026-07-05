<?php
$this->start('headerSubnavLeft');
echo $this->Layout->navbarBack();
$this->end();

$css = ($status === 'view') ? 'panel-form' : '';
?>
<div class="card panel-center">
    <div class="card-header">
        <?=
        $this->Layout->panelHeading(
            __('register_linkname'),
            ['pageHeading' => true]
        ) ?>
    </div>
    <div class="card-body richtext <?= $css ?>">
        <?php
        if ($status === 'view') {
            echo $this->element('users/register-form');
        } elseif ($status === 'fail: email') { ?>
            <h1><?= __('register_fail_email_title') ?></h1>
            <p><?= __('register_fail_email_text') ?></p>
        <?php } elseif ($status === 'success') { ?>
            <h1><?= __('register_success_title') ?></h1>
            <p><?= __('register_success_text') ?></p>
            <p><?= __('register_success_login_note') ?></p>
        <?php } ?>
    </div>
</div>
