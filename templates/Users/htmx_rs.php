<?php
/**
 * Account activation landing as an htmx island page (strangler-fig PoC).
 * Reached from the confirmation email link (/users/rs/<id>?c=<code>).
 *
 * @var \App\View\AppView $this
 * @var string $status
 */

$webroot = $this->request->getAttribute('webroot');
?>
<div class="users rs">
    <div class="card panel-center" style="max-width: 32rem; margin: 2rem auto;">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('register_linkname')) ?>
        </div>
        <div class="card-body richtext">
            <?php if ($status === 'activated') : ?>
                <h2><?= h(__('register_confirm_success_title')) ?></h2>
                <p><?= __('register_confirm_success_text') ?></p>
                <p><a href="<?= $webroot ?>users/htmx-login"><?= h(__('login_btn')) ?></a></p>
            <?php elseif ($status === 'already') : ?>
                <h2><?= h(__('register_confirm_already_title')) ?></h2>
                <p><?= __('register_confirm_already_text') ?></p>
            <?php else : ?>
                <h2><?= h(__('register_confirm_failed_title')) ?></h2>
                <p><?= __('register_confirm_failed_text') ?></p>
                <ul>
                    <li><?= __('register_confirm_failed_url') ?></li>
                    <li><?= __('register_confirm_failed_time') ?></li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
