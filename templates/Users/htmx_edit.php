<?php
/**
 * User settings as an htmx island page (strangler-fig PoC).
 *
 * Reachable at /users/htmx-edit. A focused, island-styled version of the main
 * settings form (the most-edited fields); saved via the same allowed-field
 * patch as edit(). Native FormHelper form (CSRF token in the body). The
 * `form-control` / `form-check-input` classes make the shared theme style the
 * inputs (the Saito convention).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array $availableThemes
 */
?>
<div class="users edit">
    <div class="card mb-3">
        <div class="card-header">
            <?= $this->Layout->panelHeading(__('Settings')) ?>
        </div>
        <div class="card-body">
            <?php
            echo $this->Form->create($user, ['url' => ['action' => 'htmxEdit'], 'type' => 'post']);

            echo $this->Form->control('user_email', ['class' => 'form-control', 'label' => __('userlist_email')]);
            echo $this->Form->control('user_real_name', ['class' => 'form-control', 'label' => __('user_real_name')]);
            echo $this->Form->control('user_hp', ['class' => 'form-control', 'label' => __('user_hp')]);
            echo $this->Form->control('user_place', ['class' => 'form-control', 'label' => __('user_place')]);
            echo $this->Form->control('profile', [
                'class' => 'form-control', 'type' => 'textarea', 'rows' => 3, 'label' => __('user_profile'),
            ]);
            echo $this->Form->control('signature', [
                'class' => 'form-control', 'type' => 'textarea', 'rows' => 3, 'label' => __('user_signature'),
            ]);

            if (!empty($availableThemes)) {
                echo $this->Form->control('user_theme', [
                    'class' => 'form-control', 'type' => 'select', 'options' => $availableThemes, 'label' => __('user_theme'),
                ]);
            }

            $checkboxes = [
                'inline_view_on_click' => 'inline_view_on_click',
                'user_automaticaly_mark_as_read' => 'user_automaticaly_mark_as_read',
                'personal_messages' => 'user_pers_msg',
                'user_signatures_hide' => 'user_signatures_hide',
            ];
            foreach ($checkboxes as $field => $labelKey) {
                echo $this->Html->div('form-group form-check', $this->Form->control($field, [
                    'type' => 'checkbox',
                    'class' => 'form-check-input',
                    'label' => ['text' => __($labelKey), 'class' => 'form-check-label'],
                ]));
            }

            echo $this->Form->button(__('Submit'), ['type' => 'submit', 'class' => 'btn btn-primary']);
            echo $this->Form->end();
            ?>
            <p style="margin-top: 1rem;">
                <a href="<?= $this->request->getAttribute('webroot') ?>users/htmx-change-password">
                    <?= h(__('change_password_link')) ?>
                </a>
            </p>
        </div>
    </div>
</div>

<?php
echo $this->Html->script('htmx-threads.bundle.js');
