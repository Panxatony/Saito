/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The two things that must happen before the page is painted.
 *
 * Both read a per-device preference out of `localStorage` and apply it to the
 * document: which theme stylesheet to load, and the reader's font scale. Doing
 * either after paint means a visible flash of the wrong one, which is why they
 * used to be inline `<script>` blocks in the layout's `<head>`.
 *
 * Inline is exactly what dropping `'unsafe-inline'` from the content-security
 * policy forbids. The usual answer is a per-request nonce, which would mean the
 * application taking the policy over from the edge — a larger change, and one
 * that puts a security header in two places at once.
 *
 * A **synchronous** external script needs neither. It still blocks parsing and
 * still runs before the first paint; the only cost is one request, cached from
 * then on. `defer` or `async` would not do — those are precisely the attributes
 * that would reintroduce the flash.
 *
 * The two stylesheet URLs come from `<html data-theme-css data-night-css>`: this
 * file is static and cached, so it cannot carry values the server computes per
 * theme.
 */

/**
 * Load the theme stylesheet the reader last chose.
 *
 * Appended rather than written with document.write(): equivalent while the
 * document is still parsing, and it does not need a method whose whole reputation
 * is that it should not be used.
 *
 * @param root the document element, carrying the two URLs
 */
function applyTheme(root: HTMLElement): void {
    const dayCss = root.getAttribute('data-theme-css');
    if (dayCss === null) {
        return;
    }
    const nightCss = root.getAttribute('data-night-css');

    let css = dayCss;
    try {
        if (localStorage.getItem('theme') === 'night' && nightCss !== null) {
            css = nightCss;
        }
    } catch {
        /* localStorage unavailable — private mode, blocked cookies */
    }

    const link = document.createElement('link');
    link.id = 'js-themeCss';
    link.rel = 'stylesheet';
    link.type = 'text/css';
    link.href = css;
    document.head.appendChild(link);
}

/**
 * Apply the reader's font scale, set in the settings and kept per device like the
 * theme.
 *
 * @param root the document element
 */
function applyFontScale(root: HTMLElement): void {
    try {
        const scale = localStorage.getItem('islandFontScale');
        if (scale) {
            root.style.fontSize = `${scale}%`;
        }
    } catch {
        /* localStorage unavailable */
    }
}

applyTheme(document.documentElement);
applyFontScale(document.documentElement);
