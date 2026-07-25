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
                document.write('<link id="js-themeCss" rel="stylesheet" type="text/css" href="' + css + '">');
            })();
        </script>
        <noscript><?= $this->Html->css($theme . '.theme.css') ?></noscript>
        <?php
    }
    ?>

    <?php // User font-size preference (set in settings, stored per device like the
          // night/day theme). Applied here before paint to avoid a size flash. ?>
    <script>
        (function () {
            try {
                var s = localStorage.islandFontScale;
                if (s) { document.documentElement.style.fontSize = s + '%'; }
            } catch (e) { /* localStorage unavailable */ }
        })();
    </script>

    <?= \Cake\Core\Configure::read('Saito.headHtml') ?>
    <?php // Neutral-clean island polish — loaded last so it layers over the theme. ?>
    <?= $this->Html->css('htmx-island') ?>
    <meta name="viewport" content="width=device-width"/>
</head>
<body class="htmx-island">
    <div id="site">
        <?= $this->element('layout/htmx_header') ?>
        <?php // Filled on demand by the header "new entry" / "search" links. ?>
        <div id="js-headerActions"></div>
        <div id="content">
            <?php
            // Flash → island HTML. Saito's flash elements only push into JsData
            // (the SPA renders those as toasts client-side); with no SPA here,
            // emit the messages ourselves from the same JsData store as themed
            // Bootstrap alerts.
            $this->Flash->render();
            $flashClass = ['error' => 'danger', 'success' => 'success', 'warning' => 'warning', 'notice' => 'info'];
            foreach ($this->JsData->notifications()->getAll() as $flashMsg) :
                $cls = $flashClass[$flashMsg['type']] ?? 'info';
                ?>
                <div class="alert alert-<?= h($cls) ?>" role="alert"><?= h($flashMsg['message']) ?></div>
                <?php
            endforeach;
            ?>
            <?= $this->fetch('content') ?>
        </div>
    </div>
    <?php // Footer: the standard disclaimer (Resources / Status / About). ?>
    <?= $this->element('layout/disclaimer') ?>
    <?= $this->fetch('script') ?>
    <?php // The island bundle drives the whole shell (header toggles, theme
          // switch, thread-line enhancement), so load it once here for every
          // island page instead of per-template. ?>
    <?= $this->Html->script('htmx-threads.bundle.js') ?>
</body>
</html>
