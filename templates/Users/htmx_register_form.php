<?php
/**
 * Register form/status fragment for the shared auth modal (and the no-JS
 * register page's HX fallback). Rendered by UsersController::htmxRegister() on
 * an HX-Request; posts to itself via htmx and swaps the result (form with
 * errors, or the success/fail status) back into the modal body.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var string $status
 * @var bool $tosRequired
 */

use Cake\Core\Configure;

$webroot = $this->request->getAttribute('webroot');
?>
<?php if ($status === 'success') : ?>
    <h3 style="margin: 0 0 .5rem; font-size: 1.1rem;"><?= h(__('register_success_title')) ?></h3>
    <p><?= __('register_success_text') ?></p>
    <p><?= __('register_success_login_note') ?></p>
    <p><a href="<?= $webroot ?>login" class="js-authModalOpen" data-modal-url="<?= $webroot ?>login">
        <?= h(__('login_btn')) ?></a></p>
<?php elseif ($status === 'fail: email') : ?>
    <h3 style="margin: 0 0 .5rem; font-size: 1.1rem;"><?= h(__('register_fail_email_title')) ?></h3>
    <p><?= __('register_fail_email_text') ?></p>
<?php else : ?>
    <h3 style="margin: 0 0 .75rem; font-size: 1.1rem;"><?= h(__('register_linkname')) ?></h3>
    <div x-data="{ tos: <?= $tosRequired ? 'false' : 'true' ?> }">
        <?php
        echo $this->Form->create($user, [
            'url' => ['action' => 'htmxRegister'],
            'id' => 'registerForm',
            'hx-post' => $webroot . 'users/htmx-register',
            'hx-target' => '#js-loginModalBody',
            'hx-swap' => 'innerHTML',
        ]);
        echo $this->Form->control('username', [
            'class' => 'form-control', 'autocomplete' => 'username', 'label' => __('register_user_name'),
        ]);
        echo $this->Form->control('user_email', [
            'class' => 'form-control', 'autocomplete' => 'email', 'label' => __('register_user_email'),
        ]);
        echo $this->Form->control('password', [
            'class' => 'form-control', 'type' => 'password', 'autocomplete' => 'new-password', 'label' => __('Password'),
        ]);
        echo $this->Form->control('password_confirm', [
            'class' => 'form-control', 'type' => 'password',
            'autocomplete' => 'new-password', 'label' => __('register_password_confirm'),
        ]);

        // Honeypot — invisible to users, bots fill it in.
        echo '<div aria-hidden="true" style="position:absolute;left:-9999px;visibility:hidden;">';
        echo $this->Form->control('url', ['label' => 'Website', 'tabindex' => -1, 'autocomplete' => 'off']);
        echo '</div>';

        if ($tosRequired) {
            $tosUrl = Configure::read('Saito.Settings.tos_url')
                ?: '/pages/tos';
            echo $this->Html->div('form-check', $this->Form->control('tos_confirm', [
                'type' => 'checkbox', 'class' => 'form-check-input', 'x-model' => 'tos',
                'label' => [
                    'text' => __(
                        'register_tos_label',
                        $this->Html->link(__('register_tos_linktext'), $tosUrl, ['target' => '_blank'])
                    ),
                    'class' => 'form-check-label',
                    'escape' => false,
                ],
            ]));
        }

        echo $this->Form->button(__('register_linkname'), [
            'type' => 'submit', 'class' => 'btn btn-primary', ':disabled' => '!tos',
        ]);
        echo $this->Form->end();
        ?>
    </div>
    <p style="margin-top: 1rem; font-size: .9rem;">
        <a href="<?= $webroot ?>login" class="js-authModalOpen" data-modal-url="<?= $webroot ?>login">
            <?= h(__('login_btn')) ?></a>
    </p>
<?php endif; ?>
