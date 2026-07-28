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
    const start = ta.selectionStart ?? ta.value.length;
    const end = ta.selectionEnd ?? ta.value.length;
    ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + text.length;
    ta.focus();
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
