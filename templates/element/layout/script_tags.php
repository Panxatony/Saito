<?php

$this->Flash->render();

echo $this->Html->scriptBlock($this->JsData->getAppJs($this, $CurrentUser));

// The Vite build emits one self-contained app.bundle.js (no separate vendor
// chunk), so there is no vendor/app split to keep in sync.
echo $this->Html->script([
    'app.bundle.js',
]);

echo $this->Html->scriptBlock('window.Application.start({ SaitoApp: SaitoApp });');

echo $this->fetch('script-head');
