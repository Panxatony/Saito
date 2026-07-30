/**
 * Behaviour for the administration backend.
 *
 * Replaces what jQuery, DataTables and Bootstrap's JavaScript used to do here —
 * four things, no more: a sortable and filterable table, a confirmation
 * overlay, and the navigation's two menus. Alpine is already the forum's
 * frontend, so the backend now shares it instead of carrying a second stack.
 *
 * Everything is progressive: the table is a plain table, the overlay a plain
 * page section, the menus plain links. Without this script the pages still work
 * — they are just less convenient.
 */
import Alpine from 'alpinejs';
import { initAdminScrollSpy } from './adminScrollSpy';

/** A table row reduced to what sorting and filtering need. */
interface Row {
    el: HTMLTableRowElement;
    cells: string[];
    haystack: string;
}

/**
 * Compare two cell values.
 *
 * Numbers sort numerically, everything else by locale. Dates need no special
 * case: the templates render them as `Y-m-d H:i`, which sorts correctly as
 * text — that is why they are formatted that way.
 */
function compare(a: string, b: string): number {
    const na = Number(a.replace(',', '.'));
    const nb = Number(b.replace(',', '.'));
    if (a !== '' && b !== '' && !Number.isNaN(na) && !Number.isNaN(nb)) {
        return na - nb;
    }

    return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
}

/**
 * Sortable, filterable table.
 *
 * `<div x-data="adminTable" data-sort="3:desc">` wrapping a filter input and a
 * `<table>`. The initial sort is a column index and a direction — DataTables
 * took the same argument and ignored it; here it is honoured.
 */
Alpine.data('adminTable', () => ({
    rows: [] as Row[],
    query: '',
    sortIndex: -1,
    sortAsc: true,

    table: null as HTMLTableElement | null,

    init(): void {
        const table = (this.$el as HTMLElement).querySelector('table');
        if (!table) {
            return;
        }
        this.table = table;
        this.rows = Array.from(table.tBodies[0]?.rows ?? []).map((el) => {
            const cells = Array.from(el.cells).map((c) => (c.textContent ?? '').trim());

            return { el, cells, haystack: cells.join(' ').toLowerCase() };
        });

        // Only the columns with a heading are sortable; the trailing actions
        // column has none, and sorting buttons by their label is meaningless.
        const heads = Array.from(table.tHead?.rows[0]?.cells ?? []);
        heads.forEach((th, index) => {
            if ((th.textContent ?? '').trim() === '') {
                return;
            }
            th.classList.add('is-sortable');
            th.setAttribute('role', 'button');
            th.setAttribute('tabindex', '0');
            const activate = () => this.sortBy(index);
            th.addEventListener('click', activate);
            th.addEventListener('keydown', (event) => {
                if ((event as KeyboardEvent).key === 'Enter' || (event as KeyboardEvent).key === ' ') {
                    event.preventDefault();
                    activate();
                }
            });
        });

        const initial = ((this.$el as HTMLElement).dataset.sort ?? '').split(':');
        if (initial[0] !== undefined && initial[0] !== '') {
            this.sortIndex = Number(initial[0]);
            this.sortAsc = initial[1] !== 'desc';
            this.apply();
        }
    },

    sortBy(index: number): void {
        this.sortAsc = this.sortIndex === index ? !this.sortAsc : true;
        this.sortIndex = index;
        this.apply();
    },

    apply(): void {
        const table = this.table;
        const body = table?.tBodies[0];
        if (!table || !body) {
            return;
        }

        const needle = this.query.trim().toLowerCase();
        let shown = 0;
        this.rows.forEach((row) => {
            const match = needle === '' || row.haystack.includes(needle);
            row.el.hidden = !match;
            if (match) {
                shown += 1;
            }
        });

        if (this.sortIndex >= 0) {
            const dir = this.sortAsc ? 1 : -1;
            [...this.rows]
                .sort((a, b) => compare(a.cells[this.sortIndex] ?? '', b.cells[this.sortIndex] ?? '') * dir)
                .forEach((row) => body.appendChild(row.el));
        }

        // Mark the sorted column for the arrow the stylesheet draws.
        Array.from(table.tHead?.rows[0]?.cells ?? []).forEach((th, i) => {
            th.classList.toggle('is-sorted-asc', i === this.sortIndex && this.sortAsc);
            th.classList.toggle('is-sorted-desc', i === this.sortIndex && !this.sortAsc);
        });

        this.$dispatch('admintable-filtered', { shown, total: this.rows.length });
    },
}));

/**
 * Confirmation overlay.
 *
 * `<div x-data="adminModal">` around a trigger carrying `x-on:click="open()"`
 * and a `<div x-show="isOpen">`. Escape and a click on the backdrop close it,
 * and focus moves into the dialog so a keyboard reaches the buttons.
 */
Alpine.data('adminModal', () => ({
    isOpen: false,

    open(): void {
        this.isOpen = true;
        this.$nextTick(() => {
            this.$el.querySelector<HTMLElement>('[data-autofocus]')?.focus();
        });
    },

    close(): void {
        this.isOpen = false;
    },
}));

/** Navigation: the mobile toggle and the two dropdowns. */
Alpine.data('adminNav', () => ({
    expanded: false,
    openMenu: '',

    toggle(): void {
        this.expanded = !this.expanded;
    },

    menu(name: string): void {
        this.openMenu = this.openMenu === name ? '' : name;
    },

    isOpen(name: string): boolean {
        return this.openMenu === name;
    },
}));

Alpine.start();

// The settings sidebar's scroll-spy. Was an inline <script> in the settings
// template, which is what a content-security policy without 'unsafe-inline'
// refuses; it belongs in this bundle, which every backend page loads.
initAdminScrollSpy();
