<?php
/**
 * Contact-a-member standalone island page (/contacts/htmx-contact-user/<id>).
 * Reuses the shared contacts-core fields; on success _contact() redirects to /.
 *
 * @var \App\View\AppView $this
 * @var \Cake\Form\Form $contact
 * @var \App\Model\Entity\User $user
 */

$webroot = $this->request->getAttribute('webroot');
?>
<div class="user contact">
    <?= $this->element('layout/htmx_back', ['url' => $webroot . 'users/htmx-profile/' . (int)$user->get('id'), 'label' => __('Back')]) ?>
    <div class="card panel-center">
        <div class="card-header">
            <?= $this->Layout->panelHeading($this->get('titleForPage')) ?>
        </div>
        <div class="card-body panel-form">
            <?php
            echo $this->Form->create($contact);
            echo $this->element('contacts/contacts-core');
            echo $this->Form->end();
            ?>
        </div>
    </div>
</div>
