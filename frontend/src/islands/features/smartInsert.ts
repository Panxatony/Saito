/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Turning a URL into the right BBCode: the insert overlay and paste-to-embed.
 */
import { csrfToken, insertAtCursor } from '../lib/dom';

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
        fetch(insertPreviewUrl, {
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
