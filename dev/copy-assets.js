/**
 * Copy the third-party assets that ship alongside the built CSS.
 *
 * Replaces the `copy:nonmin` grunt task. These are files that live in
 * `node_modules` (or, for the licence, the repository) and have to sit next to
 * the stylesheet that references them:
 *
 *  - Bootstrap's minified CSS + source map, for the debug/non-minified mode.
 *  - The Font Awesome web fonts.
 *  - The Cabin and Fenix theme fonts, and the SIL Open Font License text the
 *    fonts must be distributed with. `@fontsource` does carry an OFL text of
 *    its own, but one per package and worded for that typeface; `OFL.txt` is
 *    kept in the repository and copied next to the fonts because it covers both
 *    and because either theme font directory can deploy alone.
 *
 * The font packages are `@fontsource/*` since #93. The `typeface-*` packages
 * they replace stopped publishing in 2022. Their file names differ — the weight
 * and the style are separate segments now — so these globs are not the grunt
 * ones any more:
 *
 *     typeface-cabin   cabin-latin-400.woff2        cabin-latin-400italic.woff2
 *     @fontsource      cabin-latin-400-normal.woff2 cabin-latin-400-italic.woff2
 *
 * The `@font-face` rules in `plugins/Bota/webroot/css/src/partials/_fonts.scss`
 * name these files, so the two have to move together.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const fg = require('fast-glob');

// { src: [globs], dest, cwd? } — `flatten` is implied: every file lands in
// `dest` under its basename, as the grunt `flatten: true` did.
const jobs = [
    {
        src: [
            'node_modules/bootstrap/dist/css/bootstrap.min.css',
            'node_modules/bootstrap/dist/css/bootstrap.min.css.map',
        ],
        dest: 'webroot/css/stylesheets/',
    },
    { cwd: 'node_modules/font-awesome/fonts', src: ['*'], dest: 'webroot/css/stylesheets/fonts/' },
    { src: ['node_modules/@fontsource/cabin/files/cabin-latin-{400,700}-{normal,italic}.woff*'], dest: 'plugins/Bota/webroot/fonts/' },
    { src: ['node_modules/@fontsource/fenix/files/fenix-latin-400-normal.woff*'], dest: 'plugins/Bota/webroot/fonts/' },
    { src: ['webroot/css/src/fonts/OFL.txt'], dest: 'plugins/Bota/webroot/fonts/' },
    { src: ['webroot/css/src/fonts/OFL.txt'], dest: 'plugins/Macnemo/webroot/fonts/' },
];

let copied = 0;
for (const job of jobs) {
    const cwd = job.cwd || '.';
    const matches = fg.sync(job.src, { cwd, dot: false });
    fs.mkdirSync(job.dest, { recursive: true });
    for (const rel of matches) {
        fs.copyFileSync(path.join(cwd, rel), path.join(job.dest, path.basename(rel)));
        copied += 1;
    }
}
process.stdout.write(`  copied ${copied} asset(s)\n`);
