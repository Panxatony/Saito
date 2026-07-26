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
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => applyDefaultThreadCollapse());
} else {
    applyDefaultThreadCollapse();
}

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
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoDismissFlashes);
} else {
    autoDismissFlashes();
}

// Smiley picker: the toolbar button toggles a panel; clicking a smiley inserts
// its code at the cursor (panel stays open for picking several).
document.addEventListener('click', (event: MouseEvent) => {
    const toggle = (event.target as HTMLElement).closest<HTMLElement>('.js-smiley-toggle');
    if (toggle) {
        event.preventDefault();
        const panel = toggle.closest('.js-editor-toolbar')?.querySelector<HTMLElement>('.js-smiley-panel');
        if (panel) {
            panel.hidden = !panel.hidden;
        }

        return;
    }
    const smiley = (event.target as HTMLElement).closest<HTMLElement>('.js-smiley');
    if (!smiley) {
        return;
    }
    event.preventDefault();
    const textarea = smiley.closest('form')?.querySelector<HTMLTextAreaElement>('textarea[name="text"]');
    if (textarea) {
        insertAtCursor(textarea, `${smiley.getAttribute('data-code') ?? ''} `);
    }
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
    updateUploadInsertBtn();
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
    if (files?.length) {
        void uploadFiles(Array.from(files));
    }
});

function updateUploadInsertBtn(): void {
    const btn = document.querySelector<HTMLButtonElement>('.js-uploadInsert');
    if (btn) {
        btn.disabled = !document.querySelector('.js-uploadTile.is-selected');
    }
}

// The profile's upload manager has no "insert" button — selecting tiles there is
// for deleting several at once. Keep its button in step with the selection and
// show the count, so it is obvious how much is about to go.
function updateUploadsDeleteBtn(): void {
    const btn = document.querySelector<HTMLButtonElement>('.js-uploadsDeleteSelected');
    if (!btn) {
        return;
    }
    const count = document.querySelectorAll('.js-uploadManageGrid .js-uploadTile.is-selected').length;
    btn.disabled = count === 0;
    const label = btn.getAttribute('data-label') ?? '';
    const icon = btn.querySelector('i');
    btn.textContent = count > 0 ? ` ${label} (${count})` : ` ${label}`;
    if (icon) {
        btn.prepend(icon);
    }
}

// Clicking an archive tile toggles its selection (what happens with the
// selection depends on where the grid sits: insert in the editor overlay,
// delete in the profile).
document.addEventListener('click', (event: MouseEvent) => {
    const tile = (event.target as HTMLElement).closest<HTMLElement>('.js-uploadTile');
    if (!tile) {
        return;
    }
    event.preventDefault();
    tile.classList.toggle('is-selected');
    updateUploadInsertBtn();
    updateUploadsDeleteBtn();
});

