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
import { bbcodeAddsSomething, htmlToBbcode } from '../lib/htmlToBbcode';
import { renderEmbeds } from './embeds';

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

/**
 * The same address as a preview card instead of a link.
 *
 * `[embed]` makes the server fetch the page and read its Open Graph data —
 * title, teaser, picture — which the reader then sees instead of a bare
 * address. It has no label of its own: the card carries the page's own words.
 */
function urlToCard(url: string): string {
    const trimmed = url.trim();

    return trimmed ? `[embed]${trimmed}[/embed]` : '';
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

    // The label belongs to a link, so it is hidden for an image or a video —
    // but not before anything has been typed. With an empty address the type is
    // still undecided, and hiding the row then would swallow a label carried in
    // from the editor's selection: the writer selects a word, opens this, and
    // the field holding that word is nowhere to be seen until an address
    // happens to be entered.
    const undecided = type === '';
    const carriesLabel = (textEl?.value ?? '') !== '';
    textRow?.toggleAttribute('hidden', undecided ? !carriesLabel : type !== 'Link');

    // A card is offered for an ordinary link only. An image or a video is shown
    // as itself already, and a card about it would say the same thing twice.
    const cardRow = document.querySelector('.js-insertCardRow');
    const cardEl = document.getElementById('js-insertCard') as HTMLInputElement | null;
    cardRow?.toggleAttribute('hidden', type !== 'Link');
    const asCard = type === 'Link' && cardEl?.checked === true;
    // The label belongs to a link; a card carries the page's own words, so the
    // field has nothing to say once the card is chosen.
    if (asCard) {
        textRow?.setAttribute('hidden', '');
    }
    typeEl.textContent = type ? `→ ${type}` : '';
    const finalBbcode = asCard ? urlToCard(urlEl.value) : bbcode;
    confirmBtn.disabled = !finalBbcode;
    confirmBtn.dataset.bbcode = finalBbcode;

    window.clearTimeout(insertPreviewTimer);
    if (!finalBbcode) {
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
            body: new URLSearchParams({ text: finalBbcode }).toString(),
            credentials: 'same-origin',
        })
            .then((r) => r.text())
            .then((html) => {
                previewEl.innerHTML = html;
                // A card is built here, not by the server: the fragment carries
                // only the placeholder, so without this the preview of a card
                // would be an empty box.
                renderEmbeds(previewEl);
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

    // Whatever is selected in the editor is almost always what the insert is
    // about, so it starts the dialogue off rather than being retyped. Which
    // field it belongs in depends on what it is: an address goes to the address
    // field, anything else becomes the link's label.
    //
    // Nothing has to be done about the selection itself — insertAtCursor()
    // replaces it, so the words are not left standing beside the link they were
    // turned into.
    const selected = insertTarget
        ? insertTarget.value.slice(insertTarget.selectionStart, insertTarget.selectionEnd).trim()
        : '';
    const selectionIsUrl = /^(https?:\/\/|www\.)\S+$/i.test(selected);

    if (urlEl) {
        urlEl.value = selectionIsUrl ? selected : '';
    }
    if (textEl) {
        textEl.value = selectionIsUrl ? '' : selected;
    }
    const cardEl = document.getElementById('js-insertCard') as HTMLInputElement | null;
    if (cardEl) {
        cardEl.checked = false;
    }
    document.getElementById('js-insertModal')?.removeAttribute('hidden');
    refreshInsert();

    // Land on the field still to be filled rather than on the one just answered
    // — but only if it is on screen. refreshInsert() hides the label row for
    // anything that is not a link, so an image address selected in the editor
    // leaves nothing further to say and the address field keeps the focus.
    const labelRowVisible = textEl !== null
        && document.querySelector('.js-insertTextRow')?.hasAttribute('hidden') === false;
    if (selectionIsUrl && labelRowVisible) {
        textEl?.focus();
    } else {
        urlEl?.focus();
    }
});

// Live-update as the URL / text changes.
document.addEventListener('input', (event: Event) => {
    const id = (event.target as HTMLElement).id;
    if (id === 'js-insertUrl' || id === 'js-insertText' || id === 'js-insertCard') {
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
    const plain = event.clipboardData?.getData('text') ?? '';

    // Copied from a web page: keep the links and the emphasis instead of
    // flattening them to text the writer then has to mark up again.
    //
    // "Paste and match style" puts no `text/html` on the clipboard at all, so
    // that gesture keeps meaning what it says without needing a rule here.
    const html = event.clipboardData?.getData('text/html') ?? '';
    if (html) {
        const bbcode = htmlToBbcode(html);
        if (bbcodeAddsSomething(bbcode, plain)) {
            event.preventDefault();
            insertAtCursor(ta, bbcode);

            return;
        }
    }

    // A bare URL on its own still becomes the tag that suits what it points at.
    const pasted = plain.trim();
    if (!pasted || /\s/.test(pasted)) {
        return;
    }
    const { type, bbcode } = urlToBbcode(pasted, '');
    if (type === 'YouTube' || type === 'Bild' || type === 'Video' || type === 'Audio') {
        event.preventDefault();
        insertAtCursor(ta, bbcode);
    }
});
