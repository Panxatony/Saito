<?php
/**
 * Forgot-password form/status fragment for the shared auth modal. Rendered by
 * UsersController::htmxForgotPassword() on an HX-Request; posts to itself and
 * swaps the result back into the modal body.
 *
 * The reply is deliberately the same whether or not the address is a member's:
 * the `sent` status only ever means "if it is one of ours, a link is on its
 * way".
 *
 * @var \App\View\AppView $this
 * @var string $status 'view' | 'sent'
 */

$webroot = $this->request->getAttribute('webroot');

$this->Flash->render();
$flashClass = ['error' => 'danger', 'success' => 'success', 'warning' => 'warning', 'notice' => 'info'];
foreach ($this->JsData->notifications()->getAll() as $flashMsg) :
    $cls = $flashClass[$flashMsg['type']] ?? 'info';
    ?>
    <div class="alert alert-<?= h($cls) ?>" role="alert"><?= h($flashMsg['message']) ?></div>
    <?php
endforeach;
?>
<?php if ($status === 'sent') : ?>
    <h3 style="margin: 0 0 .5rem; font-size: 1.1rem;"><?= h(__('user.pwreset.request.sent.title')) ?></h3>
    <p><?= __('user.pwreset.request.sent.text') ?></p>
<?php else : ?>
    <h3 style="margin: 0 0 .75rem; font-size: 1.1rem;"><?= h(__('user.pwreset.request.title')) ?></h3>
    <p><?= __('user.pwreset.request.intro') ?></p>
    <?php
    echo $this->Form->create(null, [
        'url' => ['action' => 'htmxForgotPassword'],
        'hx-post' => $webroot . 'users/htmx-forgot-password',
        'hx-target' => '#js-loginModalBody',
        'hx-swap' => 'innerHTML',
    ]);
    echo $this->Form->control('user_email', [
        'class' => 'form-control', 'type' => 'email', 'autocomplete' => 'email',
        'label' => __('register_user_email'), 'autofocus' => true,
    ]);
    echo $this->Form->button(__('user.pwreset.request.submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo $this->Form->end();
    ?>
<?php endif; ?>
