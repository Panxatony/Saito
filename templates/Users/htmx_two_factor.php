<?php
/**
 * The second-factor step as a standalone page.
 *
 * What a member without JavaScript gets, and what a direct visit to
 * /users/two-factor serves. The overlay uses the same form through
 * htmx_two_factor_form.
 *
 * @var \App\View\AppView $this
 * @var string|null $errorMessage
 */
$title = __('user.2fa.challenge.t');
$this->set('titleForPage', $title);
?>
<div class="card panel-center" style="max-width: 24rem; margin: 2rem auto;">
    <div class="card-header">
        <?= $this->Layout->panelHeading($title, ['pageHeading' => true]) ?>
    </div>
    <div class="card-body">
        <?= $this->element('users/two_factor_form', ['errorMessage' => $errorMessage ?? null]) ?>
    </div>
</div>
