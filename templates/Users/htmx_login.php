<?php
/**
 * Island-styled login page (strangler-fig PoC).
 *
 * Reachable at /users/htmx-login. A clean standalone form that posts to the real
 * /login action (its authentication logic is untouched — login() unloads
 * FormProtection, so only the CSRF token FormHelper adds is needed).
 *
 * @var \App\View\AppView $this
 */

$webroot = $this->request->getAttribute('webroot');
?>
<div class="users login">
    <div class="card panel-form panel-center" style="max-width: 24rem; margin: 2rem auto;">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('login_btn')) ?>
        </div>
        <div class="card-body">
            <?php
            echo $this->Form->create(null, ['url' => $webroot . 'login', 'type' => 'post']);
            echo $this->Form->control('username', [
                'class' => 'form-control',
                'label' => __('username_marking'),
                'autofocus' => true,
            ]);
            echo $this->Form->control('password', [
                'class' => 'form-control',
                'type' => 'password',
                'label' => __('password'),
            ]);
            echo $this->Form->control('remember_me', [
                'type' => 'checkbox',
                'label' => __('user.rememberMe.t'),
            ]);
            echo $this->Form->button(__('login_btn'), [
                'type' => 'submit',
                'class' => 'btn btn-primary',
            ]);
            echo $this->Form->end();
            ?>
            <p style="margin-top: 1rem; font-size: .9rem;">
                <a href="<?= $webroot ?>users/htmx-register"><?= h(__('register_linkname')) ?></a>
                &middot;
                <a href="<?= $webroot ?>users/password_forgotten"><?= h(__('user.pwf.t')) ?></a>
            </p>
        </div>
    </div>
</div>

<?php
echo $this->Html->script('htmx-threads.bundle.js');
