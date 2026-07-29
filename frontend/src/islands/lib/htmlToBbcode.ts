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

/**
 * What the element's own `style` says about weight and slant.
 *
 * Editors do not agree with themselves here. Google Docs wraps a whole pasted
 * passage in `<b style="font-weight:normal">` — a bold tag that explicitly is
 * not bold — and marks the actually bold words with `<span
 * style="font-weight:700">`. Taking the tag at its word turns the entire paste
 * bold and loses the emphasis that was really there.
 *
 * So the inline style wins where it speaks, and the tag decides where it does
 * not. `null` means "no opinion".
 */
function styledAs(el: Element, property: 'weight' | 'style'): boolean | null {
    const declared = el.getAttribute('style');
    if (!declared) {
        return null;
    }
    if (property === 'weight') {
        const m = /font-weight\s*:\s*([^;]+)/i.exec(declared);
        if (!m) {
            return null;
        }
        const value = m[1].trim().toLowerCase();
        const numeric = parseInt(value, 10);

        return Number.isNaN(numeric) ? value === 'bold' || value === 'bolder' : numeric >= 600;
    }
    const m = /font-style\s*:\s*([^;]+)/i.exec(declared);

    return m ? /^(italic|oblique)/i.test(m[1].trim()) : null;
}

/**
 * Emphasis already in force further up the tree.
 *
 * Word writes `<b><span style="font-weight:bold">`, saying the same thing twice
 * in two different ways. Without knowing what is already open, both fire and the
 * result is `[b][b]…[/b][/b]` — valid but silly, and it makes a diff of two
 * pasted versions unreadable. Carrying the state down means each emphasis is
 * opened by whichever element mentions it first, and ignored by the rest.
 */
interface Emphasis {
    bold: boolean;
    italic: boolean;
}

function convertNode(node: Node, active: Emphasis = { bold: false, italic: false }): string {
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

    // Does this element open an emphasis that is not already open?
    const opensBold = !active.bold
        && (((el.tagName === 'B' || el.tagName === 'STRONG') && styledAs(el, 'weight') !== false)
            || styledAs(el, 'weight') === true);
    const opensItalic = !active.italic
        && (((el.tagName === 'I' || el.tagName === 'EM') && styledAs(el, 'style') !== false)
            || styledAs(el, 'style') === true);
    const nested: Emphasis = {
        bold: active.bold || opensBold,
        italic: active.italic || opensItalic,
    };
    const inner = Array.from(el.childNodes).map((child) => convertNode(child, nested)).join('');

    /** Wrap in whatever this element is the first to open. */
    const emphasised = (text: string): string => {
        let out = text;
        if (opensBold) {
            out = `[b]${out}[/b]`;
        }
        if (opensItalic) {
            out = `[i]${out}[/i]`;
        }

        return out;
    };

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
        case 'I':
        case 'EM':
            return inner.trim() ? emphasised(inner) : inner;
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
        case 'SPAN':
        case 'FONT':
            // Carries no meaning of its own — but Google Docs and Word put the
            // actual emphasis here, in the style rather than in a tag.
            return inner.trim() ? emphasised(inner) : inner;
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
