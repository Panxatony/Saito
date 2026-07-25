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

    <?php
    // Theme look. The theme's own layout/default.php loads this via a
    // SaitoApp-dependent `document.write`; replicate it here without the SPA
    // global so migrated pages keep the operator's theme (incl. the night
    // preset toggled via localStorage). $this->getTheme() is the active theme
    // set by ThemesComponent in AppController::beforeRender().
    $theme = $this->getTheme();
    if ($theme) {
        $themeCss = $this->Url->assetUrl($theme . '.css/theme.css');
        $nightCss = $this->Url->assetUrl($theme . '.css/night.css');
        ?>
        <script>
            (function () {
                var css = <?= json_encode($themeCss) ?>;
                try {
                    if (localStorage.theme === 'night') { css = <?= json_encode($nightCss) ?>; }
                } catch (e) { /* localStorage unavailable */ }
                document.write('<link rel="stylesheet" type="text/css" href="' + css + '">');
            })();
        </script>
        <noscript><?= $this->Html->css($theme . '.theme.css') ?></noscript>
        <?php
    }
    ?>

    <?= \Cake\Core\Configure::read('Saito.headHtml') ?>
    <meta name="viewport" content="width=device-width"/>
</head>
<body>
    <div id="site">
        <?= $this->element('layout/htmx_header') ?>
        <div id="content">
            <?= $this->fetch('content') ?>
        </div>
    </div>
    <?= $this->fetch('script') ?>
</body>
</html>
