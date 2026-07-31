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
<?php
// The theme's two stylesheets, handed to the pre-paint script on the root
// element. That script is a static file and cannot carry values the server
// computes, and an inline block is what dropping 'unsafe-inline' from the
// content-security policy forbids. `getTheme()` is set by ThemesComponent in
// AppController::beforeRender().
$theme = $this->getTheme();
$themeAttrs = '';
if ($theme) {
    $themeAttrs = ' data-theme-css="' . h($this->Url->assetUrl($theme . '.css/theme.css')) . '"'
        . ' data-night-css="' . h($this->Url->assetUrl($theme . '.css/night.css')) . '"';
}
?>
<html<?= $themeAttrs ?>>
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
    // Before anything is painted: which theme stylesheet to load and the reader's
    // font scale, both per-device preferences out of localStorage. Deliberately
    // *not* deferred — a synchronous external script still runs before the first
    // paint, which is the whole point; `defer` would bring back the flash of the
    // wrong theme these lines exist to prevent.
    //
    // It used to be two inline blocks. External so the content-security policy can
    // drop 'unsafe-inline' without the application having to take the policy over
    // from the edge and hand out a nonce per request.
    ?>
    <script src="<?= h($this->Url->assetUrl('boot.bundle.js', ['pathPrefix' => 'js/'])) ?>"></script>
    <?php if ($theme) : ?>
        <noscript><?= $this->Html->css($theme . '.theme.css') ?></noscript>
    <?php endif; ?>

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
<?php
// The unread accent rail is drawn by two stylesheets — the island's and the
// theme's — so it is switched off here, on the one element both can see, rather
// than in either of them.
$noUnreadRail = \Cake\Core\Configure::read('Saito.unreadRail') === false ? ' no-unread-rail' : '';

// A member's own thread-line colours, handed to the island as plain values; it
// applies them (userColors.ts). Empty for guests and for anyone who left the
// setting on "use the theme's colour".
$userColors = !empty($CurrentUser) && $CurrentUser->isLoggedIn()
    ? $this->User->colors($CurrentUser->getSettings())
    : [];
?>
<body class="htmx-island<?= !empty($CurrentUser) && $CurrentUser->isLoggedIn() ? ' is-member' : '' ?><?= $noUnreadRail ?>" data-inline-on-click="<?= !empty($CurrentUser) && $CurrentUser->get('inline_view_on_click') ? '1' : '0' ?>" data-threads-collapsed="<?= !empty($CurrentUser) && $CurrentUser->get('user_show_thread_collapsed') ? '1' : '0' ?>"<?php
    foreach ($userColors as $key => $value) {
        echo ' data-color-' . h($key) . '="' . h($value) . '"';
    }
?>>
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
              // arrive with a stale cache and a frontend they have never seen.
              // It can be switched off once that has passed — or on a beta being
              // used to try something else, where it is only in the way. Absent
              // configuration means "show", so installations that predate the
              // setting are unaffected. ?>
        <?php
        // Two messages, two lifetimes, and they used to share one switch.
        //
        // The lead is about a change that happened: a new frontend, reload if it
        // looks off. It stops being true a few weeks after the switch, and an
        // installation turning it off then lost the help pointer with it.
        //
        // The pointer below has no expiry date — there is always somebody
        // arriving for the first time — so it has its own setting and shows
        // unless an installation says otherwise. Both keep the "absent means
        // show" convention: leaving a key out is not the same as setting it to
        // false, which is exactly the trap the lead's own setting laid.
        $showLead = \Cake\Core\Configure::read('Saito.notice') !== false;
        $showHelp = \Cake\Core\Configure::read('Saito.noticeHelp') !== false;
        ?>
        <?php if ($showLead || $showHelp) : ?>
        <div class="island-notice" role="status">
            <?php if ($showLead) : ?>
            <p class="island-notice-lead">
                <i class="fa fa-<?= $isBeta ? 'flask' : 'refresh' ?>" aria-hidden="true"></i>
                <?= h($isBeta ? __('beta_notice') : __('notice.modernised')) ?>
            </p>
            <?php endif; ?>
            <?php if ($showHelp) : ?>
            <p class="island-notice-help-line">
                <?= h(__('beta_notice_help')) ?>
                <button type="button" class="island-notice-help js-helpOpen">
                    <i class="fa fa-question-circle" aria-hidden="true"></i>&nbsp;<?= h(__('notice.help.btn')) ?>
                </button>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?= $this->element('layout/htmx_header') ?>
        <?php
        // The banner slot: operator-supplied markup between the header bar and
        // the page, in the `div.ads_top` installations already carry a banner
        // in. Trusted configuration, so it is rendered unescaped — and the
        // container is skipped entirely when nothing is configured, rather than
        // leaving an empty div on every page of every other installation.
        $bannerHtml = (string)\Cake\Core\Configure::read('Saito.bannerHtml');
        ?>
        <?php if (trim($bannerHtml) !== '') : ?>
            <div class="ads_top"><?= $bannerHtml ?></div>
        <?php endif; ?>
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
                <?php // Marks what is being inserted, not the file: the flag rides
                      // in the BBCode tag, so the same upload can be covered in one
                      // posting and plain in another. Unticked on every open — a
                      // remembered tick would silently cover the next insertion. ?>
                <label class="upload-nsfw">
                    <input type="checkbox" class="js-uploadNsfw">
                    <?= h(__('upload_insert_nsfw')) ?>
                </label>
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
            <?php // Only offered for an ordinary link: an image or a video is
                  // already shown, and a card about it would say the same thing
                  // twice. ?>
            <div class="input js-insertCardRow" hidden>
                <label style="font-weight: normal;">
                    <input type="checkbox" id="js-insertCard">
                    <?= h(__('insert_as_card')) ?>
                </label>
                <small class="text-muted" style="display: block;"><?= h(__('insert_as_card.exp')) ?></small>
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
