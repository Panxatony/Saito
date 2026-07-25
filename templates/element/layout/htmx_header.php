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
        <a href="<?= $webroot ?>" id="btn_header_logo" class="btn btn-link">
            <div id="hero-homeLink"><?= h($forumName) ?></div>
        </a>
    </div>
    <div id="header-menu">
        <div class="middle">
            <?php if ($CurrentUser->isLoggedIn()) : ?>
                <a href="<?= $webroot ?>entries/htmx-add" class="btn btn-link">
                    <?= $this->Layout->textWithIcon(h(__('new_entry_linkname')), 'plus') ?>
                </a>
            <?php endif; ?>
            <a href="<?= $webroot ?>searches/simple" class="btn btn-link">
                <?= $this->Layout->textWithIcon(h(__('Search')), 'search') ?>
            </a>
        </div>
        <div class="last">
            <?php if ($themeCss) : ?>
                <button type="button" class="btn btn-link" id="js-themeToggle"
                        data-theme-css="<?= h($themeCss) ?>" data-night-css="<?= h($nightCss) ?>"
                        title="<?= h(__('Toggle dark / light')) ?>">
                    <i class="fa fa-adjust"></i>
                </button>
            <?php endif; ?>
            <?php if ($CurrentUser->isLoggedIn()) : ?>
                <a href="<?= $webroot ?>users/bookmarks" class="btn btn-link" title="<?= h(__('bkm.title.pl')) ?>">
                    <?= $this->Layout->textWithIcon('', 'bookmark') ?>
                </a>
                <a href="<?= $webroot ?>users/view/<?= $CurrentUser->getId() ?>" class="btn btn-link">
                    <?= $this->Layout->textWithIcon(h($CurrentUser->get('username')), 'user') ?>
                </a>
                <a href="<?= $webroot ?>logout" class="btn btn-link" title="<?= h(__('logout_linkname')) ?>">
                    <?= $this->Layout->textWithIcon('', 'sign-out') ?>
                </a>
            <?php else : ?>
                <a href="<?= $webroot ?>login" class="btn btn-link">
                    <?= $this->Layout->textWithIcon(h(__('login_btn')), 'sign-in') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
