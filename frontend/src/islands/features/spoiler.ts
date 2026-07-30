/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * `[spoiler]`: reveal the hidden text on click.
 *
 * The parser used to ship an inline `onclick` doing exactly this, which depended
 * on the CSP allowing 'unsafe-inline'. Delegated from the document, so it also
 * covers postings swapped in by htmx after load.
 */

// The attribute holds HTML-escaped text (see the Spoiler code definition), so
// assigning it as innerHTML reproduces the old behaviour — entities render as
// characters — without letting a posting introduce elements of its own.
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLElement>('.js-spoiler');
    if (!link) {
        return;
    }
    event.preventDefault();
    const container = link.parentElement;
    if (!container) {
        return;
    }
    container.innerHTML = link.getAttribute('data-spoiler') ?? '';
});
