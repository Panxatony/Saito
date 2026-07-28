import { defineConfig } from 'vite';
import path from 'node:path';

const SRC = path.resolve(__dirname, 'frontend/src');

// One entry per build (ENTRY=htmx-threads|admin); the npm script runs it once
// per entry. `htmx-threads` is the forum's frontend — the htmx/Alpine islands.
// `admin` is the administration backend's behaviour, built from the same Alpine
// rather than a second stack.
const entryFiles: Record<string, string> = {
    'htmx-threads': 'islands/htmxThreadList.ts',
    admin: 'admin.ts',
};
const requested = process.env.ENTRY ?? 'htmx-threads';
const entry = entryFiles[requested] ? requested : 'htmx-threads';

export default defineConfig({
    plugins: [
    ],
    resolve: {
        // Single jQuery instance (nested plugins pin older copies otherwise).
        alias: [
            // GitHub dep without a package.json main — resolve the subpath by hand.
            // Bare specifier `import 'exports'` used by index.js.
            // Webpack `resolve.modules:[frontend/src]` — map the top-level dirs so
            // `models/app`, `modules/…`, `lib/…`, `collections/…` etc. resolve.
            {
                find: /^(islands)\//,
                replacement: `${SRC}/$1/`,
            },
        ],
    },
    build: {
        outDir: path.resolve(__dirname, 'webroot/js'),
        emptyOutDir: false, // keep the sibling SCSS/locale/image assets
        target: 'es2018',
        // The app pulls in legacy jQuery plugins under frontend/src/lib that use
        // CommonJS / global patterns; let the CJS interop process those too (Vite
        // only covers node_modules by default), and handle mixed ESM/CJS files.
        commonjsOptions: {
            include: [/node_modules/, /frontend[\\/]src[\\/]lib[\\/]/],
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
