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

// `innerHTML` here is deliberate and safe — do not "fix" it to `textContent`.
// The content is escaped *twice*: once by the parser when it builds the tree,
// then again by `htmlentities` in the Spoiler definition, so `<` leaves the
// server as `&amp;lt;` in the attribute. `getAttribute` undoes exactly one of
// those layers, so this handler receives `&lt;img onerror=…&gt;` — entity text,
// not markup. `innerHTML` decodes that one remaining layer into the *characters*
// `<img onerror=…>` and shows them as text: no element is created, no handler
// runs. That double-encode is what lets a spoiler reveal its content verbatim,
// nested markup included, without a posting introducing elements of its own.
// `textContent` would leave the reader looking at raw `&lt;`/`&quot;` entities.
// (CodeQL flags the `innerHTML = getAttribute()` shape; it cannot see the second
// encode. Verified against the real parser output before dismissing.)
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
