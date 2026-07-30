/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The feed-address fields: select on click, and the copy button.
 *
 * Both used to live in the Feeds cell — the copy handler as an inline
 * `scriptBlock`, the selecting as an `onclick` attribute. Inline script of either
 * kind is what dropping `'unsafe-inline'` from the content-security policy
 * forbids, and neither needed to be there: the cell renders on island pages, so
 * the bundle that carries this is already loaded.
 *
 * A note on the `onclick`, because its comment claimed otherwise: it was
 * described as the fallback for readers without JavaScript. It cannot be — an
 * event attribute is JavaScript. Without it the field is still readonly and still
 * selectable by hand, which is the actual fallback and needs no code.
 */

/** Feed address fields select their whole contents when clicked. */
document.addEventListener('click', (event: MouseEvent) => {
    const field = (event.target as HTMLElement).closest<HTMLInputElement>('.js-feed-url');
    if (field) {
        field.select();
    }
});

/**
 * Copy the address beside the button, and say so on the button itself for a
 * moment — there is nowhere else to put the confirmation.
 */
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLButtonElement>('.js-feed-copy');
    if (!btn) {
        return;
    }
    const field = btn.closest('.feed-links-actions')?.querySelector<HTMLInputElement>('.js-feed-url');
    if (!field) {
        return;
    }
    field.select();

    const confirm = (): void => {
        const original = btn.textContent ?? '';
        btn.textContent = btn.getAttribute('data-copied-label') ?? original;
        window.setTimeout(() => {
            btn.textContent = original;
        }, 1500);
    };

    /** The pre-clipboard-API way, and the only one over plain http. */
    const copyViaSelection = (): void => {
        try {
            // skipcq: JS-0257
            document.execCommand('copy');
            confirm();
        } catch {
            /* nothing left to try; the text is selected, so Ctrl-C still works */
        }
    };

    // navigator.clipboard exists only in a secure context, so the fallback is not
    // hypothetical — it is what runs on an installation served over http.
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(field.value).then(confirm, copyViaSelection);

        return;
    }
    copyViaSelection();
});
