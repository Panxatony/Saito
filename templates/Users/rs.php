<?php
$this->start('headerSubnavLeft');
echo $this->Layout->navbarBack();
$this->end();
?>
<div class="panel">
    <?=
    $this->Layout->panelHeading(
        __('register_linkname'),
        ['pageHeading' => true]
    ) ?>
    <div class="panel-content richtext">
        <?php if ($status === 'activated') : ?>
            <h2><?= __('register_confirm_success_title') ?></h2>
            <p><?= __('register_confirm_success_text') ?></p>
            <p><?= $this->Html->link(__('register_confirm_success_link'), '/') ?></p>
        <?php elseif ($status === 'already') : ?>
            <h2><?= __('register_confirm_already_title') ?></h2>
            <p><?= __('register_confirm_already_text') ?></p>
            <?php
        else : ?>
            <h2><?= __('register_confirm_failed_title') ?></h2>
            <p><?= __('register_confirm_failed_text') ?></p>
            <ul>
                <li><?= __('register_confirm_failed_url') ?></li>
                <li><?= __('register_confirm_failed_time') ?></li>
            </ul>
        <?php endif; ?>
    </div>
</div>
