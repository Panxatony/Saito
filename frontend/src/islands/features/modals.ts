/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The overlays the header and footer open.
 */
import { htmx } from '../runtime';

/**
 * The overlays the header and footer can open.
 *
 * Five of these existed as five near-identical branches of one click handler.
 * They differ in three things only, so those are the three columns: which
 * trigger opens them, which overlay to reveal, and — for the ones whose content
 * is fetched rather than already in the page — where to put the fragment and
 * where to get it. `body` absent means the content is static markup that only
 * needs revealing.
 *
 * A trigger may override the URL with `data-modal-url`; that is how login and
 * register share one overlay.
 */
interface ModalSpec {
    /** Selector for the element that opens it. */
    trigger: string;
    /** Id of the `.island-modal` to reveal. */
    modal: string;
    /** Id of the element the fragment is loaded into, if any. */
    body?: string;
    /** Default source of the fragment; overridable per trigger. */
    url?: string;
}

const MODALS: ModalSpec[] = [
    // Login and register share one overlay; the trigger picks the form.
    { trigger: '.js-authModalOpen', modal: 'js-loginModal', body: 'js-loginModalBody', url: '/login' },
    { trigger: '.js-helpOpen', modal: 'js-helpModal' },
    { trigger: '.js-rssOpen', modal: 'js-rssModal' },
    {
        trigger: '.js-contactModalOpen',
        modal: 'js-contactModal',
        body: 'js-contactModalBody',
        url: '/contacts/htmx-contact-owner',
    },
    {
        trigger: '.js-passwordModalOpen',
        modal: 'js-passwordModal',
        body: 'js-passwordModalBody',
        url: '/users/htmx-change-password',
    },
];

/**
 * Reveal an overlay and, when its content is fetched, load the fragment.
 *
 * A fetched overlay whose markup is missing from the page is left closed rather
 * than opened empty.
 *
 * @param spec the overlay to open
 * @param opener the element that was clicked
 */
function openModal(spec: ModalSpec, opener: HTMLElement): void {
    const modal = document.getElementById(spec.modal);
    if (!modal) {
        return;
    }

    if (!spec.body) {
        modal.removeAttribute('hidden');

        return;
    }

    const body = document.getElementById(spec.body);
    if (!body) {
        return;
    }
    modal.removeAttribute('hidden');
    htmx.ajax('GET', opener.getAttribute('data-modal-url') ?? spec.url ?? '/', {
        target: body,
        swap: 'innerHTML',
    });
}

// Open an overlay, or close one. A failed login swaps the form back in with its
// error; a successful one returns HX-Redirect and htmx navigates natively.
// Closing also happens on the backdrop's × and on Escape (below).
document.addEventListener('click', (event: MouseEvent) => {
    const target = event.target as HTMLElement;

    for (const spec of MODALS) {
        const opener = target.closest<HTMLElement>(spec.trigger);
        if (opener) {
            event.preventDefault();
            openModal(spec, opener);

            return;
        }
    }

    const closer = target.closest('.js-modal-close');
    if (closer) {
        event.preventDefault();
        closer.closest('.island-modal')?.setAttribute('hidden', '');
    }
});

document.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.island-modal').forEach((m) => m.setAttribute('hidden', ''));
    }
});
