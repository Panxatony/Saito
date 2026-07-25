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
    const match = href.match(/\/entries\/view\/(\d+)/);
    if (!match) {
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

    // Setting off, or already open (second click): go to the full thread page.
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

/**
 * The CSRF token for session-authed write requests (bookmark/solve/delete).
 */
function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    );
}

/**
 * Enhance a freshly-loaded inline posting with the action buttons the SPA
 * normally renders client-side from its `.js-data` JSON (bookmark, "mark as
 * solution"). The tool-menu actions (pin/lock/delete) are wired via delegation
 * below; here we only inject the two per-posting toggles.
 *
 * @param core the `.js-entry-view-core` posting element
 */
function enhancePosting(core: HTMLElement): void {
    if (core.dataset.islandEnhanced) {
        return;
    }
    core.dataset.islandEnhanced = '1';

    const id = core.getAttribute('data-id');
    const dataEl = core.querySelector<HTMLElement>('.js-data');
    if (!id || !dataEl) {
        return;
    }
    let data: { isBookmarked?: boolean; showSolvedBtn?: boolean; solves?: number } = {};
    try {
        data = JSON.parse(dataEl.getAttribute('data-entry') ?? '{}');
    } catch {
        return;
    }

    let actions = core.querySelector<HTMLElement>('.postingLayout-actions');
    if (!actions) {
        actions = document.createElement('div');
        actions.className = 'postingLayout-actions';
        core.appendChild(actions);
    }

    const group = document.createElement('span');
    group.className = 'island-posting-tools';

    // Bookmark toggle — any logged-in user. Session endpoint toggles by entry id.
    const bkm = document.createElement('button');
    bkm.type = 'button';
    bkm.className = 'btn btn-link js-island-bookmark';
    const paintBkm = (on: boolean): void => {
        bkm.innerHTML = `<i class="fa ${on ? 'fa-bookmark' : 'fa-bookmark-o'}"></i>`;
        bkm.setAttribute('aria-pressed', on ? 'true' : 'false');
    };
    paintBkm(!!data.isBookmarked);
    bkm.addEventListener('click', () => {
        void fetch(`/entries/htmx-bookmark/${id}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((j: { bookmarked?: boolean }) => paintBkm(!!j.bookmarked))
            .catch(() => undefined);
    });
    group.appendChild(bkm);

    // "Mark as solution" — only when the server says the user may set it.
    if (data.showSolvedBtn) {
        const solve = document.createElement('button');
        solve.type = 'button';
        solve.className = 'btn btn-link js-island-solve';
        const paintSolve = (on: boolean): void => {
            solve.innerHTML = `<i class="fa fa-check-circle${on ? '' : '-o'}"></i>`;
            solve.setAttribute('aria-pressed', on ? 'true' : 'false');
        };
        let solved = !!data.solves;
        paintSolve(solved);
        solve.addEventListener('click', () => {
            void fetch(`/entries/solve/${id}`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => {
                    if (r.ok) {
                        solved = !solved;
                        paintSolve(solved);
                    }
                })
                .catch(() => undefined);
        });
        group.appendChild(solve);
    }

    actions.insertBefore(group, actions.firstChild);
}

// Enhance any posting htmx swaps into the page (inline open, reply, etc.).
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    (target ?? document).querySelectorAll<HTMLElement>('.js-entry-view-core')
        .forEach((el) => {
            if (el.closest('.js-thread-island')) {
                enhancePosting(el);
            }
        });
});

// Auto-dismiss the "post saved" confirmation a few seconds after it appears.
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    const done = (target ?? document).querySelector<HTMLElement>('.js-add-done');
    if (!done) {
        return;
    }
    window.setTimeout(() => {
        done.style.transition = 'opacity .5s ease';
        done.style.opacity = '0';
        window.setTimeout(() => done.remove(), 500);
    }, 5000);
});

// Tool menu — pin / lock (moderators). ajaxToggle is an ajax GET (no CSRF needed
// on GET); on success reopen the posting so its state reflects the change.
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLElement>(
        '.js-btn-toggle-fixed, .js-btn-toggle-locked'
    );
    if (!link || !link.closest('.js-thread-island')) {
        return;
    }
    event.preventDefault();
    const toggle = link.classList.contains('js-btn-toggle-fixed') ? 'fixed' : 'locked';
    const leaf = link.closest<HTMLElement>('.threadLeaf');
    const id = link.closest<HTMLElement>('.js-entry-view-core')?.getAttribute('data-id');
    if (!id) {
        return;
    }
    void fetch(`/entries/ajaxToggle/${id}/${toggle}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    })
        .then((r) => {
            if (r.ok && leaf) {
                const icon = leaf.querySelector<HTMLElement>('.btn_show_thread');
                icon?.click(); // close
                icon?.click(); // reopen with fresh state
            }
        })
        .catch(() => undefined);
});

// Tool menu — delete: go to the server's CSRF-protected delete confirmation flow
// (a destructive action stays a deliberate, guarded navigation).
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLElement>('.js-delete');
    if (!link || !link.closest('.js-thread-island')) {
        return;
    }
    event.preventDefault();
    const id = link.closest<HTMLElement>('.js-entry-view-core')?.getAttribute('data-id');
    if (id) {
        window.location.href = `/entries/delete/${id}`;
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

// Font-size preference: the settings buttons set a per-browser scale applied to
// the root element (the island sizes in rem/em, so this scales everything).
// Stored in localStorage and re-applied by the island layout on every page.
function currentFontScale(): string {
    try {
        return localStorage.getItem('islandFontScale') ?? '100';
    } catch {
        return '100';
    }
}

function markActiveFontScale(): void {
    const cur = currentFontScale();
    document.querySelectorAll<HTMLElement>('.js-font-scale').forEach((b) => {
        b.classList.toggle('active', b.getAttribute('data-scale') === cur);
    });
}

document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-font-scale');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const scale = btn.getAttribute('data-scale') ?? '100';
    try {
        localStorage.setItem('islandFontScale', scale);
    } catch {
        /* localStorage unavailable */
    }
    document.documentElement.style.fontSize = `${scale}%`;
    markActiveFontScale();
});

markActiveFontScale();

// Login modal: the header "Anmelden" link opens an overlay and loads the login
// form fragment (htmx GET /login) instead of navigating. A failed login swaps
// the form back in with the error; a successful one returns HX-Redirect (htmx
// navigates natively). Close on backdrop, ×, or Escape.
function closeLoginModal(): void {
    document.getElementById('js-loginModal')?.setAttribute('hidden', '');
}

document.addEventListener('click', (event: MouseEvent) => {
    const opener = (event.target as HTMLElement).closest<HTMLElement>('.js-loginModalOpen');
    if (opener) {
        event.preventDefault();
        const modal = document.getElementById('js-loginModal');
        const body = document.getElementById('js-loginModalBody');
        if (!modal || !body) {
            return;
        }
        modal.removeAttribute('hidden');
        window.htmx.ajax('GET', opener.getAttribute('data-login-url') ?? '/login', {
            target: body,
            swap: 'innerHTML',
        });

        return;
    }
    if ((event.target as HTMLElement).closest('.js-modal-close')) {
        event.preventDefault();
        closeLoginModal();
    }
});

document.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeLoginModal();
    }
});

Alpine.start();
