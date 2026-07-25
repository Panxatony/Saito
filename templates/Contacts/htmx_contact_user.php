<?php
/**
 * Contact-a-member standalone island page (/contacts/htmx-contact-user/<id>).
 * Reuses the shared contacts-core fields; on success _contact() redirects to /.
 *
 * @var \App\View\AppView $this
 * @var \Cake\Form\Form $contact
 * @var \App\Model\Entity\User $user
 */

$csrfToken = $this->getRequest()->getAttribute('csrfToken');
$webroot = $this->request->getAttribute('webroot');
?>
<meta name="csrf-token" content="<?= h($csrfToken) ?>">
<div class="user contact">
    <p class="mix-back" style="margin: 0 0 .75rem;">
        <a href="<?= $webroot ?>users/htmx-profile/<?= (int)$user->get('id') ?>" class="btn btn-link" rel="nofollow">
            <?= $this->Layout->textWithIcon(h(__('Back')), 'arrow-left') ?>
        </a>
    </p>
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
