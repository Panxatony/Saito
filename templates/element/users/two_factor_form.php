<?php
/**
 * The second-factor form, shared by the login overlay and the standalone page.
 *
 * In the overlay it replaces the password form in place — the member stays in
 * the modal they opened, which is what a second *step* should feel like rather
 * than a second destination. Posting swaps this same fragment back on a wrong
 * code, and a correct one answers with HX-Redirect instead.
 *
 * Nothing here names the account. The page is served before any identity
 * exists, and printing the username would turn a stolen password into a
 * confirmed username for free.
 *
 * @var \App\View\AppView $this
 * @var string|null $errorMessage
 */
$webroot = $this->request->getAttribute('webroot');
$errorMessage = $errorMessage ?? null;
?>
<?php if ($errorMessage) : ?>
    <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
<?php endif; ?>

<p><?= h(__('user.2fa.challenge.exp')) ?></p>

<?php
/**
 * The passkey path, offered first because it is one press against six digits.
 *
 * Hidden by default and revealed by the island only once the browser has
 * confirmed a platform authenticator actually exists — a button that cannot
 * work is worse than no button. Everything below it stays exactly where it was:
 * without JavaScript, without a sensor, or with the prompt dismissed, the code
 * field is still the way through.
 */
?>
<div id="js-passkeyLoginStatus" class="alert" role="alert" hidden></div>
<button type="button"
        class="btn btn-primary"
        data-passkey="login"
        data-options-url="<?= h($webroot . 'users/webauthn-login-options') ?>"
        data-verify-url="<?= h($webroot . 'users/webauthn-login') ?>"
        data-status="#js-passkeyLoginStatus"
        data-failed="<?= h(__('user.2fa.passkey.failed')) ?>"
        hidden>
    <?= h(__('user.2fa.passkey.use')) ?>
</button>

<?php
echo $this->Form->create(null, [
    'url' => $webroot . 'users/two-factor',
    'type' => 'post',
    'hx-post' => $webroot . 'users/two-factor',
    'hx-target' => '#js-loginModalBody',
    'hx-swap' => 'innerHTML',
]);
echo $this->Form->control('code', [
    'label' => __('user.2fa.code'),
    'required' => true,
    // `text` with a numeric keypad, not `number`: a recovery code goes in this
    // same field and is not a number. `one-time-code` is what lets a phone
    // offer the code from the notification.
    'type' => 'text',
    'inputmode' => 'numeric',
    'autocomplete' => 'one-time-code',
    'autofocus' => true,
    'autocorrect' => 'off',
    'autocapitalize' => 'off',
    'spellcheck' => 'false',
    'class' => 'form-control',
]);
echo $this->Form->button(h(__('user.2fa.submit')), ['class' => 'btn btn-primary']);
echo $this->Form->end();
?>
<p class="exp" style="margin-top: 1rem;"><?= h(__('user.2fa.recovery.hint')) ?></p>
