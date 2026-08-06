<?php
/**
 * Step two of a login: the code from the authenticator app.
 *
 * Reached only with a pending account in the session — see
 * UsersController::twoFactor(). Nothing here identifies the account: the page
 * is served before any identity exists, and naming the member on it would turn
 * a stolen password into a confirmed username for free.
 *
 * @var \App\View\AppView $this
 * @var string|null $errorMessage
 */
$title = __('user.2fa.challenge.t');
$this->set('titleForPage', $title);
?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content">
        <?php if ($errorMessage) : ?>
            <p class="alert alert-danger"><?= h($errorMessage) ?></p>
        <?php endif; ?>

        <p><?= h(__('user.2fa.challenge.exp')) ?></p>

        <?= $this->Form->create(null, ['url' => ['controller' => 'Users', 'action' => 'twoFactor']]) ?>
        <?= $this->Form->control('code', [
            'label' => __('user.2fa.code'),
            'required' => true,
            // A numeric soft keyboard on a phone, and the browser's one-time-code
            // autofill where the platform offers it — but `text`, not `number`:
            // a recovery code goes in this same field and is not a number.
            'type' => 'text',
            'inputmode' => 'numeric',
            'autocomplete' => 'one-time-code',
            'autofocus' => true,
            'autocorrect' => 'off',
            'autocapitalize' => 'off',
            'spellcheck' => 'false',
            'class' => 'form-control',
        ]) ?>
        <?= $this->Form->button(h(__('user.2fa.submit')), ['class' => 'btn btn-primary']) ?>
        <?= $this->Form->end() ?>

        <p class="exp"><?= h(__('user.2fa.recovery.hint')) ?></p>
    </div>
</div>
