<?php
/**
 * Contact-a-member form fragment for the profile overlay. Posts via htmx to
 * htmxContactUser; a validation error re-renders this fragment in the modal,
 * success sends an HX-Redirect. Reuses the generic contact overlay from the
 * layout, so it needs no markup of its own beyond the form.
 *
 * @var \App\View\AppView $this
 * @var \Cake\Form\Form $contact
 * @var \App\Model\Entity\User $user
 */

$postUrl = $this->Url->build([
    'controller' => 'Contacts',
    'action' => 'htmxContactUser',
    (int)$user->get('id'),
]);
?>
<h3 style="margin: 0 0 .75rem; font-size: 1.1rem;">
    <i class="fa fa-envelope"></i>&nbsp;<?= h($this->get('titleForPage')) ?>
</h3>
<?php
echo $this->Form->create($contact, [
    'hx-post' => $postUrl,
    'hx-target' => '#js-contactModalBody',
    'hx-swap' => 'innerHTML',
]);
echo $this->element('contacts/contacts-core');
echo $this->Form->end();
