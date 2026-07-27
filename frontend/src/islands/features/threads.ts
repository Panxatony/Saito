/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Thread lines: opening a posting inline, the mix view, reloading after a reply,
 * and collapsing a thread's answers.
 */
import { htmx } from '../runtime';
import { onReady } from '../lib/dom';

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

/**
 * Toggle a thread line's inline posting. The first open POSTs to entries/view/ID
 * (via htmx, so CSRF + X-Requested-With are attached) and reveals the returned
 * fragment; later clicks just show/hide it.
 *
 * @param leaf the `.threadLeaf` list item
 */
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

    // Opening the posting marks it read server-side (entries/view →
    // MarkAsRead->thread); reflect that on the line immediately.
    leaf.classList.remove('et-new');
    leaf.classList.add('et-old');

    // Swap into a throwaway inner element, not the slider itself: htmx replaces
    // its target, and we need the `.threadInline-slider` wrapper to survive so
    // the next click can find it and toggle instead of reloading. htmx.ajax()
    // runs through the normal pipeline, so htmx:configRequest (CSRF +
    // X-Requested-With) applies and the swapped-in content is processed too.
    const inner = document.createElement('div');
    slider.appendChild(inner);
    htmx.ajax('POST', url, { target: inner, swap: 'innerHTML' });
}

// Delegate clicks: only the round thread icon toggles the inline posting, and
// only inside a `.js-thread-island` container (so htmx-swapped results are
// covered too, without re-binding).
document.addEventListener('click', (event: MouseEvent) => {
    const trigger = (event.target as HTMLElement).closest('.btn_show_thread');
    if (!trigger || !trigger.closest('.js-thread-island')) {
        return;
    }
    const leaf = trigger.closest<HTMLElement>('.threadLeaf');
    if (!leaf) {
        return;
    }
    event.preventDefault();
    toggleInlinePosting(leaf);
});

// Answer button inside an inline-opened posting → load a reply form inline
// (toggle). The button is the SPA's `.js-btn-setAnsweringForm`; we enhance it
// here rather than in the shared markup so the SPA page is untouched.
document.addEventListener('click', (event: MouseEvent) => {
    const trigger = (event.target as HTMLElement).closest('.js-btn-setAnsweringForm');
    if (!trigger || !trigger.closest('.js-thread-island')) {
        return;
    }
    event.preventDefault();
    const posting = trigger.closest<HTMLElement>('.js-entry-view-core');
    const id = posting?.getAttribute('data-id');
    if (!posting || !id) {
        return;
    }
    const openForm = posting.querySelector('.js-replySlot');
    if (openForm) {
        openForm.remove(); // second click closes it

        return;
    }
    const slot = document.createElement('div');
    slot.className = 'js-replySlot';
    posting.appendChild(slot);
    htmx.ajax('GET', `/entries/htmx-reply/${id}`, { target: slot, swap: 'innerHTML' });
});

// Text link on a thread line → open the standalone htmx thread view, so a click
// stays in the island world instead of bouncing to the SPA posting page.
//
// When the user enabled "expand posting on click" (inline_view_on_click), the
// first click opens the posting inline (like the round icon) and only a second
// click — with the posting already open — navigates to the full thread page.
// With the setting off, a click goes straight to the full page.
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLAnchorElement>('a.link_show_thread');
    if (!link || !link.closest('.js-thread-island')) {
        return;
    }
    const href = link.getAttribute('href') ?? '';
    // htmx-posting on an island install, view on the SPA; the classic form is
    // still matched so a page rendered before the switch keeps working.
    if (!/\/entries\/(?:view|htmx-posting)\/\d+/.test(href)) {
        return;
    }

    const inlineOnClick = document.body.dataset.inlineOnClick === '1';
    const leaf = link.closest<HTMLElement>('.threadLeaf');
    if (inlineOnClick && leaf && !leaf.classList.contains('is-inline-open')) {
        // First click: open inline instead of navigating.
        event.preventDefault();
        toggleInlinePosting(leaf);

        return;
    }

    // Setting off, or already open (second click): follow the link. No rewriting
    // any more — the href already points at the island page, which shows the
    // posting *and* the thread it belongs to.
    event.preventDefault();
    window.location.href = href;
});

