<?php
/**
 * Island-styled forgot-password page — the no-JS / direct-visit counterpart of
 * the modal fragment. Posts to htmxForgotPassword with a plain form.
 *
 * @var \App\View\AppView $this
 * @var string $status 'view' | 'sent'
 */

$webroot = $this->request->getAttribute('webroot');
?>
<div class="users forgotPassword">
    <div class="card panel-form panel-center" style="max-width: 26rem; margin: 2rem auto;">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('user.pwreset.request.title')) ?>
        </div>
        <div class="card-body">
            <?php if ($status === 'sent') : ?>
                <h3><?= h(__('user.pwreset.request.sent.title')) ?></h3>
                <p><?= __('user.pwreset.request.sent.text') ?></p>
                <p><a href="<?= $webroot ?>users/htmx-login"><?= h(__('login_btn')) ?></a></p>
            <?php else : ?>
                <p><?= __('user.pwreset.request.intro') ?></p>
                <?php
                echo $this->Form->create(null, ['url' => ['action' => 'htmxForgotPassword']]);
                echo $this->Form->control('user_email', [
                    'class' => 'form-control', 'type' => 'email', 'autocomplete' => 'email',
                    'label' => __('register_user_email'), 'autofocus' => true,
                ]);
                echo $this->Form->button(__('user.pwreset.request.submit'), [
                    'type' => 'submit', 'class' => 'btn btn-primary',
                ]);
                echo $this->Form->end();
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>
