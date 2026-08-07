<?php
/**
 * The member's own second-factor settings.
 *
 * Three states in one page: off (offer to set it up), enrolling (QR code and a
 * confirmation field), on (status, fresh recovery codes, switch off).
 *
 * The QR code is rendered **here, on this server**, as inline SVG. Never a
 * third-party QR service: that would post the shared secret to somebody else,
 * and the content-security policy would block the image anyway.
 *
 * @var \App\View\AppView $this
 * @var bool $isEnabled
 * @var bool $isEnrolling
 * @var string $secret
 * @var string|null $provisioningUri
 * @var list<string>|null $recoveryCodes
 * @var string|null $errorMessage
 * @var int $remainingCodes
 * @var list<\App\Model\Entity\WebauthnCredential> $passkeys
 */
$webroot = $this->request->getAttribute('webroot');
$title = __('user.2fa.settings.t');
$this->set('titleForPage', $title);

$qrSvg = null;
if ($provisioningUri !== null) {
    $writer = new \Endroid\QrCode\Writer\SvgWriter();
    $qrSvg = $writer->write(new \Endroid\QrCode\QrCode($provisioningUri))->getString();
    // Drop the XML prolog: this is embedded in HTML, not served as a document.
    $qrSvg = (string)preg_replace('/^<\?xml[^>]*\?>\s*/', '', $qrSvg);
}
?>
<?= $this->element('layout/htmx_back') ?>
<div class="card panel-center">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body panel-content">
        <?php if ($errorMessage) : ?>
            <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($recoveryCodes) : ?>
            <?php // Shown once, and only once: they are stored as hashes. ?>
            <div class="alert alert-success" role="alert">
                <strong><?= h(__('user.2fa.codes.t')) ?></strong>
                <p><?= h(__('user.2fa.codes.exp')) ?></p>
                <ul class="two-factor-codes">
                    <?php foreach ($recoveryCodes as $code) : ?>
                        <li><code><?= h($code) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($isEnabled) : ?>
            <p><strong><?= h(__('user.2fa.state.on')) ?></strong></p>
            <p class="exp"><?= h(__n(
                '{0} recovery code left.',
                '{0} recovery codes left.',
                $remainingCodes,
                $remainingCodes,
            )) ?></p>

            <?php // Both of these ask for the password: one weakens the account,
                  // the other hands out new credentials. ?>
            <?= $this->Form->create(null, ['url' => $webroot . 'users/htmx-two-factor']) ?>
            <?= $this->Form->hidden('do', ['value' => 'newCodes']) ?>
            <?= $this->Form->control('password', [
                'type' => 'password', 'label' => __('user.2fa.password.confirm'),
                'required' => true, 'autocomplete' => 'current-password', 'class' => 'form-control',
            ]) ?>
            <?= $this->Form->button(h(__('user.2fa.codes.new')), ['class' => 'btn btn-secondary']) ?>
            <?= $this->Form->end() ?>

            <?php
            /**
             * Passkeys, offered only once the code is in place.
             *
             * Deliberately an addition rather than a way in: a passkey lives in
             * one machine's secure enclave, and a member whose only registered
             * device is lost needs the recovery codes that came with the code
             * — so the code has to exist first.
             */
            ?>
            <hr>
            <h4><?= h(__('user.2fa.passkey.t')) ?></h4>
            <p class="exp"><?= h(__('user.2fa.passkey.exp')) ?></p>

            <?php if ($passkeys) : ?>
                <ul class="passkey-list">
                    <?php foreach ($passkeys as $passkey) : ?>
                        <li>
                            <span class="passkey-label">
                                <?= h($passkey->get('label') ?: __('user.2fa.passkey.unnamed')) ?>
                            </span>
                            <span class="exp">
                                <?= h($this->TimeH->formatTime($passkey->get('created'), 'd.m.Y')) ?>
                            </span>
                            <?= $this->Form->create(null, [
                                'url' => $webroot . 'users/htmx-two-factor',
                                'style' => 'display:inline',
                            ]) ?>
                            <?= $this->Form->hidden('do', ['value' => 'removePasskey']) ?>
                            <?= $this->Form->hidden('credentialId', ['value' => $passkey->get('id')]) ?>
                            <?= $this->Form->button(h(__('user.2fa.passkey.remove')), [
                                'class' => 'btn btn-sm btn-secondary',
                            ]) ?>
                            <?= $this->Form->end() ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p><?= h(__('user.2fa.passkey.none.own')) ?></p>
            <?php endif; ?>

            <div id="js-passkeyStatus" class="alert" role="alert" hidden></div>
            <label class="form-label" for="js-passkeyLabel"><?= h(__('user.2fa.passkey.label')) ?></label>
            <input type="text" id="js-passkeyLabel" class="form-control" maxlength="100"
                   placeholder="<?= h(__('user.2fa.passkey.label.ph')) ?>">
            <button type="button"
                    class="btn btn-primary"
                    style="margin-top:0.5rem"
                    data-passkey="register"
                    data-options-url="<?= h($webroot . 'users/webauthn-register-options') ?>"
                    data-verify-url="<?= h($webroot . 'users/webauthn-register') ?>"
                    data-status="#js-passkeyStatus"
                    data-done="<?= h(__('user.2fa.passkey.added')) ?>"
                    data-failed="<?= h(__('user.2fa.passkey.failed')) ?>"
                    data-note="#js-passkeyUnsupported"
                    hidden>
                <?= h(__('user.2fa.passkey.add')) ?>
            </button>
            <p class="exp" id="js-passkeyUnsupported"><?= h(__('user.2fa.passkey.unsupported')) ?></p>

            <hr>
            <?= $this->Form->create(null, ['url' => $webroot . 'users/htmx-two-factor', 'style' => 'margin-top:1.5rem']) ?>
            <?= $this->Form->hidden('do', ['value' => 'disable']) ?>
            <?= $this->Form->control('password', [
                'type' => 'password', 'label' => __('user.2fa.password.confirm'),
                'required' => true, 'autocomplete' => 'current-password', 'class' => 'form-control',
            ]) ?>
            <?= $this->Form->button(h(__('user.2fa.disable')), ['class' => 'btn btn-danger']) ?>
            <?= $this->Form->end() ?>

        <?php elseif ($isEnrolling && $qrSvg !== null) : ?>
            <p><?= h(__('user.2fa.enrol.scan')) ?></p>
            <div class="two-factor-qr"><?= $qrSvg ?></div>
            <p class="exp">
                <?= h(__('user.2fa.enrol.manual')) ?>
                <code><?= h($secret) ?></code>
            </p>

            <?= $this->Form->create(null, ['url' => $webroot . 'users/htmx-two-factor']) ?>
            <?= $this->Form->hidden('do', ['value' => 'confirm']) ?>
            <?= $this->Form->control('code', [
                'label' => __('user.2fa.code'), 'required' => true, 'type' => 'text',
                'inputmode' => 'numeric', 'autocomplete' => 'one-time-code',
                'autofocus' => true, 'autocorrect' => 'off', 'autocapitalize' => 'off',
                'spellcheck' => 'false', 'class' => 'form-control',
            ]) ?>
            <?= $this->Form->button(h(__('user.2fa.enrol.confirm')), ['class' => 'btn btn-primary']) ?>
            <?= $this->Form->end() ?>

        <?php else : ?>
            <p><?= h(__('user.2fa.state.off')) ?></p>
            <p class="exp"><?= h(__('user.2fa.settings.exp')) ?></p>
            <?= $this->Form->create(null, ['url' => $webroot . 'users/htmx-two-factor']) ?>
            <?= $this->Form->hidden('do', ['value' => 'start']) ?>
            <?= $this->Form->button(h(__('user.2fa.enrol.start')), ['class' => 'btn btn-primary']) ?>
            <?= $this->Form->end() ?>
        <?php endif; ?>
    </div>
</div>
