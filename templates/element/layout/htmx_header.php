<?php
/**
 * Slim, server-rendered header for the standalone htmx island pages.
 *
 * The full theme header is SPA-driven (theme switcher, collapse, login modal);
 * this is a static, SPA-free bar reusing the theme's `#header` structure so the
 * theme CSS styles it natively. Home, search, and the login state — all plain
 * links that work without JavaScript.
 *
 * @var \App\View\AppView $this
 */

$forumName = $forumName ?? \Cake\Core\Configure::read('Saito.Settings.forum_name');
$webroot = $this->request->getAttribute('webroot');
$theme = $this->getTheme();
$themeCss = $theme ? $this->Url->assetUrl($theme . '.css/theme.css') : '';
$nightCss = $theme ? $this->Url->assetUrl($theme . '.css/night.css') : '';
?>
<header id="header" class="htmx-island-header">
    <div id="header-hero">
        <a href="<?= $webroot ?>entries/htmx-index" id="btn_header_logo" class="btn btn-link">
            <?php // The active theme's logo (e.g. plugins/Local/webroot/img/forum_logo.svg);
                  // Html->image resolves it from the theme's webroot. alt = forum name so
                  // there's a text fallback if the image is missing. ?>
            <div id="hero-homeLink"><?= $this->Html->image('forum_logo.svg', ['alt' => $forumName]) ?></div>
        </a>
    </div>
    <?php
    // Labelled buttons like the classic header: the theme hides .saito-icon-text
    // globally, so render icon + a plain .island-btn-label span (not textWithIcon).
    $iconLabel = function (string $icon, string $label): string {
        return '<i class="fa fa-' . $icon . '" aria-hidden="true"></i>'
            . '<span class="island-btn-label">' . h($label) . '</span>';
    };
    ?>
    <div id="header-menu">
        <?php // Left: profile + auth, with text labels (classic look). ?>
        <div class="first">
            <?php if ($CurrentUser->isLoggedIn()) : ?>
                <a href="<?= $webroot ?>users/htmx-profile/<?= $CurrentUser->getId() ?>"
                   class="btn btn-link" title="<?= h(__('Profile')) ?>">
                    <?= $iconLabel('user', __('Profile')) ?>
                </a>
                <a href="<?= $webroot ?>logout?redirect=<?= urlencode($webroot . 'entries/htmx-index') ?>"
                   class="btn btn-link" title="<?= h(__('logout_linkname')) ?>">
                    <?= $iconLabel('sign-out', __('logout_linkname')) ?>
                </a>
            <?php else : ?>
                <a href="<?= $webroot ?>users/htmx-login" class="btn btn-link js-authModalOpen"
                   data-modal-url="<?= $webroot ?>login" title="<?= h(__('login_btn')) ?>">
                    <?= $iconLabel('sign-in', __('login_btn')) ?>
                </a>
                <a href="<?= $webroot ?>users/htmx-register" class="btn btn-link js-authModalOpen"
                   data-modal-url="<?= $webroot ?>users/htmx-register" title="<?= h(__('register_linkname')) ?>">
                    <?= $iconLabel('user-plus', __('register_linkname')) ?>
                </a>
            <?php endif; ?>
        </div>
        <?php // Middle: search with label. ?>
        <div class="middle">
            <a href="<?= $webroot ?>searches/htmx-simple" class="btn btn-link js-headerToggle"
               data-hx-url="<?= $webroot ?>searches/htmx-simple?widget=1" title="<?= h(__('Search')) ?>">
                <?= $iconLabel('search', __('Search')) ?>
            </a>
        </div>
        <?php // Right: admin (admins only), help, theme toggle, bookmarks — icon-only. ?>
        <div class="last">
            <?php if ($CurrentUser->permission('saito.core.admin.backend')) : ?>
                <a href="<?= $webroot ?>admin" class="btn btn-link" title="<?= h(__('Admin')) ?>">
                    <i class="fa fa-cogs"></i>
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-link js-helpOpen" title="<?= h(__('Help')) ?>">
                <i class="fa fa-question-circle"></i>
            </button>
            <?php if ($themeCss) : ?>
                <button type="button" class="btn btn-link" id="js-themeToggle"
                        data-theme-css="<?= h($themeCss) ?>" data-night-css="<?= h($nightCss) ?>"
                        title="<?= h(__('Toggle dark / light')) ?>">
                    <i class="fa fa-adjust"></i>
                </button>
            <?php endif; ?>
            <?php if ($CurrentUser->isLoggedIn()) : ?>
                <a href="<?= $webroot ?>users/bookmarks" class="btn btn-link js-headerToggle"
                   data-hx-url="<?= $webroot ?>users/bookmarks" title="<?= h(__('bkm.title.pl')) ?>">
                    <i class="fa fa-bookmark"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
