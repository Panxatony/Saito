<?php
/**
 * Login form fragment for the island login modal (and the no-JS login page).
 * Rendered by UsersController::login() when the request is an HX-Request: on
 * failure it swaps back into the modal with the flash error; on success login()
 * returns an HX-Redirect header instead. Posts to /login via htmx.
 *
 * @var \App\View\AppView $this
 */

$webroot = $this->request->getAttribute('webroot');

// Island flash (Saito's flash elements only feed JsData; emit them as alerts).
$this->Flash->render();
$flashClass = ['error' => 'danger', 'success' => 'success', 'warning' => 'warning', 'notice' => 'info'];
foreach ($this->JsData->notifications()->getAll() as $flashMsg) :
    $cls = $flashClass[$flashMsg['type']] ?? 'info';
    ?>
    <div class="alert alert-<?= h($cls) ?>" role="alert"><?= h($flashMsg['message']) ?></div>
    <?php
endforeach;

echo $this->Form->create(null, [
    'url' => $webroot . 'login',
    'type' => 'post',
    'hx-post' => $webroot . 'login',
    'hx-target' => '#js-loginModalBody',
    'hx-swap' => 'innerHTML',
]);
echo $this->Form->control('username', [
    'class' => 'form-control', 'label' => __('username_marking'), 'autofocus' => true,
]);
echo $this->Form->control('password', [
    'class' => 'form-control', 'type' => 'password', 'label' => __('Password'),
]);
echo $this->Html->div('form-check', $this->Form->control('remember_me', [
    'type' => 'checkbox', 'class' => 'form-check-input',
    'label' => ['text' => __('user.rememberMe.t'), 'class' => 'form-check-label'],
]));
echo $this->Form->button(__('login_btn'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo $this->Form->end();
?>
<p style="margin-top: 1rem; font-size: .9rem;">
    <a href="<?= $webroot ?>users/htmx-register"><?= h(__('register_linkname')) ?></a>
</p>
