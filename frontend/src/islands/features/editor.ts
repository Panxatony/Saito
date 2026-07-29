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
    const selected = textarea.value.slice(start, textarea.selectionEnd);

    // Through insertAtCursor rather than by assigning `value`: the assignment
    // wiped the browser's undo history, so Ctrl-Z after clicking **B** did
    // nothing — and could not get back what had been typed before it either.
    // insertText replaces the selection, which is exactly the wrapping wanted.
    insertAtCursor(textarea, open + selected + close);

    // Leave the wrapped text selected, so a second tag can be applied to it.
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

/**
 * The preview panel belonging to a form.
 *
 * It sits *before* the form rather than inside it, so it cannot be found by
 * searching the form's own subtree — `previousElementSibling` walks back to it.
 * The edit view wraps its form in a panel, so the lookup climbs one level when
 * the panel is not a direct sibling.
 */
function previewPanelFor(form: HTMLElement): HTMLElement | null {
    let node: Element | null = form;
    while (node) {
        const sibling = node.previousElementSibling;
        if (sibling?.classList.contains('js-editorPreviewPanel')) {
            return sibling as HTMLElement;
        }
        node = node.parentElement;
        if (node === document.body) {
            break;
        }
    }

    return null;
}

/**
 * What the preview needs to know about the posting being written.
 *
 * Subject and category travel with the text so the preview can show the
 * posting's heading and info line, not just its body. Both are optional, and
 * each form supplies them differently — which is the whole reason this is worth
 * a function of its own rather than another eight branches in the click
 * handler.
 */
function previewPayload(form: HTMLElement, textarea: HTMLTextAreaElement): URLSearchParams {
    const body = new URLSearchParams({ text: textarea.value });

    const subject = form.querySelector<HTMLInputElement>('input[name="subject"]')?.value;
    if (subject) {
        body.append('subject', subject);
    }

    // From the chooser when the form has one (a new thread, or editing a thread
    // starter). A reply has no chooser — it inherits its parent's — and neither
    // does editing an answer, so those forms carry it as a data attribute.
    const category = form.querySelector<HTMLSelectElement>('[name="category_id"]')?.value
        || form.dataset.previewCategory;
    if (category && category !== '0') {
        body.append('categoryId', category);
    }

    // Nought for a posting that does not exist yet, the real count when an
    // existing one is being edited.
    body.append('views', form.dataset.previewViews ?? '0');

    return body;
}

// Editor preview: render what the writer has so far through htmxPreview and
// show it in the panel above the form. Wired explicitly (not via htmx's fragile
// `next` target inside the hx-post form) so it works in the inline editor too.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-bb-preview');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const form = btn.closest<HTMLElement>('form');
    const textarea = form?.querySelector<HTMLTextAreaElement>('textarea[name="text"]');
    const panel = form ? previewPanelFor(form) : null;
    const box = panel?.querySelector<HTMLElement>('.js-editor-preview');
    if (!form || !textarea || !panel || !box) {
        return;
    }

    const body = previewPayload(form, textarea);
    const url = btn.getAttribute('data-preview-url') ?? '/entries/htmx-preview';
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
            // An empty response means there was nothing worth previewing; keep
            // the panel shut rather than opening an empty frame.
            panel.hidden = html.trim() === '';
            if (!panel.hidden) {
                panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        })
        .catch(() => undefined);
});

// Closing the preview is a plain hide — the next press of "Preview" refills it.
document.addEventListener('click', (event: MouseEvent) => {
    const close = (event.target as HTMLElement).closest<HTMLElement>('.js-editorPreviewClose');
    const panel = close?.closest<HTMLElement>('.js-editorPreviewPanel');
    if (panel) {
        event.preventDefault();
        panel.hidden = true;
    }
});

/**
 * Let a text box grow with what is written in it.
 *
 * The editor starts at a few rows, which is right for a one-line answer and
 * wrong the moment somebody writes a paragraph: the text scrolls inside a small
 * window and the writer loses sight of what they said. Height is reset before
 * measuring, or `scrollHeight` keeps reporting the previous, larger box and the
 * field can only ever grow.
 *
 * Capped at 80vh so a long posting still leaves the buttons below it reachable
 * without scrolling the page to the bottom first.
 */
function autoGrow(textarea: HTMLTextAreaElement): void {
    textarea.style.height = 'auto';
    const max = Math.round(window.innerHeight * 0.8);
    textarea.style.height = `${Math.min(textarea.scrollHeight, max)}px`;
    textarea.style.overflowY = textarea.scrollHeight > max ? 'auto' : 'hidden';
}

document.addEventListener('input', (event: Event) => {
    const el = event.target as HTMLElement;
    if (el instanceof HTMLTextAreaElement && el.name === 'text') {
        autoGrow(el);
    }
});

// Also on arrival: an edit form opens with the existing posting already in it,
// and htmx swaps these forms in after page load, so a one-off pass at startup
// would miss them.
function growExisting(root: ParentNode): void {
    root.querySelectorAll<HTMLTextAreaElement>('textarea[name="text"]').forEach(autoGrow);
}
document.addEventListener('DOMContentLoaded', () => growExisting(document));
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    if (target) {
        growExisting(target);
    }
});
