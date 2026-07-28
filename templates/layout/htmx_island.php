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
    <?php
    // The CSRF token every scripted write request needs. It belongs to the
    // layout, not to individual templates: nine of them carried their own copy
    // and the ones added later did not, so on those pages `csrfToken()` in the
    // island bundle found nothing and every POST came back 403 — the editor
    // preview, uploads and the widget state, all failing silently.
    ?>
    <meta name="csrf-token" content="<?= h($this->getRequest()->getAttribute('csrfToken')) ?>">
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
    <?php // RSS feed autodiscovery (public feeds) so browsers/readers find them. ?>
    <?php $webrootFeed = $this->request->getAttribute('webroot'); ?>
    <link rel="alternate" type="application/rss+xml"
          title="<?= h(__d('feeds', 'postings.new.t')) ?>" href="<?= $webrootFeed ?>feeds/postings/new.rss">
    <link rel="alternate" type="application/rss+xml"
          title="<?= h(__d('feeds', 'threads.new.t')) ?>" href="<?= $webrootFeed ?>feeds/postings/threads.rss">
    <?php // Neutral-clean island polish — loaded last so it layers over the theme. ?>
    <?= $this->Html->css('htmx-island') ?>
    <meta name="viewport" content="width=device-width"/>
</head>
<body class="htmx-island<?= !empty($CurrentUser) && $CurrentUser->isLoggedIn() ? ' is-member' : '' ?>" data-inline-on-click="<?= !empty($CurrentUser) && $CurrentUser->get('inline_view_on_click') ? '1' : '0' ?>" data-threads-collapsed="<?= !empty($CurrentUser) && $CurrentUser->get('user_show_thread_collapsed') ? '1' : '0' ?>">
    <div id="site">
        <?php
        // The corner ribbon marks a test deployment and must not survive the
        // switch of a live forum to this frontend — hence Saito.beta rather than
        // the frontend switch it used to hang off.
        $isBeta = (bool)\Cake\Core\Configure::read('Saito.beta');
        ?>
        <?php if ($isBeta) : ?>
            <div class="island-ribbon" aria-hidden="true"><span>Beta</span></div>
        <?php endif; ?>
        <?php // The notice stays on a live install too: after the switch people
              // arrive with a stale cache and a frontend they have never seen. ?>
        <div class="island-notice" role="status">
            <p class="island-notice-lead">
                <i class="fa fa-<?= $isBeta ? 'flask' : 'refresh' ?>" aria-hidden="true"></i>
                <?= h($isBeta ? __('beta_notice') : __('notice.modernised')) ?>
            </p>
            <p class="island-notice-help-line">
                <?= h(__('beta_notice_help')) ?>
                <button type="button" class="island-notice-help js-helpOpen">
                    <i class="fa fa-question-circle" aria-hidden="true"></i>&nbsp;<?= h(__('notice.help.btn')) ?>
                </button>
            </p>
        </div>
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
                // Success / info auto-dismiss; errors + warnings stay until closed.
                $auto = in_array($flashMsg['type'], ['success', 'notice'], true) ? ' js-flash-auto' : '';
                ?>
                <div class="alert alert-<?= h($cls) ?> js-island-flash<?= $auto ?>" role="alert">
                    <?= h($flashMsg['message']) ?>
                    <button type="button" class="js-flash-close" aria-label="&times;">&times;</button>
                </div>
                <?php
            endforeach;
            ?>
            <?= $this->fetch('content') ?>
        </div>
    </div>
    <?php // Footer: the standard disclaimer (Resources / Status / About). ?>
    <?= $this->element('layout/disclaimer') ?>

    <?php // Upload overlay: upload one or more files, browse the user's archive
          // (20 per page, load more), click a tile to insert it. Editor toolbar. ?>
    <div id="js-uploadModal" class="island-modal" hidden>
        <div class="island-modal-backdrop js-modal-close"></div>
        <div class="island-modal-dialog" role="dialog" aria-modal="true"
             aria-label="<?= h(__('upload_media_title')) ?>">
            <button type="button" class="island-modal-close js-modal-close" aria-label="&times;">&times;</button>
            <h3 style="margin: 0 0 .75rem; font-size: 1.1rem;"><?= h(__('upload_media_title')) ?></h3>
            <div class="upload-drop js-uploadDrop">
                <input type="file" class="js-uploadInput" multiple accept="image/*,video/*,audio/*" hidden>
                <p style="margin: 0 0 .5rem; color: #777;"><?= h(__('upload_drop_hint')) ?></p>
                <button type="button" class="btn btn-secondary btn-sm js-uploadPick">
                    <?= h(__('upload_choose_files')) ?>
                </button>
            </div>
            <div class="js-uploadStatus" style="font-size: .85rem; margin: .4rem 0;"></div>
            <div class="upload-grid js-uploadGrid"></div>
            <div class="upload-actions">
                <button type="button" class="btn btn-primary js-uploadInsert" disabled>
                    <?= h(__('upload_insert_selected')) ?>
                </button>
            </div>
        </div>
    </div>

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

    <?php // Help overlay. The content — the tour plus the other topics — is
          // fetched from /help/tour when it is first opened, rather than
          // rendered into every page for the rare visit that reads it. ?>
    <div id="js-helpModal" class="island-modal" hidden>
        <div class="island-modal-backdrop js-modal-close"></div>
        <div class="island-modal-dialog island-help-dialog" role="dialog" aria-modal="true"
             aria-label="<?= h(__('Help')) ?>">
            <button type="button" class="island-modal-close js-modal-close" aria-label="&times;">&times;</button>
            <h3 style="margin: 0 0 .75rem; font-size: 1.2rem;">
                <i class="fa fa-question-circle"></i>&nbsp;<?= h(__('Help')) ?>
            </h3>
            <div id="js-helpModalBody"></div>
        </div>
    </div>

    <?php // RSS overlay: the public feeds only (personalized feeds live in the
          // profile). Opened from the footer. ?>
    <?php $webrootRss = $this->request->getAttribute('webroot'); ?>
    <div id="js-rssModal" class="island-modal" hidden>
        <div class="island-modal-backdrop js-modal-close"></div>
        <div class="island-modal-dialog" role="dialog" aria-modal="true" aria-label="<?= h(__('s.rss.t')) ?>">
            <button type="button" class="island-modal-close js-modal-close" aria-label="&times;">&times;</button>
            <h3 style="margin: 0 0 .75rem; font-size: 1.1rem;">
                <i class="fa fa-rss"></i>&nbsp;<?= h(__('s.rss.t')) ?>
            </h3>
            <ul class="island-rss-list">
                <li>
                    <a href="<?= $webrootRss ?>feeds/postings/new.rss">
                        <i class="fa fa-rss" aria-hidden="true"></i> <?= h(__d('feeds', 'postings.new.t')) ?>
                    </a>
                </li>
                <li>
                    <a href="<?= $webrootRss ?>feeds/postings/threads.rss">
                        <i class="fa fa-rss" aria-hidden="true"></i> <?= h(__d('feeds', 'threads.new.t')) ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <?php // Contact-owner overlay: form loaded on demand (htmx GET) from the footer. ?>
    <div id="js-contactModal" class="island-modal" hidden>
        <div class="island-modal-backdrop js-modal-close"></div>
        <div class="island-modal-dialog" role="dialog" aria-modal="true" aria-label="<?= h(__('owner_contact_title')) ?>">
            <button type="button" class="island-modal-close js-modal-close" aria-label="&times;">&times;</button>
            <div id="js-contactModalBody"></div>
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
    <?php // Change-password overlay: form loaded on demand (htmx GET) from the
          // settings page. ?>
    <div id="js-passwordModal" class="island-modal" hidden>
        <div class="island-modal-backdrop js-modal-close"></div>
        <div class="island-modal-dialog" role="dialog" aria-modal="true" aria-label="<?= h(__('change_password_link')) ?>">
            <button type="button" class="island-modal-close js-modal-close" aria-label="&times;">&times;</button>
            <div id="js-passwordModalBody"></div>
        </div>
    </div>
    <?= $this->fetch('script') ?>
    <?php // The island bundle drives the whole shell (header toggles, theme
          // switch, thread-line enhancement), so load it once here for every
          // island page instead of per-template. ?>
    <?= $this->Html->script('htmx-threads.bundle.js') ?>
</body>
</html>
