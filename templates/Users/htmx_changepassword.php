<?php
/**
 * Change own password as an htmx island page (strangler-fig PoC).
 *
 * @var \App\View\AppView $this
 * @var string $username
 */
?>
<div class="users changepassword">
    <div class="card panel-form panel-center" style="max-width: 26rem; margin: 2rem auto;">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('change_password_link')) ?>
        </div>
        <div class="card-body">
            <?php
            echo $this->Form->create(null, ['url' => ['action' => 'htmxChangePassword'], 'type' => 'post']);
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
            ?>
        </div>
    </div>
</div>

<?php
