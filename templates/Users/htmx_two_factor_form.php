<?php
/**
 * The second-factor step as an overlay fragment.
 *
 * Rendered by UsersController::login() when a password checks out and the
 * account owes a second factor, and again by twoFactor() on a wrong code — both
 * swap into #js-loginModalBody, so the member never leaves the login overlay.
 *
 * @var \App\View\AppView $this
 * @var string|null $errorMessage
 */
echo $this->element('users/two_factor_form', ['errorMessage' => $errorMessage ?? null]);
