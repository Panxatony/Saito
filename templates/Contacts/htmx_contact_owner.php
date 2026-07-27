<?php
/**
 * Contact-the-owner standalone island page (/contacts/htmx-contact-owner).
 * Public; anonymous senders get the email field + honeypot (like owner.php).
 *
 * @var \App\View\AppView $this
 * @var \Cake\Form\Form $contact
 */

$webroot = $this->request->getAttribute('webroot');
?>
<div class="user contact">
    <?= $this->element('layout/htmx_back') ?>
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
