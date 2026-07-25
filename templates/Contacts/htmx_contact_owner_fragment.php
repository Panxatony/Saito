<?php
/**
 * Contact-the-owner form fragment for the footer overlay. Posts via htmx to
 * htmxContactOwner; a validation error re-renders this fragment in the modal,
 * success sends an HX-Redirect to '/'. Anonymous senders get the email field +
 * honeypot (like the standalone page).
 *
 * @var \App\View\AppView $this
 * @var \Cake\Form\Form $contact
 */

$postUrl = $this->Url->build(['controller' => 'Contacts', 'action' => 'htmxContactOwner']);
?>
<h3 style="margin: 0 0 .75rem; font-size: 1.1rem;">
    <i class="fa fa-envelope"></i>&nbsp;<?= h(__('owner_contact_title')) ?>
</h3>
<?php
echo $this->Form->create($contact, [
    'hx-post' => $postUrl,
    'hx-target' => '#js-contactModalBody',
    'hx-swap' => 'innerHTML',
]);
if (!$CurrentUser->isLoggedIn()) {
    echo $this->Form->control('sender_contact', [
        'class' => 'form-control',
        'label' => __('user_contact_sender-contact'),
        'required' => 'required',
        'type' => 'email',
    ]);
    echo '<div aria-hidden="true" style="position:absolute;left:-9999px;visibility:hidden;">';
    echo $this->Form->control('website', ['label' => 'Website', 'tabindex' => -1, 'autocomplete' => 'off']);
    echo '</div>';
}
echo $this->element('contacts/contacts-core');
echo $this->Form->end();
?>
