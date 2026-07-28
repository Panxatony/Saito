/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The right-hand widget rail: drag a widget into the order you want, minimise
 * one to its icon, and let the main column take the space when all of them are.
 */
import { csrfToken } from '../lib/dom';

// Right-rail arrangement: order and collapsed state, remembered on the account
// for members and in localStorage for everyone else. The sidebar htmx-reloads
// (poll / refresh-recent), so the arrangement is re-applied after every swap of
// freshly-rendered widgets.
const WIDGETS_STORAGE = 'islandWidgetsCollapsed';
const ORDER_STORAGE = 'islandWidgetsOrder';

function readStored(key: string): string[] {
    try {
        return JSON.parse(localStorage.getItem(key) ?? '[]') as string[];
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

function rail(): HTMLElement | null {
    return document.querySelector<HTMLElement>('.island-sidebar');
}

function widgetsIn(root: ParentNode): HTMLElement[] {
    return [...root.querySelectorAll<HTMLElement>('.island-widget')];
}

function idOf(widget: Element): string {
    return widget.getAttribute('data-widget') ?? '';
}

/**
 * The order the member last dragged the rail into, for this page view only.
 *
 * The server renders a member's stored order, so this exists for the gap
 * between a drop and the request that records it: the rail refreshes itself
 * every 60 seconds, and a swap landing inside that gap would otherwise put the
 * widgets straight back where they were. Null until something is actually
 * dragged, so an untouched page never argues with the server.
 */
let liveOrder: string[] | null = null;

/**
 * The rail only narrows when *every* widget is an icon — a single open card
 * needs the full width anyway, so narrowing before that would just make that
 * card unreadable.
 */
function syncRailWidth(): void {
    const cols = document.querySelector<HTMLElement>('.island-cols');
    const widgets = widgetsIn(document);
    if (!cols || !widgets.length) {
        return;
    }
    const allMin = widgets.every((w) => w.classList.contains('is-min'));
    cols.classList.toggle('is-railMin', allMin);
}

/**
 * Put the widgets into `order`, with anything it does not name left at the end
 * — the same rule the template follows, so a widget added in a later release
 * appears in the same place whether the page or the script arranged it.
 */
function applyOrder(order: string[]): void {
    const sidebar = rail();
    if (!sidebar) {
        return;
    }
    const present = widgetsIn(sidebar);
    const named = order
        .map((id) => present.find((w) => idOf(w) === id))
        .filter((w): w is HTMLElement => w !== undefined);
    const rest = present.filter((w) => !order.includes(idOf(w)));
    [...named, ...rest].forEach((w) => sidebar.appendChild(w));
}

function applyWidgetState(root?: HTMLElement): void {
    // A signed-in member gets their arrangement rendered by the server, so
    // re-applying the browser's copy here would overrule the account.
    if (!isSignedIn()) {
        const collapsed = readStored(WIDGETS_STORAGE);
        widgetsIn(root ?? document).forEach((w) => {
            w.classList.toggle('is-min', collapsed.includes(idOf(w)));
        });
        applyOrder(readStored(ORDER_STORAGE));
    } else if (liveOrder) {
        applyOrder(liveOrder);
    }
    syncRailWidth();
}

/**
 * Persist the arrangement: on the account when signed in, else in the browser.
 *
 * Both halves go together every time. They share one column, so sending only
 * the half that changed would blank the other — minimising a widget would
 * forget the order it was dragged into.
 */
function storeArrangement(): void {
    const sidebar = rail();
    if (!sidebar) {
        return;
    }
    const widgets = widgetsIn(sidebar);
    const order = widgets.map(idOf).filter(Boolean);
    const minimised = widgets.filter((w) => w.classList.contains('is-min')).map(idOf).filter(Boolean);

    if (!isSignedIn()) {
        try {
            localStorage.setItem(WIDGETS_STORAGE, JSON.stringify(minimised));
            localStorage.setItem(ORDER_STORAGE, JSON.stringify(order));
        } catch {
            /* localStorage unavailable */
        }

        return;
    }

    liveOrder = order;
    const body = new URLSearchParams();
    minimised.forEach((id) => body.append('widgets[]', id));
    order.forEach((id) => body.append('order[]', id));
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

/* Reordering ---------------------------------------------------------------- */

const midpoint = (el: Element): number => {
    const box = el.getBoundingClientRect();

    return box.top + box.height / 2;
};

/**
 * Slide an element from where it was to where it now is.
 *
 * A widget displaced by the one being dragged moves because the DOM changed,
 * and a CSS transition cannot animate that — layout jumps are not transitions.
 * So it is offset back to its old position and released on the next frame,
 * which the stylesheet then does animate. Without this the neighbours snap and
 * the drop position is guesswork.
 */
function slideFrom(el: HTMLElement, from: number): void {
    const shift = from - el.getBoundingClientRect().top;
    if (!shift) {
        return;
    }
    el.style.transition = 'none';
    el.style.transform = `translateY(${shift}px)`;
    requestAnimationFrame(() => {
        el.style.transition = '';
        el.style.transform = '';
    });
}

/** Swap `widget` with the neighbour above (-1) or below (1) it. */
function swapWith(widget: HTMLElement, neighbour: HTMLElement, direction: -1 | 1): void {
    const sidebar = widget.parentElement;
    if (direction === -1) {
        sidebar?.insertBefore(widget, neighbour);
    } else {
        sidebar?.insertBefore(neighbour, widget);
    }
}

/**
 * The neighbour one step up or down, or null at the end of the rail.
 *
 * Checked for the widget class rather than taken as-is: the rail is the swap
 * target of an htmx request, and a stray node in it must not be picked up and
 * shuffled about as though it were a widget.
 */
function neighbourOf(widget: HTMLElement, direction: -1 | 1): HTMLElement | null {
    const sibling = direction === -1 ? widget.previousElementSibling : widget.nextElementSibling;

    return sibling?.classList.contains('island-widget') ? (sibling as HTMLElement) : null;
}

/** Move `widget` one place up or down, animating what it displaces. */
function step(widget: HTMLElement, direction: -1 | 1): boolean {
    const neighbour = neighbourOf(widget, direction);
    if (!neighbour) {
        return false;
    }
    const widgetFrom = widget.getBoundingClientRect().top;
    const neighbourFrom = neighbour.getBoundingClientRect().top;

    swapWith(widget, neighbour, direction);

    slideFrom(widget, widgetFrom);
    slideFrom(neighbour, neighbourFrom);

    return true;
}

function beginDrag(grip: HTMLElement, start: PointerEvent): void {
    const widget = grip.closest<HTMLElement>('.island-widget');
    const sidebar = widget?.parentElement;
    if (!widget || !sidebar) {
        return;
    }

    // Keeps the gesture from turning into a text selection or a native
    // image-drag halfway through.
    start.preventDefault();
    grip.setPointerCapture(start.pointerId);

    // Where the pointer was when the card was still at rest. Re-based every
    // time the card changes place in the DOM, so it keeps tracking the pointer
    // instead of jumping by its own height.
    let originY = start.clientY;
    let moved = false;

    widget.classList.add('is-dragging');

    const onMove = (event: PointerEvent): void => {
        moved = true;
        widget.style.transform = `translateY(${event.clientY - originY}px)`;

        // Past a neighbour's midpoint the two swap, and the card is re-based so
        // it stays under the pointer. Only ever one step per move event: the
        // next event re-measures against the new arrangement.
        const centre = midpoint(widget);
        const up = neighbourOf(widget, -1);
        const down = neighbourOf(widget, 1);
        let direction: -1 | 1 | 0 = 0;
        if (up && centre < midpoint(up)) {
            direction = -1;
        } else if (down && centre > midpoint(down)) {
            direction = 1;
        }
        if (direction === 0) {
            return;
        }

        const neighbour = (direction === -1 ? up : down) as HTMLElement;
        const before = widget.getBoundingClientRect().top;
        const neighbourFrom = neighbour.getBoundingClientRect().top;
        swapWith(widget, neighbour, direction);
        // The card's resting place moved; shift the origin by the same amount so
        // the transform still lands it under the pointer.
        originY += widget.getBoundingClientRect().top - before;
        widget.style.transform = `translateY(${event.clientY - originY}px)`;
        slideFrom(neighbour, neighbourFrom);
    };

    const onEnd = (): void => {
        grip.removeEventListener('pointermove', onMove);
        grip.removeEventListener('pointerup', onEnd);
        grip.removeEventListener('pointercancel', onEnd);
        widget.classList.remove('is-dragging');
        widget.style.transform = '';
        if (moved) {
            storeArrangement();
        }
    };

    grip.addEventListener('pointermove', onMove);
    grip.addEventListener('pointerup', onEnd);
    grip.addEventListener('pointercancel', onEnd);
}

document.addEventListener('pointerdown', (event: PointerEvent) => {
    // Primary button (or any touch/pen contact) only — a right-click on the
    // handle should open the context menu, not pick the widget up.
    if (event.button !== 0) {
        return;
    }
    const grip = (event.target as HTMLElement).closest<HTMLElement>('.js-widgetDrag');
    if (grip) {
        beginDrag(grip, event);
    }
});

// The same reordering without a pointer. The handle is a real button, so it is
// already in the tab order; these two keys are all it needs to be usable.
document.addEventListener('keydown', (event: KeyboardEvent) => {
    const grip = (event.target as HTMLElement).closest<HTMLElement>('.js-widgetDrag');
    const widget = grip?.closest<HTMLElement>('.island-widget');
    if (!widget || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) {
        return;
    }
    event.preventDefault();
    if (step(widget, event.key === 'ArrowUp' ? -1 : 1)) {
        storeArrangement();
        // Moving the button in the DOM drops focus; put it back so a second
        // press keeps moving the same widget.
        grip?.focus();
    }
});

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
    storeArrangement();
});
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    if (target && (target.classList?.contains('island-sidebar') || target.querySelector?.('.island-widget'))) {
        applyWidgetState(target);
    }
});
