/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * Mark the settings section currently in view in the sidebar.
 *
 * What Bootstrap's ScrollSpy did before its JavaScript was dropped in 8.1.0. The
 * anchors the helper emits are empty divs with no height, so this measures where
 * they are rather than observing them: the last anchor that has passed a line
 * near the top of the viewport is the current one.
 *
 * Moved out of an inline `<script>` in the settings template so the
 * content-security policy can stop allowing `'unsafe-inline'`. It rides in the
 * admin bundle, which every backend page loads already.
 *
 * Nothing breaks without it — the sidebar links still jump to their sections,
 * they just stop following along.
 */

interface Section {
    link: HTMLElement;
    anchor: HTMLElement;
}

/** Distance below the top edge at which a section counts as "current". */
const LINE = 80;

/**
 * Pair each sidebar link with the anchor it points at, dropping links whose
 * target is not on the page.
 *
 * @returns the pairs, in document order
 */
function collectSections(): Section[] {
    const links = document.querySelectorAll<HTMLElement>('.navbarsidelist .nav-link');

    const sections: Section[] = [];
    for (const link of Array.from(links)) {
        const id = link.getAttribute('href')?.slice(1);
        const anchor = id ? document.getElementById(id) : null;
        if (anchor) {
            sections.push({ link, anchor });
        }
    }

    return sections;
}

/**
 * Which section is current: the last anchor above the line, except at the very
 * bottom where the last one may never reach it because there is no scrolling left
 * to do.
 *
 * @param sections the link/anchor pairs
 * @returns the current pair
 */
function currentSection(sections: Section[]): Section {
    if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 2) {
        return sections[sections.length - 1];
    }

    let current = sections[0];
    for (const section of sections) {
        if (section.anchor.getBoundingClientRect().top <= LINE) {
            current = section;
        }
    }

    return current;
}

export function initAdminScrollSpy(): void {
    const sections = collectSections();
    if (!sections.length) {
        return;
    }

    let pending = false;

    const update = (): void => {
        pending = false;
        const current = currentSection(sections);
        for (const section of sections) {
            section.link.classList.toggle('active', section === current);
        }
    };

    const schedule = (): void => {
        if (!pending) {
            pending = true;
            window.requestAnimationFrame(update);
        }
    };

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    update();
}