// Mix button on a thread box.
//
// The href points at the island thread route already, so a plain click (or a new
// tab) is correct without this handler. With "expand posting on click"
// (inline_view_on_click) switched on, the whole thread is pulled into the box
// instead of loading a page — one request for every posting at once, rather than
// opening them one by one. A second click puts the thread tree back.
const mixCollapsed = new WeakMap<HTMLElement, string>();
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLAnchorElement>('a.js-mixToggle');
    if (!link || !link.closest('.js-thread-island')) {
        return;
    }
    // The href is already the island route on an island install; the classic
    // /entries/mix/ form is still matched so the handler keeps working on a
    // page that was rendered before this change.
    const match = (link.getAttribute('href') ?? '').match(/\/entries\/(?:mix|htmx-thread)\/(\d+)/);
    if (!match) {
        return;
    }
    event.preventDefault();
    const threadUrl = `/entries/htmx-thread/${match[1]}`;

    const tree = link.closest('.threadBox')?.querySelector<HTMLElement>('.threadBox-threadTree');
    if (document.body.dataset.inlineOnClick !== '1' || !tree) {
        // Setting off (or no box to expand into): the island thread page, not
        // the SPA one.
        window.location.href = threadUrl;

        return;
    }

    const previous = mixCollapsed.get(tree);
    if (previous !== undefined) {
        tree.innerHTML = previous;
        mixCollapsed.delete(tree);
        tree.classList.remove('is-mix-expanded');
        htmx.process(tree);

        return;
    }

    mixCollapsed.set(tree, tree.innerHTML);
    tree.classList.add('is-mix-expanded');
    htmx.ajax('GET', threadUrl, { target: tree, swap: 'innerHTML' });
});

// Nach einer Antwort den umgebenden Thread neu laden.
//
// htmxReply ersetzt nur das Formular durch die Bestaetigung; der neue Beitrag
// stand nirgends. Wer geantwortet hatte, sah "gespeichert" und danach seine
// Antwort nicht — und hielt sie fuer verloren. Der Thread wird deshalb neu
// geholt, sobald die Bestaetigung erscheint: in der Threadliste nur der
// betroffene Threadkasten, in der Threadansicht die ganze Insel.
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    const done = target?.querySelector<HTMLElement>('.js-replyDone[data-refresh-tid]')
        ?? (target?.matches?.('.js-replyDone[data-refresh-tid]') ? target : null);
    if (!done) {
        return;
    }
    const tid = done.getAttribute('data-refresh-tid');
    if (!tid) {
        return;
    }
    // Erst den Erfolg lesen lassen, dann austauschen.
    window.setTimeout(() => {
        const box = done.closest<HTMLElement>('.threadBox-threadTree');
        const island = done.closest<HTMLElement>('.js-thread-island');
        const container = box ?? island;
        if (!container) {
            return;
        }
        // Inside a thread box on the front page the thread is a tree of subject
        // lines, so ask for that. Without `view=tree` the reply came back with
        // every posting in the thread opened in full — the reader had folded
        // them away, and answering unfolded the lot.
        const url = box
            ? `/entries/htmx-thread/${tid}?view=tree`
            : `/entries/htmx-thread/${tid}`;
        htmx.ajax('GET', url, {
            target: container,
            swap: 'innerHTML',
        });
    }, 1200);
});

// Whole-thread collapse in the list: the threadBox tools button hides/reveals a
// thread's answer subtree (the root line stays). SPA-only feature, wired here.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.btn-threadCollapse');
    if (!btn || !btn.closest('.js-thread-island')) {
        return;
    }
    event.preventDefault();
    const box = btn.closest<HTMLElement>('.threadBox');
    if (box) {
        const collapsed = box.classList.toggle('is-thread-collapsed');
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
});
// Honour the "show all threads collapsed by default" user setting: collapse every
// answered thread on load and after the list htmx-refreshes.
function applyDefaultThreadCollapse(root?: HTMLElement): void {
    if (document.body.getAttribute('data-threads-collapsed') !== '1') {
        return;
    }
    (root ?? document).querySelectorAll<HTMLElement>('.js-thread-island .threadBox')
        .forEach((box) => {
            if (box.querySelector('.btn-threadCollapse')) {
                box.classList.add('is-thread-collapsed');
            }
        });
}
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    if (target && (target.classList?.contains('js-thread-island') || target.querySelector?.('.threadBox'))) {
        applyDefaultThreadCollapse(target);
    }
});
onReady(() => applyDefaultThreadCollapse());
