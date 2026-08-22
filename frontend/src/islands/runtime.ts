/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Shared runtime for the islands: the two libraries, the globals the templates
 * reach for, and the one htmx hook every request needs.
 *
 * Every feature module imports `htmx` from here rather than off `window`, so
 * module evaluation order guarantees the library exists before any of them run.
 * The globals are still published, because inline `x-data` and `hx-` attributes
 * in the templates have no other way to reach them.
 */
import htmx from 'htmx.org';
import Alpine from 'alpinejs';
import { showFlash } from './features/flash';
import { serverMessage } from './lib/dom';

declare global {
    interface Window {
        htmx: typeof htmx;
        Alpine: typeof Alpine;
    }
}

// Expose the globals so inline Alpine `x-data` and the template's htmx triggers
// can reach them.
window.htmx = htmx;
window.Alpine = Alpine;

document.body.addEventListener('htmx:configRequest', (event: Event) => {
    const detail = (event as CustomEvent).detail;

    // Make CakePHP's `$this->request->is('ajax')` true for htmx requests so
    // existing AJAX endpoints (e.g. entries/view) return their fragment. htmx
    // sends `HX-Request`, not the `X-Requested-With` header Cake checks.
    detail.headers['X-Requested-With'] = 'XMLHttpRequest';

    // Send CakePHP's CSRF token on every htmx request. Harmless on GET;
    // required for the POST that loads a posting inline.
    const token = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
    if (token) {
        detail.headers['X-CSRF-Token'] = token;
    }
});

/**
 * Say something when a request fails.
 *
 * htmx swaps on 2xx and stays silent otherwise. With no listener for
 * `htmx:responseError` that silence reached the member: the reply form posts
 * with `hx-post`, and once the CSRF token had expired — three hours, see
 * `Application.php` — pressing "send" did visibly nothing at all. The written
 * text stayed in the box with no hint that it had not been sent, and no hint
 * that reloading would fix it.
 *
 * Reported from macnemo.de on 2026-08-22 in its milder form: an upload that
 * answered `1111IMG_0047.JPG: failed`.
 *
 * 403 gets its own wording because it has a cure the member can apply. Every
 * other status gets a plain statement that it did not work — vague, but never
 * silent, which is the property that was missing.
 */
document.body.addEventListener('htmx:responseError', (event: Event) => {
    const status = (event as CustomEvent).detail?.xhr?.status;
    if (status === 403) {
        showFlash(
            serverMessage(
                'msg-session-stale',
                'This page has been open too long and the form is no longer valid.'
                    + ' Reload the page, then try again — your text is kept.',
            ),
            'warning',
        );

        return;
    }
    showFlash(
        serverMessage('msg-request-failed', 'That did not work. Please try again.')
            + (typeof status === 'number' && status > 0 ? ` (${status})` : ''),
    );
});

// A request that never reached the server at all — offline, DNS, a dropped
// connection. Same reasoning: better a vague message than none.
document.body.addEventListener('htmx:sendError', () => {
    showFlash(serverMessage('msg-no-connection', 'No connection to the forum. Check your network.'));
});

export { htmx, Alpine };
