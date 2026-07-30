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
 * Say "copied" on the button itself for a moment — there is nowhere else to put
 * the confirmation.
 *
 * @param btn the copy button
 */
function confirmOnButton(btn: HTMLButtonElement): void {
    const original = btn.textContent ?? '';
    btn.textContent = btn.getAttribute('data-copied-label') ?? original;
    window.setTimeout(() => {
        btn.textContent = original;
    }, 1500);
}

/**
 * Put the field's value on the clipboard.
 *
 * `navigator.clipboard` exists only in a secure context, so the older path is not
 * hypothetical — it is what runs on an installation served over plain http. The
 * text is selected either way, so Ctrl-C still works if both fail.
 *
 * @param field the address field, already selected
 * @param btn the button to confirm on
 */
function copyToClipboard(field: HTMLInputElement, btn: HTMLButtonElement): void {
    const viaSelection = (): void => {
        try {
            // skipcq: JS-0257
            document.execCommand('copy');
            confirmOnButton(btn);
        } catch {
            /* nothing left to try */
        }
    };

    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(field.value).then(() => confirmOnButton(btn), viaSelection);

        return;
    }
    viaSelection();
}

/** The copy button beside a feed address. */
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLButtonElement>('.js-feed-copy');
    const field = btn?.closest('.feed-links-actions')?.querySelector<HTMLInputElement>('.js-feed-url');
    if (!btn || !field) {
        return;
    }
    field.select();
    copyToClipboard(field, btn);
});
