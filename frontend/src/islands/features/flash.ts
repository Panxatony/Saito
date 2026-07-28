/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Island flash messages: dismiss on click, and fade the friendly ones by
 * themselves.
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
