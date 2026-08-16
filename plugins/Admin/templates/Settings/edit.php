<?php
$this->Breadcrumbs->add(__('Settings'), '/admin/settings');
$this->Breadcrumbs->add(__d('nondynamic', $setting->get('name')), false);
?>
<h1><?php echo __d('nondynamic', $setting->get('name')); ?></h1>
<p><?php echo __d('nondynamic', $setting->get('name') . '_exp'); ?></p>
<?php
echo $this->Form->create($setting);

if ($setting->get('type') === 'select') {
    // A fixed list, because the values are a closed set. Labels come from the
    // translation catalogue so the meaning is readable — "mod" alone does not
    // say whether it means "from moderator upwards" or "moderators only".
    $choices = [];
    foreach (($settingOptions ?? []) as $value) {
        $choices[$value] = __d('nondynamic', $setting->get('name') . '.' . $value);
    }
    echo $this->Form->control(
        'value',
        [
            'type' => 'select',
            'options' => $choices,
            'label' => __d('nondynamic', $setting->get('name')),
        ]
    );
} elseif ($setting->get('type') === 'bool') {
    $checkbox = $this->Form->control(
        'value',
        [
            'class' => 'form-check-input',
            'label' => __d('nondynamic', $setting->get('name')),
            'type' => 'checkbox',
        ]
    );
    echo $this->Html->div('form-check', $checkbox);
} else {
    echo $this->Form->control(
        'value',
        [
            'label' => __d('nondynamic', $setting->get('name')),
        ]
    );
}

echo $this->Form->submit(
    __('Submit'),
    [
        'class' => 'btn-primary',
    ]
);
echo $this->Form->end();
?>
