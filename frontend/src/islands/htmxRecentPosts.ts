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
 *
 * Second strangler-fig slice: progressive enhancement of the recent-post
 * lines. Each `<a class="link_show_thread" href="entries/view/ID">` is a real
 * link that works without JS (it navigates to the posting). With the island
 * loaded, a click instead loads the posting *inline* via an htmx POST to the
 * existing `entries/view/ID` endpoint (which returns the `view_posting`
 * fragment for AJAX requests) — proving the htmx POST + CSRF path end-to-end.
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
 * Toggle a recent-post line's inline posting. First open POSTs to
 * entries/view/ID (via htmx, so CSRF + X-Requested-With are attached) and
 * reveals the returned fragment; later clicks just show/hide it.
 *
 * @param leaf the `.threadLeaf` list item
 */
/**
 * Reflect the open/closed state in the round thread icon: a close (times) glyph
 * while open, the thread glyph while closed.
 *
 * @param leaf the `.threadLeaf` list item
 * @param open whether the inline posting is now shown
 */
function setIconState(leaf: HTMLElement, open: boolean): void {
    const icon = leaf.querySelector('.btn_show_thread i');
    icon?.classList.toggle('fa-thread', !open);
    icon?.classList.toggle('fa-times', open);
}

function toggleInlinePosting(leaf: HTMLElement): void {
    // Already loaded once → just show/hide it. Use an inline `display` style so
    // it wins over the `.threadInline-slider` stylesheet rule.
    const existing = leaf.querySelector<HTMLElement>('.threadInline-slider');
    if (existing) {
        const open = leaf.classList.toggle('is-inline-open');
        existing.style.display = open ? '' : 'none';
        setIconState(leaf, open);

        return;
    }

    const url = leaf
        .querySelector<HTMLAnchorElement>('a.link_show_thread')
        ?.getAttribute('href');
    if (!url) {
        return;
    }

    const slider = document.createElement('div');
    slider.className = 'threadInline-slider';
    leaf.appendChild(slider);
    leaf.classList.add('is-inline-open');
    setIconState(leaf, true);

    // Swap into a throwaway inner element, not the slider itself: htmx replaces
    // its target, and we need the `.threadInline-slider` wrapper to survive so
    // the next click can find it and toggle instead of reloading. htmx.ajax()
    // runs through the normal pipeline, so htmx:configRequest (CSRF +
    // X-Requested-With) applies and the swapped-in content is processed too.
    const inner = document.createElement('div');
    slider.appendChild(inner);
    window.htmx.ajax('POST', url, { target: inner, swap: 'innerHTML' });
}

// Only the round thread icon (`.btn_show_thread`) toggles the inline posting;
// the text link (`.link_show_thread`) keeps its normal navigation so the full
// post/thread can still be opened from the recent-posts view. Scoped to our
// island container (`#js-recentPostsList`).
document.addEventListener('click', (event: MouseEvent) => {
    const trigger = (event.target as HTMLElement).closest('.btn_show_thread');
    if (!trigger || !trigger.closest('#js-recentPostsList')) {
        return;
    }
    const leaf = trigger.closest<HTMLElement>('.threadLeaf');
    if (!leaf) {
        return;
    }
    event.preventDefault();
    toggleInlinePosting(leaf);
});

Alpine.start();
