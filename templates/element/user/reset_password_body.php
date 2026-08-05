<?php
/**
 * The body of the reset-password landing: either "this link is no good" or the
 * new-password form. Shared by the standalone page and the HX-Request fragment
 * so the two cannot drift.
 *
 * The form posts via htmx and swaps back into `#js-resetBody` (with a plain
 * action as the no-JS fallback); on success the controller answers with an
 * HX-Redirect to the login page.
 *
 * @var \App\View\AppView $this
 * @var string $status 'invalid' | 'form'
 * @var string $token
 * @var string|null $errorMessage
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
<?php if (($status ?? '') === 'invalid') : ?>
    <h3><?= h(__('user.pwreset.invalid.title')) ?></h3>
    <p><?= __('user.pwreset.invalid.text') ?></p>
    <p><a href="<?= $webroot ?>users/htmx-forgot-password"><?= h(__('user.pwreset.request.again')) ?></a></p>
<?php else : ?>
    <?php if (!empty($errorMessage)) : ?>
        <div class="alert alert-danger" role="alert"><?= h($errorMessage) ?></div>
    <?php endif; ?>
    <p><?= __('user.pwreset.form.intro') ?></p>
    <?php
    echo $this->Form->create(null, [
        'url' => ['action' => 'htmxResetPassword'],
        'hx-post' => $webroot . 'users/htmx-reset-password',
        'hx-target' => '#js-resetBody',
        'hx-swap' => 'innerHTML',
    ]);
    echo $this->Form->hidden('token', ['value' => $token]);
    echo $this->Form->control('password', [
        'class' => 'form-control', 'type' => 'password', 'autocomplete' => 'new-password',
        'label' => __('Password'), 'autofocus' => true,
    ]);
    echo $this->Form->control('password_confirm', [
        'class' => 'form-control', 'type' => 'password', 'autocomplete' => 'new-password',
        'label' => __('register_password_confirm'),
    ]);
    echo $this->Form->button(__('user.pwreset.form.submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo $this->Form->end();
    ?>
<?php endif; ?>
