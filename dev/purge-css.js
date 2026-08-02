#!/usr/bin/env node
'use strict';

/**
 * Trim the compiled stylesheets to the classes the forum actually uses.
 *
 * Runs in the release chain, after minification. Bootstrap stays a dependency —
 * `node_modules` is theirs, this repository is ours — and only the shipped CSS
 * is reduced. Measured on 2026-08-02: 132 KB to 41 KB per theme, zero differing
 * pixels.
 *
 * **Each file is rewritten in place, one at a time.** The CLI writes into a
 * single `--output` directory by basename, and all three themes are called
 * `theme.css`; pointing them at one directory silently collapses them into
 * whichever ran last. That has bitten this project twice in comparison
 * harnesses, and it would be a genuine outage here.
 */

const fs = require('fs');
const path = require('path');
const { PurgeCSS } = require('purgecss');
const config = require('../purgecss.config.js');

const targets = [
    'webroot/css/stylesheets/static.css',
    'plugins/Bota/webroot/css/theme.css',
    'plugins/Bota/webroot/css/night.css',
    'plugins/Nova/webroot/css/theme.css',
    'plugins/Nova/webroot/css/night.css',
    'plugins/Macnemo/webroot/css/theme.css',
    'plugins/Macnemo/webroot/css/night.css',
];

(async () => {
    const root = path.resolve(__dirname, '..');
    let before = 0;
    let after = 0;

    for (const rel of targets) {
        const file = path.join(root, rel);
        if (!fs.existsSync(file)) {
            console.error(`purge: ${rel} fehlt — Release-Kette in falscher Reihenfolge?`);
            process.exit(1);
        }

        const [result] = await new PurgeCSS().purge({ ...config, css: [file] });
        const sizeBefore = fs.statSync(file).size;
        fs.writeFileSync(file, result.css);
        const sizeAfter = fs.statSync(file).size;

        before += sizeBefore;
        after += sizeAfter;
        const pct = Math.round((1 - sizeAfter / sizeBefore) * 100);
        console.log(`  ${rel}: ${Math.round(sizeBefore / 1024)} KB → ${Math.round(sizeAfter / 1024)} KB (−${pct}%)`);
    }

    console.log(`  gesamt: ${Math.round(before / 1024)} KB → ${Math.round(after / 1024)} KB`);

    // A purge that removes nothing means the config stopped matching — a broken
    // glob, a moved directory — and it must not pass quietly as "no savings".
    if (after >= before) {
        console.error('purge: nichts entfernt. Die content-Globs greifen nicht mehr.');
        process.exit(1);
    }
})();
