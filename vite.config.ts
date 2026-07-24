import { defineConfig } from 'vite';
import inject from '@rollup/plugin-inject';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const SRC = path.resolve(__dirname, 'frontend/src');

/**
 * Compile `.html` imports to an Underscore template function, replacing the
 * webpack `underscore-template-loader`. `import Tpl from './fooTpl.html'` then
 * yields a `(data) => htmlString` function, exactly as before.
 */
const TPL_SUFFIX = '?underscore-template';

function underscoreHtml() {
    return {
        name: 'underscore-html',
        // Run before Vite's own HTML handling.
        enforce: 'pre' as const,
        async resolveId(source: string, importer: string | undefined) {
            if (!source.endsWith('.html')) {
                return null;
            }
            const resolved = await (this as any).resolve(source, importer, { skipSelf: true });
            if (!resolved) {
                return null;
            }
            // Tag the id so it no longer ends in `.html`: Vite's built-in HTML
            // plugin (parse5) then leaves it to us and treats it as a JS module.
            return resolved.id + TPL_SUFFIX;
        },
        load(id: string) {
            if (!id.endsWith(TPL_SUFFIX)) {
                return null;
            }
            const file = id.slice(0, -TPL_SUFFIX.length);
            const html = readFileSync(file, 'utf8');
            // Ship the raw template and let Underscore compile it at runtime.
            // (Its compiled body uses `with(obj)`, which is illegal in a strict
            // ES module; Underscore builds it via `new Function`, which is not
            // strict, so runtime compilation sidesteps the problem entirely.)
            return `import _ from 'underscore';\nexport default _.template(${JSON.stringify(html)});`;
        },
    };
}

// One entry per build (ENTRY=app|exports); the npm script runs it twice.
// The bundle name (app/exports) maps to its source entry file.
const entryFiles: Record<string, string> = { app: 'index.js', exports: 'exports.js' };
const entry = process.env.ENTRY === 'exports' ? 'exports' : 'app';

export default defineConfig({
    plugins: [
        underscoreHtml(),
        // Replaces webpack's ProvidePlugin: inject $/jQuery where used without
        // import. Limited to real source; compiled templates look `$` up as a
        // runtime global instead.
        inject({ $: ['jquery', 'default'], jQuery: ['jquery', 'default'], include: ['**/*.ts', '**/*.js'] }),
    ],
    resolve: {
        // Single jQuery instance (nested plugins pin older copies otherwise).
        dedupe: ['jquery'],
        alias: [
            { find: 'jquery', replacement: path.resolve(__dirname, 'node_modules/jquery/dist/jquery.js') },
            // GitHub dep without a package.json main — resolve the subpath by hand.
            {
                find: 'jQuery-tinyTimer/jquery.tinytimer',
                replacement: path.resolve(__dirname, 'node_modules/jQuery-tinyTimer/jquery.tinytimer.js'),
            },
            // Bare specifier `import 'exports'` used by index.js.
            { find: /^exports$/, replacement: path.join(SRC, 'exports.js') },
            // Webpack `resolve.modules:[frontend/src]` — map the top-level dirs so
            // `models/app`, `modules/…`, `lib/…`, `collections/…` etc. resolve.
            {
                find: /^(app|collections|lib|locale|models|modules|templates|views)\//,
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
    // Keep moment small: pull only the locales Saito ships.
    define: {},
});
