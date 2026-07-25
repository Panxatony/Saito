/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Reusable htmx + Alpine.js island for server-rendered thread-line lists
 * (recent postings, search results, …). This is the "island" delivery model the
 * Vite migration enables: a small, self-contained bundle loaded only on the
 * pages that need it, replacing the legacy Backbone/Marionette SPA for that view.
 *
 * Any container marked `.js-thread-island` gets progressive enhancement of its
 * recent/search thread lines: the round thread icon (`.btn_show_thread`) toggles
 * the posting inline via an htmx POST to the existing `entries/view/ID` endpoint
 * (which returns the `view_posting` fragment for AJAX requests); the text link
 * (`.link_show_thread`) keeps its normal navigation to the full post. Alpine
 * drives any page-local interactivity declared in the template.
 */
import htmx from 'htmx.org';
import Alpine from 'alpinejs';

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

    // Swap into a throwaway inner element, not the slider itself: htmx replaces
    // its target, and we need the `.threadInline-slider` wrapper to survive so
    // the next click can find it and toggle instead of reloading. htmx.ajax()
    // runs through the normal pipeline, so htmx:configRequest (CSRF +
    // X-Requested-With) applies and the swapped-in content is processed too.
    const inner = document.createElement('div');
    slider.appendChild(inner);
    window.htmx.ajax('POST', url, { target: inner, swap: 'innerHTML' });
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
    window.htmx.ajax('GET', `/entries/htmx-reply/${id}`, { target: slot, swap: 'innerHTML' });
});

// Text link on a thread line → open the standalone htmx thread view, so a click
// stays in the island world instead of bouncing to the SPA posting page. The
// round icon still inline-opens; this is the "open the whole thread" affordance.
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLAnchorElement>('a.link_show_thread');
    if (!link || !link.closest('.js-thread-island')) {
        return;
    }
    const href = link.getAttribute('href') ?? '';
    const match = href.match(/\/entries\/view\/(\d+)/);
    if (!match) {
        return;
    }
    event.preventDefault();
    window.location.href = href.replace(/\/entries\/view\/\d+/, `/entries/htmx-thread/${match[1]}`);
});

// BBCode editor toolbar: wrap the textarea selection in the button's tags.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-bb-btn');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const textarea = btn.closest('form')?.querySelector<HTMLTextAreaElement>('textarea');
    if (!textarea) {
        return;
    }
    const open = btn.getAttribute('data-bb-open') ?? '';
    const close = btn.getAttribute('data-bb-close') ?? '';
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selected = textarea.value.slice(start, end);
    textarea.value = textarea.value.slice(0, start) + open + selected + close + textarea.value.slice(end);
    textarea.focus();
    textarea.selectionStart = start + open.length;
    textarea.selectionEnd = start + open.length + selected.length;
});

// Editor upload: the upload button opens the hidden file picker …
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest('.js-bb-upload');
    if (!btn) {
        return;
    }
    event.preventDefault();
    btn.closest('.js-editor-toolbar')
        ?.querySelector<HTMLInputElement>('.js-bb-file')
        ?.click();
});

// … and picking one or more files uploads each and inserts its
// [tag src=upload]name[/tag]. Uploads run sequentially so the tags stay in the
// picked order.
function insertUploadTag(textarea: HTMLTextAreaElement, name: string, mime: string): void {
    const type = mime.split('/')[0];
    const tag = type === 'video' || type === 'audio' ? type : type === 'image' ? 'img' : 'file';
    const bb = `[${tag} src=upload]${name}[/${tag}]\n`;
    const pos = textarea.selectionStart ?? textarea.value.length;
    textarea.value = textarea.value.slice(0, pos) + bb + textarea.value.slice(pos);
    textarea.selectionStart = textarea.selectionEnd = pos + bb.length;
}

document.addEventListener('change', (event: Event) => {
    const input = (event.target as HTMLElement).closest<HTMLInputElement>('.js-bb-file');
    if (!input || !input.files || input.files.length === 0) {
        return;
    }
    const textarea = input.closest('form')?.querySelector<HTMLTextAreaElement>('textarea');
    if (!textarea) {
        input.value = '';

        return;
    }
    const token = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const files = Array.from(input.files);

    void (async () => {
        for (const file of files) {
            const body = new FormData();
            body.append('file', file);
            try {
                const response = await fetch('/entries/htmx-upload', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                    credentials: 'same-origin',
                });
                const data: { name?: string; mime?: string; error?: string } = await response.json();
                if (data.error || !data.name) {
                    window.alert(`${file.name}: ${data.error ?? 'upload failed'}`);
                    continue;
                }
                insertUploadTag(textarea, data.name, data.mime ?? '');
            } catch {
                window.alert(`${file.name}: upload failed`);
            }
        }
        input.value = '';
        textarea.focus();
    })();
});

// Theme toggle (light / night) for the standalone header: swap the stylesheet
// live and remember the choice under the theme's own `localStorage.theme` key,
// so a reload keeps it (the htmx_island layout reads the same key).
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('#js-themeToggle');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const link = document.getElementById('js-themeCss') as HTMLLinkElement | null;
    if (!link) {
        return;
    }
    try {
        const toNight = localStorage.getItem('theme') !== 'night';
        const href = btn.getAttribute(toNight ? 'data-night-css' : 'data-theme-css');
        localStorage.setItem('theme', toNight ? 'night' : 'theme');
        if (href) {
            link.href = href;
        }
    } catch (e) {
        /* localStorage unavailable */
    }
});

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
    window.htmx.ajax('GET', url, { target: slot, swap: 'innerHTML' });
});

Alpine.start();
