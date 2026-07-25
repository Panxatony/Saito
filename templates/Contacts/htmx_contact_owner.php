<?php
/**
 * Contact-the-owner standalone island page (/contacts/htmx-contact-owner).
 * Public; anonymous senders get the email field + honeypot (like owner.php).
 *
 * @var \App\View\AppView $this
 * @var \Cake\Form\Form $contact
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
$webroot = $this->request->getAttribute('webroot');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">
<div class="user contact">
    <p class="mix-back" style="margin: 0 0 .75rem;">
        <a href="<?= $webroot ?>entries/htmx-index" class="btn btn-link" rel="nofollow">
            <?= $this->Layout->textWithIcon(h(__('Back')), 'arrow-left') ?>
        </a>
    </p>
    <div class="card panel-center">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('owner_contact_title')) ?>
        </div>
        <div class="card-body panel-form">
            <?php
            echo $this->Form->create($contact);
            if (!$CurrentUser->isLoggedIn()) {
                echo $this->Form->control('sender_contact', [
                    'class' => 'form-control',
                    'label' => __('user_contact_sender-contact'),
                    'required' => 'required',
                    'type' => 'email',
                ]);
                // Honeypot: hidden from users, bots fill it in.
                echo '<div aria-hidden="true" style="position:absolute;left:-9999px;visibility:hidden;">';
                echo $this->Form->control('website', ['label' => 'Website', 'tabindex' => -1, 'autocomplete' => 'off']);
                echo '</div>';
            }
            echo $this->element('contacts/contacts-core');
            echo $this->Form->end();
            ?>
        </div>
    </div>
</div>
