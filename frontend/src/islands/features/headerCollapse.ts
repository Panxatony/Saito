/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Fold the header away, and remember it.
 *
 * The classic Bota theme had this and the island frontend did not carry it over
 * — the theme's own `theme.js` implemented it in Marionette, which retired with
 * the SPA. macfix, whose theme is a fork of Bota's, still runs it, and readers
 * who collapsed the bar years ago expect it to stay collapsed.
 *
 * The class goes on the document element, not `<body>`: the state has to be
 * applied before the first paint, `boot.ts` runs inside `<head>`, and
 * `document.body` does not exist yet at that point. Here it would not matter;
 * keeping both on the same element is what makes the CSS have one rule instead
 * of two.
 *
 * `localStorage` is written as the string `'true'` / `'false'` because that is
 * the key the classic theme wrote, and an installation upgrading from it should
 * find its readers' collapsed headers still collapsed.
 */

const KEY = 'headerClosed';

/**
 * @param closed whether the header should be folded away
 */
function setCollapsed(closed: boolean): void {
    document.documentElement.classList.toggle(KEY, closed);
    try {
        localStorage.setItem(KEY, closed ? 'true' : 'false');
    } catch {
        /* localStorage unavailable — private mode, blocked cookies */
    }
}

document.addEventListener('click', (event: MouseEvent) => {
    const target = event.target as HTMLElement;

    if (target.closest('#js-top-menu-close')) {
        event.preventDefault();
        setCollapsed(true);

        return;
    }

    if (target.closest('#js-top-menu-open')) {
        event.preventDefault();
        setCollapsed(false);
        // Reopening from a collapsed, sticky header leaves the reader part-way
        // down the page with the bar suddenly twice as tall. The classic theme
        // scrolled to the top for the same reason.
        window.scrollTo(0, 0);
    }
});
