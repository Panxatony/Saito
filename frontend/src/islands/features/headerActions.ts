/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Header and list actions that load a fragment into a slot — the search box and
 * the new-entry editor.
 */
import { htmx } from '../runtime';

// Toggle actions ("search" in the header, "new entry" above the thread list):
// load a fragment into a slot on click — clicking the same link again closes it,
// a different link switches. The slot defaults to #js-headerActions but a link
// may name its own via data-hx-target (element id), so the same handler drives
// both the header search and the in-list new-entry editor.
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLElement>('.js-headerToggle');
    if (!link) {
        return;
    }
    event.preventDefault();
    const targetId = link.getAttribute('data-hx-target') ?? 'js-headerActions';
    const slot = document.getElementById(targetId);
    if (!slot) {
        return;
    }
    const url = link.getAttribute('data-hx-url') ?? '';
    if (slot.getAttribute('data-loaded') === url) {
        slot.innerHTML = '';
        slot.removeAttribute('data-loaded');

        return;
    }
    slot.setAttribute('data-loaded', url);
    htmx.ajax('GET', url, { target: slot, swap: 'innerHTML' });
});
