/**
 * PostCSS release step — autoprefixer + cssnano over the shipped stylesheets.
 *
 * Replaces the `postcss:release` grunt task (`@lodder/grunt-postcss`). It runs
 * after the Sass compile and before the purge: adds vendor prefixes, then
 * minifies, in place. The processor list and its options are exactly what the
 * grunt task carried, so the output is byte-identical to the old chain —
 * verified with `cmp` against a `grunt release` reference and with
 * `dev/pixel-diff.sh`.
 *
 * The file set is the same seven stylesheets `dev/purge-css.js` trims next; a
 * literal list rather than a glob, for the same reason it uses one — the set is
 * fixed and a list is what a reader can check.
 */

'use strict';

const fs = require('fs');
const postcss = require('postcss');
const autoprefixer = require('autoprefixer');
const cssnano = require('cssnano');

const files = [
    'webroot/css/stylesheets/static.css',
    'plugins/Bota/webroot/css/theme.css',
    'plugins/Bota/webroot/css/night.css',
    'plugins/Nova/webroot/css/theme.css',
    'plugins/Nova/webroot/css/night.css',
    'plugins/Macnemo/webroot/css/theme.css',
    'plugins/Macnemo/webroot/css/night.css',
];

const processor = postcss([
    // Target browsers come from package.json's `browserslist`, which cssnano
    // reads too — autoprefixer dropped its inline `browsers` option in v10.
    autoprefixer(),
    cssnano({
        // Keyframe names are referenced by string, so cssnano must not rename or
        // drop them — the same guard the grunt task carried.
        // @see https://github.com/ben-eb/cssnano/issues/247
        reduceIdents: { keyframes: false },
        discardUnused: { keyframes: false },
    }),
]);

(async () => {
    for (const file of files) {
        const css = fs.readFileSync(file, 'utf8');
        const result = await processor.process(css, { from: file, to: file, map: false });
        fs.writeFileSync(file, result.css);
        process.stdout.write(`  postcss: ${file}\n`);
    }
})().catch((error) => {
    process.stderr.write(`${error && error.stack ? error.stack : error}\n`);
    process.exit(1);
});
