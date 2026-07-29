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

/**
 * Tags that wrap their content in one BBCode pair and nothing more.
 *
 * As data rather than as `case` labels: the mapping is the whole rule, and a
 * table says so at a glance where twenty fall-through cases did not.
 */
const WRAPPED: Record<string, [string, string]> = {
    S: ['[s]', '[/s]'],
    DEL: ['[s]', '[/s]'],
    STRIKE: ['[s]', '[/s]'],
    CODE: ['[code]', '[/code]'],
    PRE: ['[code]', '[/code]'],
};

/**
 * Tags that carry emphasis rather than markup of their own.
 *
 * `SPAN` and `FONT` are in here because Google Docs and Word put the actual
 * emphasis in their style attribute rather than in a tag.
 */
const EMPHASIS = new Set(['B', 'STRONG', 'I', 'EM', 'SPAN', 'FONT']);

/** Tags that close a block, so their content is followed by a line break. */
const BLOCK = new Set(['P', 'DIV', 'SECTION', 'ARTICLE', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6']);

/** Tags that become a list. Saito's list has no nesting and no numbering. */
const LIST = new Set(['UL', 'OL']);

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
        const weight = /font-weight\s*:\s*([^;]+)/i.exec(declared);
        if (!weight) {
            return null;
        }
        const value = weight[1].trim().toLowerCase();
        const numeric = parseInt(value, 10);

        return Number.isNaN(numeric) ? value === 'bold' || value === 'bolder' : numeric >= 600;
    }
    const slant = /font-style\s*:\s*([^;]+)/i.exec(declared);

    return slant ? /^(italic|oblique)/i.test(slant[1].trim()) : null;
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

/**
 * Which emphasis this element is the first to open.
 *
 * "First" is what the two `!active` terms are for — see {@link Emphasis}. The
 * rest is the disagreement between tag and style: a `<b>` counts unless its own
 * style says otherwise, and any element counts if its style says so.
 */
function opensEmphasis(el: Element, active: Emphasis): Emphasis {
    const tag = el.tagName;

    return {
        bold: !active.bold
            && (((tag === 'B' || tag === 'STRONG') && styledAs(el, 'weight') !== false)
                || styledAs(el, 'weight') === true),
        italic: !active.italic
            && (((tag === 'I' || tag === 'EM') && styledAs(el, 'style') !== false)
                || styledAs(el, 'style') === true),
    };
}

/**
 * `<a>` — kept only when the target is one we would follow.
 *
 * An unsafe target loses the link but keeps the words: the text a reader can
 * see is not the dangerous part, the destination is.
 */
function convertLink(el: Element, inner: string): string {
    const href = el.getAttribute('href') ?? '';
    if (!isSafeUrl(href)) {
        return inner;
    }
    const label = inner.trim();

    return label && label !== href.trim() ? `[url=${href}]${label}[/url]` : `[url]${href}[/url]`;
}

/** `<img>` — an unsafe source leaves nothing behind; there is no text to keep. */
function convertImage(el: Element): string {
    const src = el.getAttribute('src') ?? '';

    return isSafeUrl(src) ? `[img]${src}[/img]` : '';
}

/**
 * Turn one element and its already-converted content into BBCode.
 *
 * @param el the element
 * @param inner its children, converted
 * @param emphasise wraps text in whatever emphasis this element opens
 */
function convertElement(el: Element, inner: string, emphasise: (text: string) => string): string {
    const tag = el.tagName;

    // The four table-driven groups first — between them they cover twenty of the
    // tags and each is one lookup rather than a run of fall-through cases.
    if (EMPHASIS.has(tag)) {
        return inner.trim() ? emphasise(inner) : inner;
    }
    const wrapper = WRAPPED[tag];
    if (wrapper !== undefined) {
        return inner.trim() ? `${wrapper[0]}${inner}${wrapper[1]}` : inner;
    }
    if (BLOCK.has(tag)) {
        return inner.trim() ? `${inner}\n` : inner;
    }
    if (LIST.has(tag)) {
        // A nested list simply continues as further items rather than
        // pretending to indent.
        return `\n[list]\n${inner}[/list]\n`;
    }

    // What is left needs an attribute or a shape of its own.
    switch (tag) {
        case 'A':
            return convertLink(el, inner);
        case 'IMG':
            return convertImage(el);
        case 'BLOCKQUOTE':
            return inner.trim() ? `\n[quote]${inner.trim()}[/quote]\n` : '';
        case 'LI':
            return `[*] ${inner.trim()}\n`;
        case 'BR':
            return '\n';
        default:
            return inner;
    }
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

    const opens = opensEmphasis(el, active);
    const nested: Emphasis = {
        bold: active.bold || opens.bold,
        italic: active.italic || opens.italic,
    };
    const inner = Array.from(el.childNodes).map((child) => convertNode(child, nested)).join('');

    /** Wrap in whatever this element is the first to open. */
    const emphasise = (text: string): string => {
        let out = text;
        if (opens.bold) {
            out = `[b]${out}[/b]`;
        }
        if (opens.italic) {
            out = `[i]${out}[/i]`;
        }

        return out;
    };

    return convertElement(el, inner, emphasise);
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
