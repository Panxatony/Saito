/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * The upload overlay and the profile's upload manager: pick or drop files,
 * browse the archive, insert a selection into the editor, delete several at
 * once.
 */
import { htmx } from '../runtime';
import { csrfToken } from '../lib/dom';

// Editor upload overlay: the toolbar button opens an overlay to upload files and
// browse the user's archive (20 per page + load more); clicking a tile inserts
// its [tag src=upload]name[/tag] into the editor that opened it.
let uploadTarget: HTMLTextAreaElement | null = null;

function insertUploadTag(textarea: HTMLTextAreaElement, name: string, mime: string): void {
    const type = mime.split('/')[0];
    const tag = type === 'video' || type === 'audio' ? type : type === 'image' ? 'img' : 'file';
    const bb = `[${tag} src=upload]${name}[/${tag}]\n`;
    const pos = textarea.selectionStart ?? textarea.value.length;
    textarea.value = textarea.value.slice(0, pos) + bb + textarea.value.slice(pos);
    textarea.selectionStart = textarea.selectionEnd = pos + bb.length;
}

function loadUploadGrid(): void {
    const grid = document.querySelector<HTMLElement>('.js-uploadGrid');
    if (grid) {
        htmx.ajax('GET', '/entries/htmx-uploads', { target: grid, swap: 'innerHTML' });
    }
}

async function uploadFiles(files: File[]): Promise<void> {
    const token = csrfToken();
    const status = document.querySelector<HTMLElement>('.js-uploadStatus');
    let ok = 0;
    const errs: string[] = [];
    for (const file of files) {
        const body = new FormData();
        body.append('file', file);
        try {
            const response = await fetch('/entries/htmx-upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' },
                body,
                credentials: 'same-origin',
            });
            const data: { name?: string; error?: string } = await response.json();
            if (data.error || !data.name) {
                errs.push(`${file.name}: ${data.error ?? 'failed'}`);
            } else {
                ok += 1;
            }
        } catch {
            errs.push(`${file.name}: failed`);
        }
    }
    if (status) {
        status.textContent = `${ok} ✓${errs.length ? ` · ${errs.join(', ')}` : ''}`;
    }
    loadUploadGrid();
}

// Toolbar upload button → open the overlay for this editor.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest('.js-bb-upload');
    if (!btn) {
        return;
    }
    event.preventDefault();
    uploadTarget = btn.closest('form')?.querySelector<HTMLTextAreaElement>('textarea[name="text"]') ?? null;
    const status = document.querySelector<HTMLElement>('.js-uploadStatus');
    if (status) {
        status.textContent = '';
    }
    document.getElementById('js-uploadModal')?.removeAttribute('hidden');
    loadUploadGrid();
    updateUploadInsertBtn();
});

// "Choose files" opens the picker.
document.addEventListener('click', (event: MouseEvent) => {
    if ((event.target as HTMLElement).closest('.js-uploadPick')) {
        event.preventDefault();
        document.querySelector<HTMLInputElement>('.js-uploadInput')?.click();
    }
});

// Picking files uploads them, then refreshes the archive grid.
document.addEventListener('change', (event: Event) => {
    const input = (event.target as HTMLElement).closest<HTMLInputElement>('.js-uploadInput');
    if (!input || !input.files || input.files.length === 0) {
        return;
    }
    // uploadFiles reports per-file failures itself and never rejects, but the
    // callback below can throw — without a catch that would be an unhandled
    // rejection.
    uploadFiles(Array.from(input.files))
        .then(() => {
            input.value = '';
        })
        .catch(() => {
            /* nothing useful left to do; the status line already shows the result */
        });
});

// Drag & drop onto the drop zone.
document.addEventListener('dragover', (event: DragEvent) => {
    const drop = (event.target as HTMLElement).closest('.js-uploadDrop');
    if (!drop) {
        return;
    }
    event.preventDefault();
    drop.classList.add('is-dragover');
});
document.addEventListener('dragleave', (event: DragEvent) => {
    (event.target as HTMLElement).closest('.js-uploadDrop')?.classList.remove('is-dragover');
});
document.addEventListener('drop', (event: DragEvent) => {
    const drop = (event.target as HTMLElement).closest('.js-uploadDrop');
    if (!drop) {
        return;
    }
    event.preventDefault();
    drop.classList.remove('is-dragover');
    const files = event.dataTransfer?.files;
    if (files?.length) {
        uploadFiles(Array.from(files));
    }
});

function updateUploadInsertBtn(): void {
    const btn = document.querySelector<HTMLButtonElement>('.js-uploadInsert');
    if (btn) {
        btn.disabled = !document.querySelector('.js-uploadTile.is-selected');
    }
}

// The profile's upload manager has no "insert" button — selecting tiles there is
// for deleting several at once. Keep its button in step with the selection and
// show the count, so it is obvious how much is about to go.
function updateUploadsDeleteBtn(): void {
    const btn = document.querySelector<HTMLButtonElement>('.js-uploadsDeleteSelected');
    if (!btn) {
        return;
    }
    const count = document.querySelectorAll('.js-uploadManageGrid .js-uploadTile.is-selected').length;
    btn.disabled = count === 0;
    const label = btn.getAttribute('data-label') ?? '';
    const icon = btn.querySelector('i');
    btn.textContent = count > 0 ? ` ${label} (${count})` : ` ${label}`;
    if (icon) {
        btn.prepend(icon);
    }
}

// Clicking an archive tile toggles its selection (what happens with the
// selection depends on where the grid sits: insert in the editor overlay,
// delete in the profile).
document.addEventListener('click', (event: MouseEvent) => {
    const tile = (event.target as HTMLElement).closest<HTMLElement>('.js-uploadTile');
    if (!tile) {
        return;
    }
    event.preventDefault();
    tile.classList.toggle('is-selected');
    updateUploadInsertBtn();
    updateUploadsDeleteBtn();
});

// "Delete selection" in the profile: confirm once for the whole batch, then
// remove each upload and drop its tile. Deleting is per-upload on the server, so
// a single failure leaves the rest done and that tile in place.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-uploadsDeleteSelected');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const tiles = Array.from(
        document.querySelectorAll<HTMLElement>('.js-uploadManageGrid .js-uploadTile.is-selected')
    );
    // The native dialog on purpose: this deletes several uploads at once and
    // cannot be undone. A themed overlay would fit the design better but can
    // fail to render, and then the click would delete without ever asking.
    // skipcq: JS-0052
    if (!tiles.length || !window.confirm(btn.getAttribute('data-confirm') ?? 'Delete?')) {
        return;
    }
    btn.disabled = true;
    Promise.all(tiles.map((tile) => {
        const id = tile.getAttribute('data-upload-id');
        if (!id) {
            return Promise.resolve();
        }

        return fetch(`/entries/htmx-upload-delete/${id}`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (response.ok) {
                    tile.closest('.js-uploadManageItem')?.remove();
                }
            })
            .catch(() => undefined);
    })).then(() => updateUploadsDeleteBtn());
});

// "Insert selected" → insert every selected tile's BBCode, then close.
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest('.js-uploadInsert');
    if (!btn || !uploadTarget) {
        return;
    }
    event.preventDefault();
    document.querySelectorAll<HTMLElement>('.js-uploadTile.is-selected').forEach((tile) => {
        insertUploadTag(uploadTarget as HTMLTextAreaElement, tile.getAttribute('data-name') ?? '', tile.getAttribute('data-mime') ?? '');
    });
    document.getElementById('js-uploadModal')?.setAttribute('hidden', '');
});
