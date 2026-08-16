<div class="mb-3">
<?= $this->Form->control('subject', [
    'class' => 'form-control',
    'label' => __('user_contact_subject'),
    'tabindex' => 1,
]) ?>
</div>

<div class="mb-3">
<?= $this->Form->control('text', [
    'class' => 'form-control',
    'style' => 'height: 10em',
    'label' => __('user_contact_message'),
    'tabindex' => 2,
]) ?>
</div>

<div class="mb-3 form-check">
<?= $this->Form->control('cc', [
    'class' => 'form-check-input',
    'label' => [
        'class' => 'form-check-label',
        'text' => __('user_contact_send_carbon_copy'),
        'style' => 'display: inline;',
    ],
    'tabindex' => 3,
]) ?>
</div>

<div class="mb-3">
<?= $this->Form->submit(__('Submit'), [
    'class' => 'btn btn-primary',
    'tabindex' => 4,
]) ?>
</div>
