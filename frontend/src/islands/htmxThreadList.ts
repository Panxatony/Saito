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

// Editor upload overlay: the toolbar button opens an overlay to upload files and
// browse the user's archive (20 per page + load more); clicking a tile inserts
// its [tag src=upload]name[/tag] into the editor that opened it.
let uploadTarget: HTMLTextAreaElement | null = null;

function insertUploadTag(textarea: HTMLTextAreaElement, name: string, mime: string): void {
    const type = mime.split('/')[0];
    const tag = type === 'video' || type === 'audio' ? type : type === 'image' ? 'img' : 'file';
    const bb = `[${tag} src=upload]${name}[/${tag}]\n`;
    const pos = textarea.selectionStart ?? textarea.value.length;
    textarea.value = textarea.value.slice(0, pos) + bb + textarea.value.slice(pos);
    textarea.selectionStart = textarea.selectionEnd = pos + bb.length;
}

function loadUploadGrid(): void {
    const grid = document.querySelector<HTMLElement>('.js-uploadGrid');
    if (grid) {
        window.htmx.ajax('GET', '/entries/htmx-uploads', { target: grid, swap: 'innerHTML' });
    }
}

async function uploadFiles(files: File[]): Promise<void> {
    const token = csrfToken();
    const status = document.querySelector<HTMLElement>('.js-uploadStatus');
    let ok = 0;
    const errs: string[] = [];
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
            const data: { name?: string; error?: string } = await response.json();
            if (data.error || !data.name) {
                errs.push(`${file.name}: ${data.error ?? 'failed'}`);
            } else {
                ok += 1;
            }
        } catch {
            errs.push(`${file.name}: failed`);
        }
    }
    if (status) {
        status.textContent = `${ok} ✓${errs.length ? ` · ${errs.join(', ')}` : ''}`;
    }
    loadUploadGrid();
}

// Toolbar upload button → open the overlay for this editor.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest('.js-bb-upload');
    if (!btn) {
        return;
    }
    event.preventDefault();
    uploadTarget = btn.closest('form')?.querySelector<HTMLTextAreaElement>('textarea[name="text"]') ?? null;
    const status = document.querySelector<HTMLElement>('.js-uploadStatus');
    if (status) {
        status.textContent = '';
    }
    document.getElementById('js-uploadModal')?.removeAttribute('hidden');
    loadUploadGrid();
});

// "Choose files" opens the picker.
document.addEventListener('click', (event: MouseEvent) => {
    if ((event.target as HTMLElement).closest('.js-uploadPick')) {
        event.preventDefault();
        document.querySelector<HTMLInputElement>('.js-uploadInput')?.click();
    }
});

// Picking files uploads them, then refreshes the archive grid.
document.addEventListener('change', (event: Event) => {
    const input = (event.target as HTMLElement).closest<HTMLInputElement>('.js-uploadInput');
    if (!input || !input.files || input.files.length === 0) {
        return;
    }
    void uploadFiles(Array.from(input.files)).then(() => {
        input.value = '';
    });
});

// Drag & drop onto the drop zone.
document.addEventListener('dragover', (event: DragEvent) => {
    const drop = (event.target as HTMLElement).closest('.js-uploadDrop');
    if (!drop) {
        return;
    }
    event.preventDefault();
    drop.classList.add('is-dragover');
});
document.addEventListener('dragleave', (event: DragEvent) => {
    (event.target as HTMLElement).closest('.js-uploadDrop')?.classList.remove('is-dragover');
});
document.addEventListener('drop', (event: DragEvent) => {
    const drop = (event.target as HTMLElement).closest('.js-uploadDrop');
    if (!drop) {
        return;
    }
    event.preventDefault();
    drop.classList.remove('is-dragover');
    const files = event.dataTransfer?.files;
    if (files && files.length) {
        void uploadFiles(Array.from(files));
    }
});

// Clicking an archive tile inserts its BBCode into the editor that opened it.
document.addEventListener('click', (event: MouseEvent) => {
    const tile = (event.target as HTMLElement).closest<HTMLElement>('.js-uploadTile');
    if (!tile || !uploadTarget) {
        return;
    }
    event.preventDefault();
    insertUploadTag(uploadTarget, tile.getAttribute('data-name') ?? '', tile.getAttribute('data-mime') ?? '');
});

