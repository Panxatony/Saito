<?php
/**
 * Password-reset email. Sent by UsersController::htmxForgotPassword() when a
 * request names an address that belongs to an activated account.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var string $resetUrl the single-use reset link (carries the token)
 * @var string $forumName
 */
echo __(
    'user.pwreset.email.content',
    [
        $forumName,
        $resetUrl,
    ]
);
echo $this->element('email/text/footer');
