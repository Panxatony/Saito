/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * How many characters are still free in the subject field.
 *
 * The limit comes from the admin setting and is already on the field as
 * `maxlength`, so a writer cannot exceed it — but until now the field simply
 * stopped accepting input, with no warning that the end was near.
 *
 * Built here rather than in the three templates that render a subject: two of
 * them go through Form->control(), which owns its own markup, so adding the
 * counter in PHP would have meant three different insertion points for one
 * feature. The field is wrapped on the fly instead, which also keeps the counter
 * pinned to the field's right edge — the field is narrower than the row it sits
 * in, so aligning to the row would have put the number out in the margin.
 */

const WRAPPED = 'js-subjectWrapped';

/**
 * Update the number shown for one field.
 *
 * @param input the subject field
 * @param counter its counter element
 */
function update(input: HTMLInputElement, counter: HTMLElement): void {
    const max = Number(input.getAttribute('maxlength') ?? 0);
    if (!max) {
        return;
    }
    // Count what the field itself counts. `maxlength` measures UTF-16 code
    // units, so an emoji costs two — reporting anything else would let the
    // number reach zero while the field still accepted input, or the reverse.
    const left = max - input.value.length;
    counter.textContent = String(left);
    // Only the last stretch is worth colouring; a permanent warning colour is
    // no warning at all.
    counter.classList.toggle('is-low', left <= Math.min(20, Math.floor(max / 5)));
}

/**
 * Give every subject field in `root` a counter, once.
 *
 * @param root element to search in
 */
function attach(root: ParentNode): void {
    const fields = root.querySelectorAll<HTMLInputElement>('.js-subject');
    for (const input of Array.from(fields)) {
        if (input.classList.contains(WRAPPED) || !input.getAttribute('maxlength')) {
            continue;
        }
        input.classList.add(WRAPPED);

        const wrap = document.createElement('span');
        wrap.className = 'subject-field';
        // The width rule lives on the wrapper now, so carry the limit across;
        // the field itself goes back to filling what it is given.
        wrap.style.setProperty('--subject-max', input.style.getPropertyValue('--subject-max') || '100');

        const counter = document.createElement('span');
        counter.className = 'subject-count';
        counter.setAttribute('aria-hidden', 'true');

        input.parentNode?.insertBefore(wrap, input);
        wrap.appendChild(input);
        wrap.appendChild(counter);

        input.addEventListener('input', () => update(input, counter));
        update(input, counter);
    }
}

attach(document);

// Reply and edit forms arrive by htmx, so they are not in the document at load.
document.body.addEventListener('htmx:afterSwap', (event: Event) => {
    const target = (event as CustomEvent).detail?.target as ParentNode | undefined;
    attach(target ?? document);
});
