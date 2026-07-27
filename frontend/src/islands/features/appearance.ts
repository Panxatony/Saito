/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * What the reader can set about how the forum looks: light or night, and the
 * font scale. Both are per-browser and re-applied by the island layout.
 */
// Theme toggle (light / night) for the standalone header: swap the stylesheet
// live and remember the choice under the theme's own `localStorage.theme` key,
// so a reload keeps it (the htmx_island layout reads the same key).
document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('#js-themeToggle');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const link = document.getElementById('js-themeCss') as HTMLLinkElement | null;
    if (!link) {
        return;
    }
    try {
        const toNight = localStorage.getItem('theme') !== 'night';
        const href = btn.getAttribute(toNight ? 'data-night-css' : 'data-theme-css');
        localStorage.setItem('theme', toNight ? 'night' : 'theme');
        if (href) {
            link.href = href;
        }
    } catch {
        /* localStorage unavailable */
    }
});

// Font-size preference: the settings buttons set a per-browser scale applied to
// the root element (the island sizes in rem/em, so this scales everything).
// Stored in localStorage and re-applied by the island layout on every page.
function currentFontScale(): string {
    try {
        return localStorage.getItem('islandFontScale') ?? '100';
    } catch {
        return '100';
    }
}

function markActiveFontScale(): void {
    const cur = currentFontScale();
    document.querySelectorAll<HTMLElement>('.js-font-scale').forEach((b) => {
        b.classList.toggle('active', b.getAttribute('data-scale') === cur);
    });
}

document.addEventListener('click', (event: MouseEvent) => {
    const btn = (event.target as HTMLElement).closest<HTMLElement>('.js-font-scale');
    if (!btn) {
        return;
    }
    event.preventDefault();
    const scale = btn.getAttribute('data-scale') ?? '100';
    try {
        localStorage.setItem('islandFontScale', scale);
    } catch {
        /* localStorage unavailable */
    }
    document.documentElement.style.fontSize = `${scale}%`;
    markActiveFontScale();
});

markActiveFontScale();
