/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The posting editor's toolbar: BBCode buttons, the smiley picker and the
 * preview.
 */
import { csrfToken, insertAtCursor } from '../lib/dom';

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
    fetch(url, {
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
