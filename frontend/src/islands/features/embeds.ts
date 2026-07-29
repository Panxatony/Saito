/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Preview cards for `[embed]` links.
 *
 * The server side of this has existed and worked all along: `[embed]` fetches
 * the page through the SSRF-guarded client, reads its Open Graph data and puts
 * the result into `data-embed` on an empty `<div class="js-embed">`. What was
 * missing is this — whatever turned that into a card went away with the
 * Backbone frontend, so the forum has been fetching previews and showing
 * nothing since.
 *
 * The payload also carries a `html` field: markup the far end supplied for
 * embedding itself. It is deliberately **not used**. Nobody sanitises it, and
 * writing a third party's HTML into the page is how a link in a posting becomes
 * script running on the forum. The card is built from the individual fields
 * instead — text as text, and an image only from an address we have checked.
 */

interface EmbedData {
    description?: string;
    image?: string;
    providerIcon?: string;
    providerName?: string;
    providerUrl?: string;
    title?: string;
    url?: string;
}

/** Addresses worth putting in an `href` or `src`. Anything else is dropped. */
function isSafeUrl(url: string | undefined): url is string {
    return typeof url === 'string' && /^https?:\/\//i.test(url.trim());
}

/** An element with text in it, or nothing when there is no text. */
function textNode(tag: string, className: string, text: string | undefined): HTMLElement | null {
    const value = (text ?? '').trim();
    if (!value) {
        return null;
    }
    const el = document.createElement(tag);
    el.className = className;
    // textContent, never innerHTML: the words come from a page we do not
    // control, and they are words, not markup.
    el.textContent = value;

    return el;
}

function buildCard(data: EmbedData): HTMLElement | null {
    const href = isSafeUrl(data.url) ? data.url.trim() : '';
    if (!href) {
        return null;
    }

    const card = document.createElement('a');
    card.className = 'embed-card';
    card.href = href;
    card.target = '_blank';
    // noopener: the opened page must not reach back into this one. noreferrer
    // additionally keeps the forum's address out of the far end's logs.
    card.rel = 'noopener noreferrer nofollow';

    if (isSafeUrl(data.image)) {
        const img = document.createElement('img');
        img.className = 'embed-card-image';
        img.src = data.image.trim();
        img.alt = '';
        img.loading = 'lazy';
        // The reader's address still reaches the far end when the picture
        // loads — the same as for any [img] in a posting — but at least it does
        // not learn which thread they are reading.
        img.referrerPolicy = 'no-referrer';
        card.appendChild(img);
    }

    // Without a title there is no card worth showing: a box containing only the
    // host name says less than the address it replaced. The caller falls back to
    // a plain link.
    const title = textNode('div', 'embed-card-title', data.title);
    if (title === null) {
        return null;
    }

    const body = document.createElement('div');
    body.className = 'embed-card-body';
    const parts = [
        title,
        textNode('div', 'embed-card-description', data.description),
        textNode('div', 'embed-card-provider', data.providerName || new URL(href).hostname),
    ].filter((el): el is HTMLElement => el !== null);
    parts.forEach((el) => body.appendChild(el));
    card.appendChild(body);

    return card;
}

/**
 * Render every embed placeholder that has not been rendered yet.
 *
 * Idempotent by a marker attribute, because htmx swaps re-run this over content
 * that is already done.
 */
function renderEmbeds(root: ParentNode = document): void {
    root.querySelectorAll<HTMLElement>('.js-embed[data-embed]').forEach((el) => {
        if (el.dataset.embedRendered === '1') {
            return;
        }
        el.dataset.embedRendered = '1';

        let data: EmbedData;
        try {
            data = JSON.parse(el.dataset.embed ?? '{}') as EmbedData;
        } catch {
            return;
        }

        const card = buildCard(data);
        if (card) {
            el.appendChild(card);
        } else if (isSafeUrl(data.url)) {
            // Not enough for a card — the fetch may have failed or the page
            // offers nothing. A plain link is better than an empty space where
            // the writer put something.
            const link = document.createElement('a');
            link.href = data.url.trim();
            link.target = '_blank';
            link.rel = 'noopener noreferrer nofollow';
            link.textContent = data.url.trim();
            el.appendChild(link);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => renderEmbeds());
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target;
    renderEmbeds(target instanceof Element ? target : document);
});

export { renderEmbeds };
