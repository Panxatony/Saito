import { defineConfig } from 'vite';
import path from 'node:path';

const SRC = path.resolve(__dirname, 'frontend/src');

// One entry per build (ENTRY=htmx-threads|admin|boot); the npm script runs it once
// per entry. `htmx-threads` is the forum's frontend — the htmx/Alpine islands.
// `admin` is the administration backend's behaviour, built from the same Alpine
// rather than a second stack.
const entryFiles: Record<string, string> = {
    'htmx-threads': 'islands/htmxThreadList.ts',
    admin: 'admin.ts',
    // Loaded synchronously in <head>, before anything is painted: the theme
    // stylesheet choice and the font scale. Its own entry rather than part of the
    // island bundle because it has to run *first*, while the island bundle sits at
    // the end of <body> where it belongs.
    boot: 'islands/boot.ts',
};
const requested = process.env.ENTRY ?? 'htmx-threads';
const entry = entryFiles[requested] ? requested : 'htmx-threads';

export default defineConfig({
    plugins: [
    ],
    resolve: {
        // `islands/…` imports resolve against frontend/src rather than relative
        // paths. The only alias that is needed: everything else under
        // frontend/src is imported relatively.
        alias: [
            {
                find: /^(islands)\//,
                replacement: `${SRC}/$1/`,
            },
        ],
    },
    build: {
        outDir: path.resolve(__dirname, 'webroot/js'),
        // Vite would clear webroot/js, and `webroot/js/empty` is tracked — it is
        // what keeps the directory in the repository now that no built asset is
        // committed. (The comment here used to claim sibling SCSS, locale and
        // image assets; those went with the webpack build and are not there.)
        emptyOutDir: false,
        target: 'es2018',
        // Handles dependencies that mix ESM and CommonJS. Vite covers
        // node_modules by default, which is the only place this now applies:
        // the `frontend/src/lib` tree this used to name as well was the SPA's
        // jQuery plugins, and it went with the SPA.
        commonjsOptions: {
            transformMixedEsModules: true,
        },
        // A self-contained IIFE per entry → one file, no vendor/app chunk split,
        // so the "deploy app but not vendor" mismatch class cannot happen.
        lib: {
            entry: path.join(SRC, entryFiles[entry]),
            formats: ['iife'],
            name: 'SaitoBundle', // required for IIFE; the app assigns window.Application itself
            fileName: () => `${entry}.bundle.js`,
        },
        rollupOptions: {
            output: { extend: true },
        },
    },
});
