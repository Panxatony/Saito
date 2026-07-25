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
    <?php if (\Cake\Core\Configure::read('Saito.noindex')) : ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
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
<body class="htmx-island<?= !empty($CurrentUser) && $CurrentUser->isLoggedIn() ? ' is-member' : '' ?>" data-inline-on-click="<?= !empty($CurrentUser) && $CurrentUser->get('inline_view_on_click') ? '1' : '0' ?>">
    <div id="site">
        <?php // Beta banner: only on island-frontend (beta) installs. ?>
        <?php if (\Cake\Core\Configure::read('Saito.frontend') === 'island') : ?>
            <div class="beta-notice" role="status">
                <i class="fa fa-flask" aria-hidden="true"></i>
                <?= h(__('beta_notice')) ?>
            </div>
        <?php endif; ?>
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

    <?php // Smart insert overlay: paste a URL, auto-detect link/image/video/
          // YouTube, live-preview, insert. Opened from the editor toolbar. ?>
    <div id="js-insertModal" class="island-modal" hidden>
        <div class="island-modal-backdrop js-modal-close"></div>
        <div class="island-modal-dialog" role="dialog" aria-modal="true"
             aria-label="<?= h(__('insert_link_media')) ?>">
            <button type="button" class="island-modal-close js-modal-close" aria-label="&times;">&times;</button>
            <h3 style="margin: 0 0 .75rem; font-size: 1.1rem;"><?= h(__('insert_link_media')) ?></h3>
            <div class="input">
                <label for="js-insertUrl">URL</label>
                <input type="url" id="js-insertUrl" class="form-control" placeholder="https://…" autocomplete="off">
            </div>
            <div class="input js-insertTextRow" hidden>
                <label for="js-insertText"><?= h(__('insert_text_optional')) ?></label>
                <input type="text" id="js-insertText" class="form-control" autocomplete="off">
            </div>
            <div class="js-insertType" style="font-size: .85rem; color: #777; margin: .25rem 0 .5rem;"></div>
            <div class="js-insertPreview" style="min-height: 1px;"></div>
            <button type="button" class="btn btn-primary js-insertConfirm" disabled><?= h(__('insert_btn')) ?></button>
        </div>
    </div>

    <?php // Login overlay: filled on demand (htmx GET /login) by the header link. ?>
    <div id="js-loginModal" class="island-modal" hidden>
        <div class="island-modal-backdrop js-modal-close"></div>
        <div class="island-modal-dialog" role="dialog" aria-modal="true" aria-label="<?= h(__('login_btn')) ?>">
            <button type="button" class="island-modal-close js-modal-close" aria-label="&times;">&times;</button>
            <div id="js-loginModalBody"></div>
        </div>
    </div>
    <?= $this->fetch('script') ?>
    <?php // The island bundle drives the whole shell (header toggles, theme
          // switch, thread-line enhancement), so load it once here for every
          // island page instead of per-template. ?>
    <?= $this->Html->script('htmx-threads.bundle.js') ?>
</body>
</html>
