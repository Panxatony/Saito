<?php
/**
 * Reset-password landing as an island page — reached from the emailed link
 * (/users/htmx-reset-password?token=…). Holds the body in `#js-resetBody` so
 * the form's htmx swap has something to replace; the no-JS path posts the same
 * form and re-renders this page.
 *
 * @var \App\View\AppView $this
 * @var string $status
 * @var string $token
 * @var string|null $errorMessage
 */
?>
<div class="users resetPassword">
    <div class="card panel-form panel-center" style="max-width: 26rem; margin: 2rem auto;">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('user.pwreset.form.title')) ?>
        </div>
        <div class="card-body">
            <div id="js-resetBody">
                <?= $this->element('user/reset_password_body', [
                    'status' => $status,
                    'token' => $token,
                    'errorMessage' => $errorMessage,
                ]) ?>
            </div>
        </div>
    </div>
</div>
