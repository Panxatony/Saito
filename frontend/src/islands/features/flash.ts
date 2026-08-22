/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Island flash messages: dismiss on click, fade the friendly ones by
 * themselves, and — since 8.4.14 — let the islands raise one of their own.
 */
import { onReady } from '../lib/dom';

// Island flash messages: a close button dismisses any; success/info auto-fade.
function dismissFlash(el: HTMLElement): void {
    el.style.transition = 'opacity .4s ease';
    el.style.opacity = '0';
    window.setTimeout(() => el.remove(), 400);
}
document.addEventListener('click', (event: MouseEvent) => {
    const close = (event.target as HTMLElement).closest<HTMLElement>('.js-flash-close');
    if (close) {
        event.preventDefault();
        const alertEl = close.closest<HTMLElement>('.js-island-flash');
        if (alertEl) {
            dismissFlash(alertEl);
        }
    }
});
function autoDismissFlashes(): void {
    document.querySelectorAll<HTMLElement>('.js-flash-auto').forEach((el) => {
        el.classList.remove('js-flash-auto');
        window.setTimeout(() => dismissFlash(el), 5000);
    });
}
onReady(autoDismissFlashes);

/**
 * Raise a flash message from an island.
 *
 * Deliberately builds the same markup the layout emits for a server-side
 * flash, so it inherits the theme, the close button and the dismiss handler
 * above rather than growing a second look for the same thing.
 *
 * The text is passed in rather than written here: nothing in the frontend is
 * translated, so a string in this file would be English on a German forum. The
 * caller reads it from what the server rendered.
 *
 * @param message the text to show, already in the reader's language
 * @param kind bootstrap alert suffix; errors and warnings stay until dismissed
 */
export function showFlash(message: string, kind: 'danger' | 'warning' | 'info' = 'danger'): void {
    const host = document.getElementById('content') ?? document.body;

    // One at a time per text. A failing request often means the next one fails
    // too, and three identical banners about the same expired page help nobody.
    const existing = Array.from(host.querySelectorAll<HTMLElement>('.js-island-flash'));
    if (existing.some((el) => el.dataset.flashText === message)) {
        return;
    }

    const alertEl = document.createElement('div');
    alertEl.className = `alert alert-${kind} js-island-flash`;
    alertEl.setAttribute('role', 'alert');
    alertEl.dataset.flashText = message;
    // textContent, not innerHTML: the message may carry a server error string.
    alertEl.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'js-flash-close';
    close.setAttribute('aria-label', '×');
    close.textContent = '×';
    alertEl.appendChild(close);

    host.insertBefore(alertEl, host.firstChild);
    alertEl.scrollIntoView({ block: 'nearest' });
}
