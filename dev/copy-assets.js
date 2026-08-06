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
 *    fonts must be distributed with — the npm packages carry only their own
 *    packaging licence, so `OFL.txt` is kept in the repo and copied next to the
 *    fonts. Both theme font directories get a copy, because either can deploy
 *    alone.
 *
 * The glob strings are exactly the grunt ones so the file set does not change:
 * `[4|7]` is a character class matching the 400 and 700 weights (and, harmlessly,
 * a literal `|` that no file carries).
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
    { src: ['node_modules/typeface-cabin/files/cabin-latin-[4|7]00.woff*'], dest: 'plugins/Bota/webroot/fonts/' },
    { src: ['node_modules/typeface-cabin/files/cabin-latin-[4|7]00italic.woff*'], dest: 'plugins/Bota/webroot/fonts/' },
    { src: ['node_modules/typeface-fenix/files/fenix-latin-400.woff*'], dest: 'plugins/Bota/webroot/fonts/' },
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
