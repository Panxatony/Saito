<?php
/**
 * Minimal layout for the htmx/Alpine PoC pages — the same <head>/CSS as the
 * normal layout but WITHOUT the Backbone/Marionette SPA bootstrap
 * (element/layout/script_tags) and its SaitoApp-dependent inline scripts.
 *
 * A migrated page is served standalone: the SPA and an island cannot both own
 * the same `.threadLeaf` / `.js-entry-view-core` markup (the SPA scans for it
 * globally at start, with no way to exclude a subtree), so loading both on one
 * page makes them fight over the DOM. Serving without the SPA is the clean
 * strangler-fig end state for a fully-migrated view.
 *
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= h($titleForLayout ?? '') ?></title>
    <?= $this->fetch('meta') ?>
    <?= $this->Html->css('stylesheets/static.css') ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('theme_head') ?>
    <?= \Cake\Core\Configure::read('Saito.headHtml') ?>
    <meta name="viewport" content="width=device-width"/>
</head>
<body>
    <div id="site">
        <div id="content">
            <?= $this->fetch('content') ?>
        </div>
    </div>
    <?= $this->fetch('script') ?>
</body>
</html>
