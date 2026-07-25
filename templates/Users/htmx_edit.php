<?php
/**
 * User settings as an htmx island page (strangler-fig PoC).
 *
 * Reachable at /users/htmx-edit. A focused, island-styled version of the main
 * settings form (the most-edited fields); saved via the same allowed-field
 * patch as edit(). Native FormHelper form (CSRF token in the body).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array $availableThemes
 */
?>
<div class="users edit">
    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('user.edit.t', [h($user->get('username'))])) ?>
        </div>
        <div class="card-body">
            <?php
            echo $this->Form->create($user, ['url' => ['action' => 'htmxEdit'], 'type' => 'post']);

            echo $this->Form->control('user_email', ['label' => __('user_email')]);
            echo $this->Form->control('user_real_name', ['label' => __('user_real_name')]);
            echo $this->Form->control('user_hp', ['label' => __('user_hp')]);
            echo $this->Form->control('user_place', ['label' => __('user_place')]);
            echo $this->Form->control('profile', [
                'type' => 'textarea', 'rows' => 3, 'label' => __('user_profile'),
            ]);
            echo $this->Form->control('signature', [
                'type' => 'textarea', 'rows' => 3, 'label' => __('user_signature'),
            ]);

            if (!empty($availableThemes)) {
                echo $this->Form->control('user_theme', [
                    'type' => 'select', 'options' => $availableThemes, 'label' => __('user.set.theme.t'),
                ]);
            }

            echo $this->Form->control('inline_view_on_click', [
                'type' => 'checkbox', 'label' => __('user.set.inlineView.t'),
            ]);
            echo $this->Form->control('user_automaticaly_mark_as_read', [
                'type' => 'checkbox', 'label' => __('user.set.autoMarkAsRead.t'),
            ]);
            echo $this->Form->control('personal_messages', [
                'type' => 'checkbox', 'label' => __('user.set.pm.t'),
            ]);
            echo $this->Form->control('user_signatures_hide', [
                'type' => 'checkbox', 'label' => __('user.set.hideSignatures.t'),
            ]);

            echo $this->Form->button(__('Submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo $this->Form->end();
            ?>
        </div>
    </div>
</div>

<?php
echo $this->Html->script('htmx-threads.bundle.js');
