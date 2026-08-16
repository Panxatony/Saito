<?php

$this->Breadcrumbs->add(__('Settings'), 'admin/settings');
$this->Breadcrumbs->add('🌍🕐', false);

?>

<div id="settings_timezone" class="settings timezone">
<?php
echo $this->Form->create($setting);
echo $this->Form->control(
    'value',
    [
        'label' => '🌍🕐',
        'type' => 'select',
        'options' => ['fields' => $this->TimeH->getTimezoneSelectOptions()],
    ]
);

echo $this->Form->submit(
    null,
    [
        'class' => 'btn-primary',
    ]
);
echo $this->Form->end();
?>
</div>