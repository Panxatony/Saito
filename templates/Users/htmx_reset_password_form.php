<?php
/**
 * HX-Request fragment for the reset-password landing: just the body, swapped
 * back into `#js-resetBody`. See the shared element for the markup.
 *
 * @var \App\View\AppView $this
 * @var string $status
 * @var string $token
 * @var string|null $errorMessage
 */
echo $this->element('user/reset_password_body', [
    'status' => $status,
    'token' => $token,
    'errorMessage' => $errorMessage,
]);