// "Delete selection" in the profile: confirm once for the whole batch, then
// remove each upload and drop its tile. Deleting is per-upload on the server, so
// a single failure leaves the rest done and that tile in place.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-uploadsDeleteSelected');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const tiles = Array.from(
        document.querySelectorAll<HTMLElement>('.js-uploadManageGrid .js-uploadTile.is-selected')
    );
    if (!tiles.length || !window.confirm(btn.getAttribute('data-confirm') ?? 'Delete?')) {
        return;
    }
    btn.disabled = true;
    void Promise.all(tiles.map((tile) => {
        const id = tile.getAttribute('data-upload-id');
        if (!id) {
            return Promise.resolve();
        }

        return fetch(`/entries/htmx-upload-delete/${id}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (response.ok) {
                    tile.closest('.js-uploadManageItem')?.remove();
                }
            })
            .catch(() => undefined);
    })).then(() => updateUploadsDeleteBtn());
});

// "Insert selected" → insert every selected tile's BBCode, then close.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest('.js-uploadInsert');
    if (!btn || !uploadTarget) {
        return;
    }
    event.preventDefault();
    document.querySelectorAll<HTMLElement>('.js-uploadTile.is-selected').forEach((tile) => {
        insertUploadTag(uploadTarget as HTMLTextAreaElement, tile.getAttribute('data-name') ?? '', tile.getAttribute('data-mime') ?? '');
    });
    document.getElementById('js-uploadModal')?.setAttribute('hidden', '');
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
    } catch {
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
    let data: { isBookmarked?: boolean; showSolvedBtn?: boolean; solves?: number; pid?: number } = {};
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
    paintBkm(Boolean(data.isBookmarked));
    bkm.addEventListener('click', () => {
        void fetch(`/entries/htmx-bookmark/${id}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((j: { bookmarked?: boolean }) => paintBkm(Boolean(j.bookmarked)))
            .catch(() => undefined);
    });
    group.appendChild(bkm);

    // "Mark as helpful" — only when the server says the user may set it (the
    // thread starter), and only on answers, not the opening post (pid > 0).
    if (data.showSolvedBtn && data.pid) {
        const solve = document.createElement('button');
        solve.type = 'button';
        solve.className = 'btn btn-link js-island-solve';
        const paintSolve = (on: boolean): void => {
            solve.innerHTML = `<i class="fa fa-check-circle${on ? '' : '-o'}"></i>`;
            solve.setAttribute('aria-pressed', on ? 'true' : 'false');
        };
        let solved = Boolean(data.solves);
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

// Full page loads (the thread read view) fire no htmx swap, so enhance the
// postings already present in the DOM once the page is ready.
function enhanceExistingPostings(): void {
    document.querySelectorAll<HTMLElement>('.js-thread-island .js-entry-view-core')
        .forEach((el) => enhancePosting(el));
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceExistingPostings);
} else {
    enhanceExistingPostings();
}

// Posting tool menu (pin / lock / merge / delete) is a Bootstrap-4 dropdown, but
// the island loads no Bootstrap JS — so toggle `.show` ourselves. Open on the
// wrench button, close on outside click / item select / Escape.
function closeIslandDropdowns(): void {
    document.querySelectorAll('.js-thread-island .dropdown-menu.show')
        .forEach((m) => m.classList.remove('show'));
}
document.addEventListener('click', (event: MouseEvent) => {
    const toggle = (event.target as HTMLElement).closest<HTMLElement>('[data-toggle="dropdown"]');
    if (toggle?.closest('.js-thread-island')) {
        event.preventDefault();
        const menu = toggle.parentElement?.querySelector<HTMLElement>('.dropdown-menu') ?? null;
        const willOpen = Boolean(menu) && !menu.classList.contains('show');
        closeIslandDropdowns();
        if (willOpen && menu) {
            menu.classList.add('show');
        }

        return;
    }
    // Any other click (including selecting a menu item) closes open menus.
    closeIslandDropdowns();
});
document.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeIslandDropdowns();
    }
});

