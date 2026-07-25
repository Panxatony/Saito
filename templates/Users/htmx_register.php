<?php
/**
 * Island-styled registration page (strangler-fig PoC). Posts to htmxRegister
 * (same honeypot/TOS/register flow as register()); Alpine enables the submit
 * once TOS is accepted.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var string $status
 * @var bool $tosRequired
 */

use Cake\Core\Configure;

$webroot = $this->request->getAttribute('webroot');
?>
<div class="users register">
    <div class="card panel-form panel-center" style="max-width: 26rem; margin: 2rem auto;">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('register_linkname')) ?>
        </div>
        <div class="card-body">
            <?php if ($status === 'success') : ?>
                <h3><?= h(__('register_success_title')) ?></h3>
                <p><?= __('register_success_text') ?></p>
                <p><?= __('register_success_login_note') ?></p>
                <p><a href="<?= $webroot ?>users/htmx-login"><?= h(__('login_btn')) ?></a></p>
            <?php elseif ($status === 'fail: email') : ?>
                <h3><?= h(__('register_fail_email_title')) ?></h3>
                <p><?= __('register_fail_email_text') ?></p>
            <?php else : ?>
                <div x-data="{ tos: <?= $tosRequired ? 'false' : 'true' ?> }">
                    <?php
                    echo $this->Form->create($user, ['url' => ['action' => 'htmxRegister'], 'id' => 'registerForm']);
                    echo $this->Form->control('username', [
                        'class' => 'form-control', 'autocomplete' => 'username', 'label' => __('register_user_name'),
                    ]);
                    echo $this->Form->control('user_email', [
                        'class' => 'form-control', 'autocomplete' => 'email', 'label' => __('register_user_email'),
                    ]);
                    echo $this->Form->control('password', [
                        'class' => 'form-control', 'type' => 'password', 'autocomplete' => 'new-password', 'label' => __('password'),
                    ]);
                    echo $this->Form->control('password_confirm', [
                        'class' => 'form-control', 'type' => 'password', 'autocomplete' => 'new-password', 'label' => __('register_password_confirm'),
                    ]);

                    // Honeypot — invisible to users, bots fill it in.
                    echo '<div aria-hidden="true" style="position:absolute;left:-9999px;visibility:hidden;">';
                    echo $this->Form->control('url', ['label' => 'Website', 'tabindex' => -1, 'autocomplete' => 'off']);
                    echo '</div>';

                    if ($tosRequired) {
                        $tosUrl = Configure::read('Saito.Settings.tos_url')
                            ?: '/pages/' . Configure::read('Saito.language') . '/tos';
                        echo $this->Html->div('form-group form-check', $this->Form->control('tos_confirm', [
                            'type' => 'checkbox',
                            'x-model' => 'tos',
                            'label' => [
                                'text' => __(
                                    'register_tos_label',
                                    $this->Html->link(__('register_tos_linktext'), $tosUrl, ['target' => '_blank'])
                                ),
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
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
echo $this->Html->script('htmx-threads.bundle.js');
