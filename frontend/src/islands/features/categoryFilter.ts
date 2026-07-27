/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Filtering the front page by several categories at once.
 */
import { htmx } from '../runtime';

// Category filter: several categories at once, as the retired chooser allowed.
// htmx-index takes a comma-separated ?category and returns the filtered page-1
// fragment; an empty selection means "all".
function catFilterApply(root: HTMLElement): void {
    const list = document.getElementById('js-threadList');
    if (!list) {
        return;
    }
    const chosen = [...root.querySelectorAll<HTMLInputElement>('.js-catFilterOne')]
        .filter((box) => box.checked)
        .map((box) => box.value);

    // "All" is the absence of a filter, not a category of its own — so an empty
    // selection and every box ticked mean the same thing, and both send `all`.
    const every = chosen.length === root.querySelectorAll('.js-catFilterOne').length;
    const param = chosen.length === 0 || every ? 'all' : chosen.join(',');

    const all = root.querySelector<HTMLInputElement>('.js-catFilterAll');
    if (all) {
        all.checked = param === 'all';
    }
    const summary = root.querySelector<HTMLElement>('.js-catFilterSummary');
    if (summary) {
        summary.textContent = param === 'all'
            ? (all?.parentElement?.textContent ?? '').trim()
            : `${chosen.length} / ${root.querySelectorAll('.js-catFilterOne').length}`;
    }

    const base = root.getAttribute('data-list-url') ?? '/entries/htmx-index';
    htmx.ajax('GET', `${base}?category=${encodeURIComponent(param)}`, {
        target: list,
        swap: 'innerHTML',
    });
}

document.addEventListener('change', (event: Event) => {
    const target = event.target as HTMLElement;
    const root = target.closest<HTMLElement>('.js-catFilter');
    if (!root) {
        return;
    }
    // Ticking "all" clears the individual boxes rather than adding to them.
    if (target.classList.contains('js-catFilterAll')) {
        const on = (target as HTMLInputElement).checked;
        root.querySelectorAll<HTMLInputElement>('.js-catFilterOne').forEach((box) => {
            box.checked = false;
        });
        if (!on) {
            // Unticking "all" on its own would leave nothing selected, which is
            // the same as "all" — so keep it ticked instead of doing nothing.
            (target as HTMLInputElement).checked = true;

            return;
        }
    }
    catFilterApply(root);
});

// Open and close the filter menu.
document.addEventListener('click', (event: MouseEvent) => {
    const toggle = (event.target as HTMLElement).closest<HTMLElement>('.js-catFilterToggle');
    const openMenu = document.querySelector<HTMLElement>('.island-catFilter-menu:not([hidden])');
    if (toggle) {
        const menu = toggle.parentElement?.querySelector<HTMLElement>('.island-catFilter-menu');
        if (menu) {
            const show = menu.hasAttribute('hidden');
            menu.toggleAttribute('hidden', !show);
            toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        }

        return;
    }
    // A click inside the menu is a checkbox, not a dismissal.
    if (openMenu && !(event.target as HTMLElement).closest('.island-catFilter-menu')) {
        openMenu.setAttribute('hidden', '');
        document.querySelector('.js-catFilterToggle')?.setAttribute('aria-expanded', 'false');
    }
});
