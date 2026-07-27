/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The right-hand widget rail: minimise a widget to its icon, remember the
 * arrangement, and let the main column take the space when all of them are.
 */
import { csrfToken } from '../lib/dom';

// Right-rail widgets: collapse/expand per widget, remembered in localStorage.
// The sidebar htmx-reloads (poll / refresh-recent), so the collapsed state is
// re-applied after every swap of freshly-rendered widgets.
const WIDGETS_STORAGE = 'islandWidgetsCollapsed';
function collapsedWidgets(): string[] {
    try {
        return JSON.parse(localStorage.getItem(WIDGETS_STORAGE) ?? '[]') as string[];
    } catch {
        return [];
    }
}

/**
 * Is anybody signed in? Only then is there an account to store this on. The
 * island layout already marks the body for signed-in members; reusing that
 * beats inventing a second marker that could drift out of step with it.
 */
function isSignedIn(): boolean {
    return document.body.classList.contains('is-member');
}

/**
 * The rail only narrows when *every* widget is an icon — a single open card
 * needs the full width anyway, so narrowing before that would just make that
 * card unreadable.
 */
function syncRailWidth(): void {
    const cols = document.querySelector<HTMLElement>('.island-cols');
    const widgets = document.querySelectorAll<HTMLElement>('.island-widget');
    if (!cols || !widgets.length) {
        return;
    }
    const allMin = [...widgets].every((w) => w.classList.contains('is-min'));
    cols.classList.toggle('is-railMin', allMin);
}

function applyWidgetCollapse(root?: HTMLElement): void {
    // A signed-in member gets their arrangement rendered by the server, so
    // re-applying the browser's copy here would overrule the account.
    if (!isSignedIn()) {
        const collapsed = collapsedWidgets();
        (root ?? document).querySelectorAll<HTMLElement>('.island-widget').forEach((w) => {
            const id = w.getAttribute('data-widget') ?? '';
            w.classList.toggle('is-min', collapsed.includes(id));
        });
    }
    syncRailWidth();
}

/** Persist the arrangement: on the account when signed in, else in the browser. */
function storeWidgetState(minimised: string[]): void {
    if (!isSignedIn()) {
        try {
            localStorage.setItem(WIDGETS_STORAGE, JSON.stringify(minimised));
        } catch {
            /* localStorage unavailable */
        }

        return;
    }
    const body = new URLSearchParams();
    minimised.forEach((id) => body.append('widgets[]', id));
    void fetch('/users/htmx-widget-state', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: body.toString(),
    });
}
document.addEventListener('click', (event: MouseEvent) => {
    const head = (event.target as HTMLElement).closest<HTMLElement>('.js-widgetToggle');
    if (!head) {
        return;
    }
    // A heading may contain a real link (the online widget links the word
    // "users" to the member list). Let that navigate instead of swallowing the
    // click and collapsing the widget.
    if ((event.target as HTMLElement).closest('a')) {
        return;
    }
    event.preventDefault();
    const widget = head.closest<HTMLElement>('.island-widget');
    if (!widget) {
        return;
    }
    widget.classList.toggle('is-min');
    syncRailWidth();
    // Read the arrangement back off the page rather than tracking it separately:
    // one source of truth, and the request cannot drift from what is rendered.
    const minimised = [...document.querySelectorAll<HTMLElement>('.island-widget.is-min')]
        .map((w) => w.getAttribute('data-widget') ?? '')
        .filter(Boolean);
    storeWidgetState(minimised);
});
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    if (target && (target.classList?.contains('island-sidebar') || target.querySelector?.('.island-widget'))) {
        applyWidgetCollapse(target);
    }
});
