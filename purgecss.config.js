'use strict';

/**
 * Which CSS rules survive into a release.
 *
 * Bootstrap stays a dependency — `node_modules` is theirs, this repository is
 * ours, and nothing derived from it lives here. What ships is trimmed instead:
 * the compiled themes carry 1600 classes and the forum uses about 150, so the
 * rest is removed at build time rather than rewritten by hand.
 *
 * **This is a lossy step and it fails silently.** PurgeCSS keeps a rule when it
 * finds the class name as a literal somewhere in `content`. A class that PHP or
 * Alpine composes at runtime is invisible to it, the rule is dropped, and the
 * page renders slightly wrong with nothing in any log. The safelist below is
 * therefore not a precaution — it is a list of known blind spots, and every
 * entry earned its place by breaking a pixel comparison.
 *
 * Verify with `dev/pixel-diff.sh` after touching this file. Zero differing
 * pixels across all themes and presets is the gate; a number other than zero
 * means an entry is missing here, not that the number is acceptable.
 */
module.exports = {
    content: [
        'templates/**/*.php',
        'plugins/*/templates/**/*.php',
        // Not only templates: the thread renderers and several helpers build
        // markup in PHP. `threadLine-pre`, `threadTree-node`, `et-root` and
        // `solves-isSolved` live in `src/Lib/Saito/Thread/Renderer/` and
        // `src/View/Helper/`, and leaving these globs out silently strips the
        // whole thread tree's styling.
        'src/**/*.php',
        'plugins/*/src/**/*.php',
        // The islands add and remove classes at runtime; the built bundle is
        // scanned as well as the source, because a class can be assembled from
        // fragments in TypeScript and survive as a literal only after bundling.
        'frontend/src/**/*.ts',
        'webroot/js/*.bundle.js',
        // Help content is rendered as HTML and carries its own markup.
        'docs/help/**/*.md',
    ],

    safelist: {
        standard: [
            // Composed in PHP, never written out as a literal class attribute.
            'no-unread-rail',
            'headerClosed',
            // Defined in `basics/_mixins.scss` and reached only through
            // `@extend`, so the name appears in the compiled CSS but in no
            // template. Removing them collapses the header and the tool bars.
            'flex-bar',
            'flex-bar-header',
        ],
        // Bootstrap's state classes, toggled by Alpine rather than rendered.
        greedy: [
            /^show$/, /^active$/, /^disabled$/, /^collapse/, /^fade$/,
            // **Font Awesome is kept whole, deliberately.** Icon names are
            // composed rather than written: `$iconLabel('sign-in', …)` in
            // `templates/element/layout/htmx_header.php` produces `fa-sign-in`,
            // which appears nowhere in the source as a literal. Purging found
            // exactly that and dropped the login and register icons — 1673
            // pixels, and nothing in any log.
            //
            // A cleverer extractor could learn today's two call patterns and
            // would miss tomorrow's third. Icons are a small, bounded set; the
            // saving is not worth a failure mode that only a pixel comparison
            // can catch, and only on a page somebody remembered to compare.
            /^fa$/, /^fa-/,
            // The collapsed-header state. A plain safelist entry proved not to
            // hold: of five `html.headerClosed …` rules, purging kept three and
            // dropped two — including the one that lets the bar shrink. Greedy
            // keeps every rule the class appears in, compound selectors and all.
            /headerClosed/,
        ],
        // Keep every rule that styles an element rather than a class: `reboot`
        // is the base normalisation and has no class names to match on.
        deep: [/^html$/, /^body$/],
    },

    // Font-face and keyframes are referenced by name, not by selector.
    fontFace: false,
    keyframes: false,
    variables: false,
};