// Keep edit / merge inside the island: the shared posting template renders
// classic links (/entries/edit|merge/<id>) that would drop into the SPA. Point
// them at the island equivalents instead (replace in place to keep any webroot
// prefix).
document.addEventListener('click', (event: MouseEvent) => {
    const anchor = (event.target as HTMLElement).closest<HTMLAnchorElement>('a[href]');
    if (!anchor?.closest('.js-thread-island')) {
        return;
    }
    const href = anchor.getAttribute('href') ?? '';
    if (/\/entries\/edit\/\d+/.test(href)) {
        event.preventDefault();
        window.location.href = href.replace(/\/entries\/edit\/(\d+)/, '/entries/htmx-edit/$1');
    } else if (/\/entries\/merge\/\d+/.test(href)) {
        event.preventDefault();
        window.location.href = href.replace(/\/entries\/merge\/(\d+)/, '/entries/htmx-merge/$1');
    }
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

// Right-rail widgets: collapse/expand per widget, remembered in localStorage.
// The sidebar htmx-reloads (poll / refresh-recent), so the collapsed state is
// re-applied after every swap of freshly-rendered widgets.
const WIDGETS_KEY = 'islandWidgetsCollapsed';
function collapsedWidgets(): string[] {
    try {
        return JSON.parse(localStorage.getItem(WIDGETS_KEY) ?? '[]') as string[];
    } catch {
        return [];
    }
}
function applyWidgetCollapse(root?: HTMLElement): void {
    const collapsed = collapsedWidgets();
    (root ?? document).querySelectorAll<HTMLElement>('.island-widget').forEach((w) => {
        const id = w.getAttribute('data-widget') ?? '';
        w.classList.toggle('is-collapsed', collapsed.includes(id));
    });
}
document.addEventListener('click', (event: MouseEvent) => {
    const head = (event.target as HTMLElement).closest<HTMLElement>('.js-widgetToggle');
    if (!head) {
        return;
    }
    event.preventDefault();
    const widget = head.closest<HTMLElement>('.island-widget');
    if (!widget) {
        return;
    }
    const id = widget.getAttribute('data-widget') ?? '';
    const nowCollapsed = widget.classList.toggle('is-collapsed');
    const list = collapsedWidgets().filter((x) => x !== id);
    if (nowCollapsed) {
        list.push(id);
    }
    try {
        localStorage.setItem(WIDGETS_KEY, JSON.stringify(list));
    } catch {
        /* localStorage unavailable */
    }
});
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    if (target && (target.classList?.contains('island-sidebar') || target.querySelector?.('.island-widget'))) {
        applyWidgetCollapse(target);
    }
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

// Category chooser: reload the thread list filtered to the chosen category
// (htmx-index honours ?category and returns the filtered page-1 fragment).
document.addEventListener('change', (event: Event) => {
    const sel = (event.target as HTMLElement).closest<HTMLSelectElement>('.js-categoryChooser');
    if (!sel) {
        return;
    }
    const list = document.getElementById('js-threadList');
    if (!list) {
        return;
    }
    const base = sel.getAttribute('data-list-url') ?? '/entries/htmx-index';
    window.htmx.ajax('GET', `${base}?category=${encodeURIComponent(sel.value)}`, {
        target: list,
        swap: 'innerHTML',
    });
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
    // Help overlay: static content already in the DOM — just reveal it.
    const helpOpener = (event.target as HTMLElement).closest<HTMLElement>('.js-helpOpen');
    if (helpOpener) {
        event.preventDefault();
        document.getElementById('js-helpModal')?.removeAttribute('hidden');

        return;
    }
    // RSS overlay (public feeds) — also static, just reveal it.
    const rssOpener = (event.target as HTMLElement).closest<HTMLElement>('.js-rssOpen');
    if (rssOpener) {
        event.preventDefault();
        document.getElementById('js-rssModal')?.removeAttribute('hidden');

        return;
    }
    // Contact-owner overlay: open the modal and htmx-load the form fragment.
    const contactOpener = (event.target as HTMLElement).closest<HTMLElement>('.js-contactModalOpen');
    if (contactOpener) {
        event.preventDefault();
        const modal = document.getElementById('js-contactModal');
        const body = document.getElementById('js-contactModalBody');
        if (modal && body) {
            modal.removeAttribute('hidden');
            window.htmx.ajax('GET', contactOpener.getAttribute('data-modal-url') ?? '/contacts/htmx-contact-owner', {
                target: body,
                swap: 'innerHTML',
            });
        }

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
    const trimmedUrl = url.trim();
    if (!trimmedUrl) {
        return { type: '', bbcode: '' };
    }
    const id = youtubeId(trimmedUrl);
    if (id) {
        return {
            type: 'YouTube',
            bbcode: `[iframe src=//www.youtube-nocookie.com/embed/${id} allowfullscreen=allowfullscreen`
                + ' frameborder=0 height=315 width=560][/iframe]',
        };
    }
    if (/\.(png|gif|jpe?g|webp|svg)([/?#]|$)/i.test(trimmedUrl)) {
        return { type: 'Bild', bbcode: `[img]${trimmedUrl}[/img]` };
    }
    if (/\.(mp4|webm|m4v)([/?#]|$)/i.test(trimmedUrl)) {
        return { type: 'Video', bbcode: `[video]${trimmedUrl}[/video]` };
    }
    if (/\.(m4a|ogg|mp3|wav|opus)([/?#]|$)/i.test(trimmedUrl)) {
        return { type: 'Audio', bbcode: `[audio]${trimmedUrl}[/audio]` };
    }
    const label = text.trim();
    return { type: 'Link', bbcode: label ? `[url=${trimmedUrl}]${label}[/url]` : `[url]${trimmedUrl}[/url]` };
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
