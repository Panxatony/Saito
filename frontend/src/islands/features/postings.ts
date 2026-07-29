/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * A rendered posting: the per-posting toggles the server does not draw, the
 * tool menu, and its moderator actions.
 */
import { csrfToken } from '../lib/dom';
import { onReady } from '../lib/dom';

/**
 * Enhance a freshly-loaded inline posting with the action buttons the SPA
 * normally renders client-side from its `.js-data` JSON (bookmark, "mark as
 * solution"). The tool-menu actions (pin/lock/delete) are wired via delegation
 * below; here we only inject the two per-posting toggles.
 *
 * @param core the `.js-entry-view-core` posting element
 */
function enhancePosting(core: HTMLElement): void {
    if (core.dataset.islandEnhanced) {
        return;
    }
    core.dataset.islandEnhanced = '1';

    const id = core.getAttribute('data-id');
    const dataEl = core.querySelector<HTMLElement>('.js-data');
    if (!id || !dataEl) {
        return;
    }
    let data: { isBookmarked?: boolean; showSolvedBtn?: boolean; solves?: number; pid?: number } = {};
    try {
        data = JSON.parse(dataEl.getAttribute('data-entry') ?? '{}');
    } catch {
        return;
    }

    let actions = core.querySelector<HTMLElement>('.postingLayout-actions');
    if (!actions) {
        actions = document.createElement('div');
        actions.className = 'postingLayout-actions';
        core.appendChild(actions);
    }

    const group = document.createElement('span');
    group.className = 'island-posting-tools';

    // Bookmark toggle — any logged-in user. Session endpoint toggles by entry id.
    const bkm = document.createElement('button');
    bkm.type = 'button';
    bkm.className = 'btn btn-link js-island-bookmark';
    const paintBkm = (on: boolean): void => {
        bkm.innerHTML = `<i class="fa ${on ? 'fa-bookmark' : 'fa-bookmark-o'}"></i>`;
        bkm.setAttribute('aria-pressed', on ? 'true' : 'false');
    };
    paintBkm(Boolean(data.isBookmarked));
    bkm.addEventListener('click', () => {
        fetch(`/entries/htmx-bookmark/${id}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((j: { bookmarked?: boolean }) => paintBkm(Boolean(j.bookmarked)))
            .catch(() => undefined);
    });
    group.appendChild(bkm);

    // "Mark as helpful" — only when the server says the user may set it (the
    // thread starter), and only on answers, not the opening post (pid > 0).
    if (data.showSolvedBtn && data.pid) {
        const solve = document.createElement('button');
        solve.type = 'button';
        solve.className = 'btn btn-link js-island-solve';
        const paintSolve = (on: boolean): void => {
            solve.innerHTML = `<i class="fa fa-check-circle${on ? '' : '-o'}"></i>`;
            solve.setAttribute('aria-pressed', on ? 'true' : 'false');
        };
        let solved = Boolean(data.solves);
        paintSolve(solved);
        solve.addEventListener('click', () => {
            fetch(`/entries/solve/${id}`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => {
                    if (r.ok) {
                        solved = !solved;
                        paintSolve(solved);
                    }
                })
                .catch(() => undefined);
        });
        group.appendChild(solve);
    }

    actions.insertBefore(group, actions.firstChild);
}

// Enhance any posting htmx swaps into the page (inline open, reply, etc.).
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    (target ?? document).querySelectorAll<HTMLElement>('.js-entry-view-core')
        .forEach((el) => {
            if (el.closest('.js-thread-island')) {
                enhancePosting(el);
            }
        });
});

// Full page loads (the thread read view) fire no htmx swap, so enhance the
// postings already present in the DOM once the page is ready.
function enhanceExistingPostings(): void {
    document.querySelectorAll<HTMLElement>('.js-thread-island .js-entry-view-core')
        .forEach((el) => enhancePosting(el));
}
onReady(enhanceExistingPostings);

// Posting tool menu (pin / lock / merge / delete) is a Bootstrap-4 dropdown, but
// the island loads no Bootstrap JS — so toggle `.show` ourselves. Open on the
// wrench button, close on outside click / item select / Escape.
function closeIslandDropdowns(): void {
    document.querySelectorAll('.js-thread-island .dropdown-menu.show')
        .forEach((m) => m.classList.remove('show'));
}
document.addEventListener('click', (event: MouseEvent) => {
    const toggle = (event.target as HTMLElement).closest<HTMLElement>('[data-toggle="dropdown"]');
    if (toggle?.closest('.js-thread-island')) {
        event.preventDefault();
        const menu = toggle.parentElement?.querySelector<HTMLElement>('.dropdown-menu') ?? null;
        const willOpen = Boolean(menu) && !menu.classList.contains('show');
        closeIslandDropdowns();
        if (willOpen && menu) {
            menu.classList.add('show');
        }

        return;
    }
    // Any other click (including selecting a menu item) closes open menus.
    closeIslandDropdowns();
});
document.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeIslandDropdowns();
    }
});

// Auto-dismiss the "post saved" confirmation a few seconds after it appears.
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as HTMLElement | undefined;
    const done = (target ?? document).querySelector<HTMLElement>('.js-add-done');
    if (!done) {
        return;
    }
    window.setTimeout(() => {
        done.style.transition = 'opacity .5s ease';
        done.style.opacity = '0';
        window.setTimeout(() => done.remove(), 500);
    }, 5000);
});

// Tool menu — pin / lock (moderators). Posts with the CSRF token like its
// neighbours; it used to travel by GET, protected only by the X-Requested-With
// header the controller insists on, which is a side effect rather than a
// defence.
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLElement>(
        '.js-btn-toggle-fixed, .js-btn-toggle-locked'
    );
    if (!link || !link.closest('.js-thread-island')) {
        return;
    }
    event.preventDefault();
    const toggle = link.classList.contains('js-btn-toggle-fixed') ? 'fixed' : 'locked';
    const leaf = link.closest<HTMLElement>('.threadLeaf');
    const id = link.closest<HTMLElement>('.js-entry-view-core')?.getAttribute('data-id');
    if (!id) {
        return;
    }
    fetch(`/entries/ajaxToggle/${id}/${toggle}`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    })
        .then((r) => {
            if (r.ok && leaf) {
                // Throw the cached fragment away before reopening. Two clicks on
                // the icon only hid and re-showed it — toggleInlinePosting takes
                // the fetch path solely when no slider exists, so the moderator
                // was looking at the state from before their own change and
                // clicked again, setting the flag back.
                leaf.querySelector('.threadInline-slider')?.remove();
                leaf.classList.remove('is-inline-open');
                leaf.querySelector<HTMLElement>('.btn_show_thread')?.click();
            }
        })
        .catch(() => undefined);
});

// Tool menu — delete: go to the server's CSRF-protected delete confirmation flow
// (a destructive action stays a deliberate, guarded navigation).
document.addEventListener('click', (event: MouseEvent) => {
    const link = (event.target as HTMLElement).closest<HTMLElement>('.js-delete');
    if (!link || !link.closest('.js-thread-island')) {
        return;
    }
    event.preventDefault();
    const id = link.closest<HTMLElement>('.js-entry-view-core')?.getAttribute('data-id');
    if (id) {
        window.location.href = `/entries/delete/${id}`;
    }
});
