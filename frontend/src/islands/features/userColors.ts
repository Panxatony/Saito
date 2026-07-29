/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * A member's own colours for thread lines: unread, read, and the posting being
 * looked at.
 *
 * The layout puts the chosen values on `<body>` as data attributes (see
 * UserHelper::colors()); this turns them into custom properties and marks which
 * ones are set, so the stylesheet can leave the theme alone for the rest.
 *
 * Applied through CSSOM rather than by writing a `<style>` block, which is what
 * the pre-island layout did — an inline stylesheet is exactly what dropping
 * 'unsafe-inline' from the CSP would stop, silently.
 */

const COLORS: ReadonlyArray<readonly [string, string]> = [
    // Read before unread before current: a posting can carry several of these
    // classes at once, and the later rule in the stylesheet is the one meant to
    // win. The names here only have to match the data attributes.
    ['old', '--saito-color-old'],
    ['new', '--saito-color-new'],
    ['current', '--saito-color-current'],
];

const body = document.body;
for (const [key, property] of COLORS) {
    const value = body.getAttribute(`data-color-${key}`);
    if (!value) {
        continue;
    }
    body.style.setProperty(property, value);
    body.classList.add(`has-color-${key}`);
}
