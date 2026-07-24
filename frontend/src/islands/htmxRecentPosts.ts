/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * PoC island: htmx + Alpine.js for the server-rendered recent-postings view.
 *
 * This is the "island" delivery model the Vite migration enables: a small,
 * self-contained bundle loaded only on the page that needs it, coexisting with
 * the legacy Backbone/Marionette SPA (app.bundle.js) on the same document.
 * htmx fetches/refreshes the server-rendered fragment; Alpine drives the local
 * interactivity (the auto-refresh toggle).
 */
import htmx from 'htmx.org';
import Alpine from 'alpinejs';

declare global {
    interface Window {
        htmx: typeof htmx;
        Alpine: typeof Alpine;
    }
}

// Expose the globals so the inline Alpine `x-data` and the htmx event triggers
// in the template can reach them.
window.htmx = htmx;
window.Alpine = Alpine;

// Send CakePHP's CSRF token on every htmx request. Harmless on the GET the PoC
// issues; required as soon as htmx starts doing POST/DELETE for the writeable
// parts of the migration.
document.body.addEventListener('htmx:configRequest', (event: Event) => {
    const token = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
    if (token) {
        (event as CustomEvent).detail.headers['X-CSRF-Token'] = token;
    }
});

Alpine.start();