// Editor preview: render the current textarea through htmxPreview and show the
// result in the toolbar's preview box. Wired explicitly (not via htmx's fragile
// `next` target inside the hx-post form) so it works in the inline editor too.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-bb-preview');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const toolbar = btn.closest('.js-editor-toolbar');
    const textarea = btn.closest('form')?.querySelector<HTMLTextAreaElement>('textarea[name="text"]');
    const box = toolbar?.querySelector<HTMLElement>('.js-editor-preview');
    if (!textarea || !box) {
        return;
    }
    const url = btn.getAttribute('data-preview-url') ?? '/entries/htmx-preview';
    const body = new URLSearchParams({ text: textarea.value });
    void fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
        credentials: 'same-origin',
    })
        .then((r) => r.text())
        .then((html) => {
            box.innerHTML = html;
        })
        .catch(() => undefined);
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

// Category chooser: set the user's active category (204 response) then reload
// the thread list via the refresh-recent trigger #js-threadList listens for.
document.addEventListener('change', (event: Event) => {
    const sel = (event.target as HTMLElement).closest<HTMLSelectElement>('.js-categoryChooser');
    if (!sel) {
        return;
    }
    const url = (sel.getAttribute('data-set-url') ?? '') + encodeURIComponent(sel.value);
    void fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(() => document.body.dispatchEvent(new CustomEvent('refresh-recent', { bubbles: true })))
        .catch(() => undefined);
});

// Login modal: the header "Anmelden" link opens an overlay and loads the login
// form fragment (htmx GET /login) instead of navigating. A failed login swaps
// the form back in with the error; a successful one returns HX-Redirect (htmx
// navigates natively). Close on backdrop, ×, or Escape.
document.addEventListener('click', (event: MouseEvent) => {
    // Auth modal: login and register share one overlay; the trigger's
    // data-modal-url decides which form fragment is loaded.
    const opener = (event.target as HTMLElement).closest<HTMLElement>('.js-authModalOpen');
    if (opener) {
        event.preventDefault();
        const modal = document.getElementById('js-loginModal');
        const body = document.getElementById('js-loginModalBody');
        if (!modal || !body) {
            return;
        }
        modal.removeAttribute('hidden');
        window.htmx.ajax('GET', opener.getAttribute('data-modal-url') ?? '/login', {
            target: body,
            swap: 'innerHTML',
        });

        return;
    }
    const closer = (event.target as HTMLElement).closest('.js-modal-close');
    if (closer) {
        event.preventDefault();
        closer.closest('.island-modal')?.setAttribute('hidden', '');
    }
});

document.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.island-modal').forEach((m) => m.setAttribute('hidden', ''));
    }
});

// --- Smart insert overlay + paste-to-embed ---------------------------------
// Turn a URL into the right BBCode: YouTube → embedded iframe (matching the
// SPA's youtube-nocookie format), image/video/audio by extension, else a link.
function youtubeId(url: string): string | null {
    const be = url.match(/youtu\.be\/([\w-]+)/);
    if (be) {
        return be[1];
    }
    const wa = url.match(/youtube\.com\/.*[?&]v=([\w-]+)/);
    return wa ? wa[1] : null;
}

