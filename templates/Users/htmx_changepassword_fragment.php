<?php
/**
 * Change-own-password form fragment for the settings overlay. Posts via htmx to
 * htmxChangePassword; a validation error re-renders this fragment inside the
 * modal, success sends an HX-Redirect back to the settings page.
 *
 * @var \App\View\AppView $this
 * @var string $username
 * @var string|null $errorMessage
 */

$postUrl = $this->Url->build(['controller' => 'Users', 'action' => 'htmxChangePassword']);
?>
<h3 style="margin: 0 0 .75rem; font-size: 1.1rem;">
    <i class="fa fa-key"></i>&nbsp;<?= h(__('change_password_link')) ?>
</h3>
<?php
if ($errorMessage !== null) {
    echo '<div class="alert alert-danger" role="alert">' . h($errorMessage) . '</div>';
}

echo $this->Form->create(null, [
    'url' => ['controller' => 'Users', 'action' => 'htmxChangePassword'],
    'type' => 'post',
    'hx-post' => $postUrl,
    'hx-target' => '#js-passwordModalBody',
    'hx-swap' => 'innerHTML',
]);
// Hidden username so password managers can associate the account.
echo $this->Form->control('username', ['type' => 'hidden', 'value' => $username, 'autocomplete' => 'username']);
echo $this->Form->control('password_old', [
    'class' => 'form-control', 'type' => 'password',
    'autocomplete' => 'current-password', 'label' => __('change_password_old_password'),
]);
echo $this->Form->control('password', [
    'class' => 'form-control', 'type' => 'password',
    'autocomplete' => 'new-password', 'label' => __('change_password_new_password'),
]);
echo $this->Form->control('password_confirm', [
    'class' => 'form-control', 'type' => 'password',
    'autocomplete' => 'new-password', 'label' => __('change_password_new_password_confirm'),
]);
echo $this->Form->button(__('change_password_btn_submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo $this->Form->end();
