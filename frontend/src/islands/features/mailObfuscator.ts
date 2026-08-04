/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * `[email]`: reassemble the address the server split across two attributes.
 *
 * The parser emits `<a class="js-mailObfuscated" data-ttl data-dom>` with the
 * local part and domain in the two attributes, and the address never appears in
 * the markup a scraper sees. Here they are joined and the `mailto:` link built.
 *
 * `textContent`, not `innerHTML`: `dataset` returns the values decoded, so the
 * server's escaping is already undone by the time we read them. Writing them
 * back as text puts them on the page as characters — writing them as HTML would
 * reproduce the stored-XSS this replaced. The scheme is hard-coded to `mailto:`,
 * so a posting cannot smuggle a `javascript:` link in either.
 *
 * Runs on load and after every htmx swap, because a posting containing a mail
 * link can arrive at either point.
 */

function revealMailLinks(root: ParentNode): void {
    root.querySelectorAll<HTMLAnchorElement>('a.js-mailObfuscated').forEach((el) => {
        const local = el.dataset.ttl ?? '';
        const domain = el.dataset.dom ?? '';
        if (local === '' || domain === '') {
            return;
        }

        const address = `${local}@${domain}`;
        el.href = `mailto:${address}`;

        // An [email] with no title renders an empty link; fill it with the
        // address as text. One that carried a title keeps it.
        if (el.textContent === '') {
            el.textContent = address;
        }

        // Done — drop the parts so a second pass (or a scraper reading the live
        // DOM) finds nothing to reassemble.
        el.classList.remove('js-mailObfuscated');
        delete el.dataset.ttl;
        delete el.dataset.dom;
    });
}

revealMailLinks(document);

document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as ParentNode | undefined;
    revealMailLinks(target ?? document);
});
