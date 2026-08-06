/**
 * Clear the built JS output before Vite rebuilds it.
 *
 * Replaces the `clean:release` grunt task (`./webroot/js/**\/!(empty)`): remove
 * everything under `webroot/js` except the `empty` placeholder that keeps the
 * otherwise-generated directory in the repository. Vite overwrites the three
 * bundles it emits, but a bundle from a renamed or removed entry would linger
 * without this.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const root = 'webroot/js';

function clear(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            clear(full);
            if (fs.readdirSync(full).length === 0) {
                fs.rmdirSync(full);
            }
        } else if (entry.name !== 'empty') {
            fs.unlinkSync(full);
        }
    }
}

if (fs.existsSync(root)) {
    clear(root);
}
