/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Small helpers shared across the island features.
 */

/**
 * The CSRF token for session-authed write requests (bookmark/solve/delete).
 */
export function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    );
}

/**
 * Insert text at the caret and leave the caret after it.
 *
 * @param ta the textarea to write into
 * @param text what to insert
 */
export function insertAtCursor(ta: HTMLTextAreaElement, text: string): void {
    ta.focus();

    // `execCommand` is deprecated and still the only way to put text into a
    // textarea *as though it were typed*: the browser records it on its own undo
    // stack, so Ctrl/Cmd-Z takes it back again. Assigning `value` — which is what
    // this did — replaces the field's contents wholesale and throws that history
    // away, leaving undo silently doing nothing after a smiley, a toolbar tag or
    // a pasted link.
    //
    // It returns false where it is not supported, and that is the only case the
    // manual path below is for.
    let inserted: boolean;
    try {
        inserted = document.execCommand('insertText', false, text);
    } catch {
        inserted = false;
    }
    if (inserted) {
        return;
    }

    const start = ta.selectionStart ?? ta.value.length;
    const end = ta.selectionEnd ?? ta.value.length;
    ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + text.length;
    // Anything listening for a change — the auto-growing editor, for one — hears
    // nothing from a direct assignment, so say it explicitly.
    ta.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * Run once the document is parsed — now if it already is.
 *
 * Four features needed this and each spelled the readyState dance out again.
 *
 * @param fn what to run
 */
export function onReady(fn: () => void): void {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
}

/**
 * Is this click one the browser should handle itself?
 *
 * Command or Ctrl opens a link in a new tab, Shift in a new window, Alt saves
 * the target, and the middle button opens a background tab. A handler that
 * calls `preventDefault()` on those takes all of it away — which is exactly how
 * the thread list lost "open in a new tab": it intercepted every click and then
 * navigated by hand, doing what the browser would have done anyway, only worse.
 *
 * The old Backbone view got this right by accident rather than by checking: it
 * only ever intercepted the click it actually needed, and let the anchor do its
 * own work otherwise.
 */
export function isModifiedClick(event: MouseEvent): boolean {
    return event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
}
