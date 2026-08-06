<?php
/**
 * Somebody tried to register with an address that already has an account.
 *
 * This mail exists so the registration form can stop answering the question
 * "does this address have an account here?". The form now says the same thing
 * whether the address is new or known — and the difference is moved here, where
 * only the person who owns the address can read it.
 *
 * Two kinds of reader, and the text has to serve both without alarming either:
 * the member who forgot they had signed up, and the member whose address
 * somebody else typed in. Neither has to do anything, and saying so is the
 * point of the mail.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var string $forumName
 */
echo __(
    'register_email_existing_content',
    [
        $forumName,
        $user->get('username'),
        $this->Url->build(
            ['controller' => 'users', 'action' => 'htmxLogin'],
            ['fullBase' => true],
        ),
    ],
);
echo $this->element('email/text/footer');