function urlToBbcode(url: string, text: string): { type: string; bbcode: string } {
    const u = url.trim();
    if (!u) {
        return { type: '', bbcode: '' };
    }
    const id = youtubeId(u);
    if (id) {
        return {
            type: 'YouTube',
            bbcode: `[iframe src=//www.youtube-nocookie.com/embed/${id} allowfullscreen=allowfullscreen`
                + ` frameborder=0 height=315 width=560][/iframe]`,
        };
    }
    if (/\.(png|gif|jpe?g|webp|svg)([/?#]|$)/i.test(u)) {
        return { type: 'Bild', bbcode: `[img]${u}[/img]` };
    }
    if (/\.(mp4|webm|m4v)([/?#]|$)/i.test(u)) {
        return { type: 'Video', bbcode: `[video]${u}[/video]` };
    }
    if (/\.(m4a|ogg|mp3|wav|opus)([/?#]|$)/i.test(u)) {
        return { type: 'Audio', bbcode: `[audio]${u}[/audio]` };
    }
    const label = text.trim();
    return { type: 'Link', bbcode: label ? `[url=${u}]${label}[/url]` : `[url]${u}[/url]` };
}

function insertAtCursor(ta: HTMLTextAreaElement, text: string): void {
    const start = ta.selectionStart ?? ta.value.length;
    const end = ta.selectionEnd ?? ta.value.length;
    ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + text.length;
    ta.focus();
}

let insertTarget: HTMLTextAreaElement | null = null;
let insertPreviewUrl = '/entries/htmx-preview';
let insertPreviewTimer = 0;

function refreshInsert(): void {
    const urlEl = document.getElementById('js-insertUrl') as HTMLInputElement | null;
    const textEl = document.getElementById('js-insertText') as HTMLInputElement | null;
    const textRow = document.querySelector('.js-insertTextRow');
    const typeEl = document.querySelector('.js-insertType');
    const previewEl = document.querySelector<HTMLElement>('.js-insertPreview');
    const confirmBtn = document.querySelector<HTMLButtonElement>('.js-insertConfirm');
    if (!urlEl || !typeEl || !previewEl || !confirmBtn) {
        return;
    }
    const { type, bbcode } = urlToBbcode(urlEl.value, textEl?.value ?? '');
    textRow?.toggleAttribute('hidden', type !== 'Link');
    typeEl.textContent = type ? `→ ${type}` : '';
    confirmBtn.disabled = !bbcode;
    confirmBtn.dataset.bbcode = bbcode;

    window.clearTimeout(insertPreviewTimer);
    if (!bbcode) {
        previewEl.innerHTML = '';

        return;
    }
    insertPreviewTimer = window.setTimeout(() => {
        void fetch(insertPreviewUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({ text: bbcode }).toString(),
            credentials: 'same-origin',
        })
            .then((r) => r.text())
            .then((html) => {
                previewEl.innerHTML = html;
            })
            .catch(() => undefined);
    }, 350);
}

// Open the overlay from a toolbar Link/Media button.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-insertOpen');
    if (!btn) {
        return;
    }
    event.preventDefault();
    insertTarget = btn.closest('form')?.querySelector<HTMLTextAreaElement>('textarea[name="text"]') ?? null;
    insertPreviewUrl = btn.getAttribute('data-preview-url') ?? '/entries/htmx-preview';
    const urlEl = document.getElementById('js-insertUrl') as HTMLInputElement | null;
    const textEl = document.getElementById('js-insertText') as HTMLInputElement | null;
    if (urlEl) {
        urlEl.value = '';
    }
    if (textEl) {
        textEl.value = '';
    }
    document.getElementById('js-insertModal')?.removeAttribute('hidden');
    refreshInsert();
    urlEl?.focus();
});

// Live-update as the URL / text changes.
document.addEventListener('input', (event: Event) => {
    const id = (event.target as HTMLElement).id;
    if (id === 'js-insertUrl' || id === 'js-insertText') {
        refreshInsert();
    }
});

// Confirm → drop the BBCode into the editor.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLButtonElement>('.js-insertConfirm');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const bbcode = btn.dataset.bbcode ?? '';
    if (insertTarget && bbcode) {
        insertAtCursor(insertTarget, bbcode);
    }
    document.getElementById('js-insertModal')?.setAttribute('hidden', '');
});

// Paste-to-embed: pasting a bare media URL into an island editor auto-embeds it
// (plain links and multi-word text paste normally).
document.addEventListener('paste', (event: ClipboardEvent) => {
    const ta = event.target as HTMLElement;
    if (!(ta instanceof HTMLTextAreaElement)) {
        return;
    }
    if (!ta.closest('form')?.querySelector('.js-editor-toolbar')) {
        return;
    }
    const pasted = event.clipboardData?.getData('text')?.trim() ?? '';
    if (!pasted || /\s/.test(pasted)) {
        return;
    }
    const { type, bbcode } = urlToBbcode(pasted, '');
    if (type === 'YouTube' || type === 'Bild' || type === 'Video' || type === 'Audio') {
        event.preventDefault();
        insertAtCursor(ta, bbcode);
    }
});

Alpine.start();
