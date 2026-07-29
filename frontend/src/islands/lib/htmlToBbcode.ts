/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Turn pasted HTML into the BBCode the forum speaks.
 *
 * Copying from a web page puts two flavours on the clipboard: the plain text and
 * the marked-up original. Until now only the plain text was used, so pasting a
 * photo credit or a quoted paragraph lost every link and every emphasis, and the
 * writer had to rebuild them by hand.
 *
 * The browser does the parsing — `DOMParser` is built in, so nothing has to be
 * shipped for it and no hand-written HTML parser can get it wrong. What is left
 * is a walk over the tree with one case per tag.
 *
 * The default case is what makes this safe against the markup real editors
 * produce: anything unrecognised contributes its text and nothing else. Word's
 * `MsoNormal` spans, Google Docs' nested `<div>`s and a stray `<script>` all
 * collapse to their content without needing a rule of their own.
 *
 * Only tags Saito's BBCode actually has are mapped. There is no table tag, so a
 * pasted table becomes its text rather than a broken approximation of one.
 */

/** Tags whose content must never be carried over, only dropped. */
const DROPPED = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'HEAD', 'TITLE']);

/** A link target worth keeping. Anything else — `javascript:`, `data:` — is not. */
function isSafeUrl(url: string): boolean {
    return /^https?:\/\//i.test(url.trim());
}

function convertNode(node: Node): string {
    if (node.nodeType === Node.TEXT_NODE) {
        // Collapse runs of whitespace the way HTML rendering would; a newline in
        // the source is not a newline on screen.
        return (node.nodeValue ?? '').replace(/\s+/g, ' ');
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
        return '';
    }

    const el = node as Element;
    if (DROPPED.has(el.tagName)) {
        return '';
    }

    const inner = Array.from(el.childNodes).map(convertNode).join('');

    switch (el.tagName) {
        case 'A': {
            const href = el.getAttribute('href') ?? '';
            if (!isSafeUrl(href)) {
                return inner;
            }
            const label = inner.trim();

            return label && label !== href.trim() ? `[url=${href}]${label}[/url]` : `[url]${href}[/url]`;
        }
        case 'IMG': {
            const src = el.getAttribute('src') ?? '';

            return isSafeUrl(src) ? `[img]${src}[/img]` : '';
        }
        case 'B':
        case 'STRONG':
            return inner.trim() ? `[b]${inner}[/b]` : inner;
        case 'I':
        case 'EM':
            return inner.trim() ? `[i]${inner}[/i]` : inner;
        case 'S':
        case 'DEL':
        case 'STRIKE':
            return inner.trim() ? `[s]${inner}[/s]` : inner;
        case 'CODE':
        case 'PRE':
            return inner.trim() ? `[code]${inner}[/code]` : inner;
        case 'BLOCKQUOTE':
            return inner.trim() ? `\n[quote]${inner.trim()}[/quote]\n` : '';
        case 'LI':
            return `[*] ${inner.trim()}\n`;
        case 'UL':
        case 'OL':
            // Saito's list has no nesting and no numbering; a nested list simply
            // continues as further items rather than pretending to indent.
            return `\n[list]\n${inner}[/list]\n`;
        case 'BR':
            return '\n';
        case 'P':
        case 'DIV':
        case 'SECTION':
        case 'ARTICLE':
        case 'H1':
        case 'H2':
        case 'H3':
        case 'H4':
        case 'H5':
        case 'H6':
            return inner.trim() ? `${inner}\n` : inner;
        default:
            return inner;
    }
}

/**
 * Convert an HTML fragment to BBCode.
 *
 * @param html the `text/html` flavour off the clipboard
 * @returns BBCode, tidied of the whitespace the walk leaves behind
 */
export function htmlToBbcode(html: string): string {
    const doc = new DOMParser().parseFromString(html, 'text/html');

    return convertNode(doc.body)
        .replace(/[ \t]+/g, ' ')
        .replace(/ *\n */g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * Is converting this paste worth taking over from the browser?
 *
 * Copying ordinary prose out of a browser still puts `text/html` on the
 * clipboard, so the flavour being present says nothing. What matters is whether
 * the conversion produced anything the plain text did not already carry — if it
 * did not, the browser is left to paste, which keeps undo, scroll position and
 * "paste and match style" behaving exactly as they always have.
 */
export function bbcodeAddsSomething(bbcode: string, plain: string): boolean {
    const normalise = (s: string): string => s.replace(/\s+/g, ' ').trim();

    return bbcode !== '' && normalise(bbcode) !== normalise(plain);
}
